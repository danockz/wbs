# Runbook — enabling group/ancestor ranking (Part B roll-up)

This is the deploy + backfill + enable procedure for the group-attribution and
ancestor roll-up feature (design doc `gamification_crud_audit_and_ranking_design.md`,
Part B / sequencing C-3…C-5). It is written to be **safe on a live 50k-concurrent
system**: every step is additive or rebuildable, and the ranking cache is a pure
function of the immutable `point_ledger`, so a mistake is always recoverable by a
rebuild.

## Preconditions
- Code deployed containing migration `2026-09-05-000045_RankingRollupAndGroupAttribution`,
  `RollupService`, the `PointsEngine` roll-up wiring, `LeaderboardService` boards,
  and the `gamification:rebuild-rollup` command.
- A maintenance window is **not** required — all steps are online-safe — but run
  the backfill during a low-traffic period so the batch `UPDATE`s contend less.

## Step 1 — Apply the schema (no behaviour change yet)
```
php spark migrate -g gamification
```
Adds the nullable attribution columns to `point_ledger`
(`group_id`, `category_code`, `project_code`, `phase`, `amount_minor`), the
`group_point_rollup` table, the widened idempotency key, and the
`rollup_awards` / `group_credit_mode` flags. Existing rows read as
org-level/untagged; nothing changes in behaviour because the new columns are
nullable/defaulted.

> The idempotency `UNIQUE` key becomes
> `(rule_id, subject_id, source_ref, entry_type, group_id)`. Because historical
> rows have `group_id = NULL` and MySQL treats each NULL as distinct in a UNIQUE
> index, this widening cannot introduce a collision on existing data.

## Step 2 — Verify the live engine is now attributing new awards
From this point every NEW final award/reversal already writes attribution and
rolls up automatically (`RewardCoordinator` passes the cause's group; other award
sites use membership fallback / org-level). Spot check:
```
SELECT group_id, category_code, project_code, phase, amount_minor
FROM point_ledger
WHERE organization_id = :org
ORDER BY created_at DESC
LIMIT 20;
```
Recent contribution rows should show a `group_id` (or NULL when the cause is
org-wide) and a populated `amount_minor` / `project_code`.

## Step 3 — Backfill historical rows AND build the cache
One command does both (backfill first, then rebuild) per org, or `--all`:
```
php spark gamification:rebuild-rollup --org=<uuid> --backfill
# or, for every organization:
php spark gamification:rebuild-rollup --all --backfill
```
- **Backfill** is best-effort + idempotent: it only fills columns that are still
  NULL/0, and only for rows whose source event still carries the data (today:
  contribution rows → cause group / cause project / amount). Re-running never
  overwrites a value the live engine has since written.
- **Rebuild** truncates `group_point_rollup` for the scope and recomputes it from
  every FINAL ledger row, applying the ancestor roll-up via `group_closure`.

The command prints, per org, how many rows were backfilled and how many final
entries were re-applied.

## Step 4 — Validate
```
-- Cache is internally consistent with the ledger for a spot group:
SELECT points FROM group_point_rollup
WHERE organization_id=:org AND season_id=:season
  AND group_id=:g AND category_code='*' AND project_code='*' AND phase='*';

-- ...should equal the subtree sum from the ledger:
SELECT COALESCE(SUM(pl.points),0) FROM point_ledger pl
JOIN group_closure gc ON gc.descendant_id = pl.group_id AND gc.ancestor_id = :g
WHERE pl.organization_id=:org AND pl.season_id=:season AND pl.state='final';
```
The two numbers must match. Then hit the boards:
```
GET gamification/leaderboards/groups?parent=<region-id>
GET gamification/leaderboards/individuals?within_group=<id>&include_subtree=1
GET gamification/leaderboards/by/project/<cause-id>
GET gamification/leaderboards/membership/department
```

## Step 5 — Enable ancestor award minting (optional, opt-in)
Ancestor **ranking** is on from Step 3. Ancestor **award minting** stays OFF until
a campaign opts in:
```
PATCH gamification/campaigns/<id>  { "rollup_awards": 1 }   # draft campaigns only
```
With `rollup_awards=1`, each group from the contributing member's team up to the
campaign owner mints its own single milestone award at `team_target_value`.

## Recovery
The cache can always be rebuilt from the ledger — if it is ever suspected wrong:
```
php spark gamification:rebuild-rollup --org=<uuid>        # rebuild only
php spark gamification:rebuild-rollup --org=<uuid> --backfill   # re-backfill + rebuild
```
No ledger data is mutated by a rebuild; only `group_point_rollup` is rewritten.
The backfill only ever fills NULL/0 attribution columns, never points.

## Appendix — signing/secret env vars (not rollup-specific)

Two secrets can affect an environment where you exercise the ranking engine
end-to-end (attendance check-ins that award points, and payment webhooks that
feed contribution attribution). They behave DIFFERENTLY — do not treat both as
"optional with a dev default":

| Env | If unset | Action |
|---|---|---|
| `CHECKIN_SIGNING_KEY` | **Optional.** QR check-in still works, but nonces are HMAC-signed with a dev default key that is **public in the source**. | Set a strong random value in production: `php -r 'echo bin2hex(random_bytes(32)).PHP_EOL;'` |
| `webhook.secret.<provider>` / `WEBHOOK_SECRET` | **No default; FAIL-CLOSED.** An empty secret is never valid, so **every inbound payment webhook is rejected** and no contribution ever posts (so nothing rolls up). | **Required** wherever real provider callbacks are accepted. Value = the exact secret configured on the provider (e.g. Stripe endpoint signing secret) — copy it from the provider, do not invent it. `webhook.secret.stripe` (dotted, per-provider) wins; `WEBHOOK_SECRET` is the global fallback. |

Format in `.env`: `key = value`; dotted keys go in literally
(`webhook.secret.stripe = whsec_…`); uncomment the line to activate it. See the
`.env.example` "Signing / secret keys" block and the root `RUNBOOK.md`.
