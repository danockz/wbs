<?= $this->extend('layouts/app') ?>

<?php
/**
 * Campaign ad hoc TEAM management console
 * (GET /gamification/campaigns/{id}/teams/manage) — the browser face of
 * CampaignsController::manageCampaignTeams. Previously ad hoc teams and their
 * rosters could only be managed via the JSON API (define/rename/delete team,
 * add/remove member); the only browser view was the read-only data-page team
 * list. This turns the page into a full management console.
 *
 * All writes are no-JS PRG forms posting to webcsrf-guarded aliases:
 *   - create team → POST campaigns/{id}/teams
 *   - rename team → POST campaigns/{id}/teams/{teamId}/update
 *   - delete team → POST campaigns/{id}/teams/{teamId}/delete   (draft only)
 *   - add member  → POST campaigns/{id}/teams/{teamId}/members
 *   - remove mbr  → POST campaigns/{id}/members/{userId}/remove
 * The CSRF field is `_csrf`. Each destructive action sits behind a <details>
 * disclosure (CSP-safe confirm — no inline on* handlers, no <script>).
 *
 * Lifecycle rules are enforced authoritatively in CampaignService; the console
 * only mirrors them: ad hoc teams exist only when team_challenge + team_mode
 * =adhoc; a team is deletable only while the campaign is a DRAFT; a user belongs
 * to at most one team per campaign. When the campaign is not ad hoc, or is
 * completed/cancelled, the write controls are hidden with an explanatory note.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.campaignTeams.*') with English fallback.
 *
 * @var string                    $campaignId
 * @var array<string,mixed>       $campaign  the campaign row (name, status, team_*)
 * @var string                    $status    campaign status
 * @var bool                      $isAdhoc   whether ad hoc teams apply
 * @var list<array<string,mixed>> $teams     teams + resolved 'members' rosters
 * @var list<array<string,mixed>> $roster    org members for the add-member picker
 * @var string                    $csrf
 */
$campaignId = (string) ($campaignId ?? '');
$campaign   = is_array($campaign ?? null) ? $campaign : [];
$status     = (string) ($status ?? 'draft');
$isAdhoc    = (bool) ($isAdhoc ?? false);
$teams      = is_array($teams ?? null) ? $teams : [];
$roster     = is_array($roster ?? null) ? $roster : [];
$csrf       = $csrf ?? '';
$count      = count($teams);
$isDraft    = $status === 'draft';
$isEditable = ! in_array($status, ['completed', 'cancelled'], true);
$ce         = rawurlencode($campaignId);

$tf = static fn (string $k): string => 'Gamification.campaignTeams.' . $k;

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';
?>

<?= $this->section('content') ?>
    

    <p><a href="/gamification/campaigns/<?= esc($ce, 'attr') ?>" style="color:#fcd34d">&larr; <?= esc(lang(($tf)('backToCampaign'))) ?></a></p>
    <h1><?= esc(lang(($tf)('title'))) ?></h1>
    <div class="sub">
        <?= esc((string) ($campaign['name'] ?? $campaignId)) ?>
        · <span class="ct-tag"><?= esc(lang(($tf)('statusLabel'))) ?>: <?= esc($status) ?></span>
        · <?= esc(str_replace('{0}', (string) $count, $count === 1 ? lang(($tf)('countOne')) : lang(($tf)('count')))) ?>
    </div>

    <?php if ($flashOk !== ''): ?><div class="ct-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="ct-flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <?php if (! $isAdhoc): ?>
        <div class="ct-note"><?= esc(lang(($tf)('notAdhoc'))) ?></div>
    <?php else: ?>
        <?php if (! $isEditable): ?>
            <div class="ct-note"><?= esc(lang(($tf)('frozen'))) ?></div>
        <?php endif; ?>

        <?php if ($isEditable): ?>
        <details class="ct-panel">
            <summary>+ <?= esc(lang(($tf)('form.newTeam'))) ?></summary>
            <form method="post" action="/gamification/campaigns/<?= esc($ce, 'attr') ?>/teams" class="ct-form">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <div class="fld">
                    <label><?= esc(lang(($tf)('form.codeLabel'))) ?></label>
                    <input name="code" required maxlength="80" placeholder="<?= esc(lang(($tf)('form.codePh')), 'attr') ?>">
                </div>
                <div class="fld">
                    <label><?= esc(lang(($tf)('form.nameLabel'))) ?></label>
                    <input name="name" required maxlength="200">
                </div>
                <div class="fld">
                    <label><?= esc(lang(($tf)('form.iconLabel'))) ?></label>
                    <input name="icon" placeholder="<?= esc(lang(($tf)('form.iconPh')), 'attr') ?>">
                </div>
                <div class="fld">
                    <label><?= esc(lang(($tf)('form.colorLabel'))) ?></label>
                    <input name="color" placeholder="<?= esc(lang(($tf)('form.colorPh')), 'attr') ?>">
                </div>
                <div class="fld">
                    <button type="submit" class="ct-btn"><?= esc(lang(($tf)('form.saveNew'))) ?></button>
                </div>
            </form>
        </details>
        <?php endif; ?>

        <?php if ($teams === []): ?>
            <div class="ct-empty"><?= esc(lang(($tf)('empty'))) ?></div>
        <?php else: ?>
            <?php foreach ($teams as $t): ?>
                <?php
                $tid     = (string) ($t['id'] ?? '');
                $te      = rawurlencode($tid);
                $members = is_array($t['members'] ?? null) ? $t['members'] : [];
                $mcount  = (int) ($t['member_count'] ?? count($members));
                // Members already on ANY team can't be added again (one team per user).
                $onTeam  = [];
                foreach ($teams as $tt) {
                    foreach ((is_array($tt['members'] ?? null) ? $tt['members'] : []) as $mm) {
                        $onTeam[(string) ($mm['user_id'] ?? '')] = true;
                    }
                }
                ?>
                <div class="ct-team">
                    <div class="row">
                        <span class="name" style="<?= ! empty($t['color']) ? 'color:' . esc($t['color'], 'attr') . ';' : '' ?>">
                            <?php if (! empty($t['icon'])): ?><?= esc((string) $t['icon']) ?> <?php endif; ?>
                            <?= esc((string) ($t['name'] ?? ($t['code'] ?? '—'))) ?>
                        </span>
                        <span class="ct-tag"><?= esc((string) ($t['code'] ?? '')) ?></span>
                        <span class="ct-tag"><?= esc(str_replace('{0}', (string) $mcount, lang(($tf)('memberCount')))) ?></span>
                    </div>

                    <?php if ($members === []): ?>
                        <div class="ct-note" style="margin:10px 0 0"><?= esc(lang(($tf)('noMembers'))) ?></div>
                    <?php else: ?>
                        <ul class="ct-members">
                            <?php foreach ($members as $m): ?>
                                <?php $uid = (string) ($m['user_id'] ?? ''); $ue = rawurlencode($uid); ?>
                                <li>
                                    <span class="who"><?= esc((string) ($m['display_name'] ?? '') !== '' ? (string) $m['display_name'] : $uid) ?></span>
                                    <span class="uid"><?= esc($uid) ?></span>
                                    <?php if ($isEditable): ?>
                                    <form method="post" action="/gamification/campaigns/<?= esc($ce, 'attr') ?>/members/<?= esc($ue, 'attr') ?>/remove">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <button type="submit" class="ct-act warn"><?= esc(lang(($tf)('form.removeMember'))) ?></button>
                                    </form>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($isEditable): ?>
                    <div class="ct-acts">
                        <details class="ct-inline" style="border:0;margin:0">
                            <summary class="ct-act"><?= esc(lang(($tf)('form.addMember'))) ?></summary>
                            <form method="post" action="/gamification/campaigns/<?= esc($ce, 'attr') ?>/teams/<?= esc($te, 'attr') ?>/members" class="ct-addrow">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <div class="fld">
                                    <label><?= esc(lang(($tf)('form.memberLabel'))) ?></label>
                                    <select name="user_id" required>
                                        <option value=""><?= esc(lang(($tf)('form.memberNone'))) ?></option>
                                        <?php foreach ($roster as $u): ?>
                                            <?php
                                            $ruid = (string) ($u['id'] ?? '');
                                            if ($ruid === '' || isset($onTeam[$ruid])) {
                                                continue;
                                            }
                                            $rname = (string) ($u['display_name'] ?? '');
                                            ?>
                                            <option value="<?= esc($ruid, 'attr') ?>"><?= esc($rname !== '' ? $rname : $ruid) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="ct-btn"><?= esc(lang(($tf)('form.addMember'))) ?></button>
                            </form>
                        </details>

                        <details class="ct-inline" style="border:0;margin:0">
                            <summary class="ct-act"><?= esc(lang(($tf)('form.edit'))) ?></summary>
                            <form method="post" action="/gamification/campaigns/<?= esc($ce, 'attr') ?>/teams/<?= esc($te, 'attr') ?>/update" class="ct-form">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <div class="fld">
                                    <label><?= esc(lang(($tf)('form.nameLabel'))) ?></label>
                                    <input name="name" maxlength="200" value="<?= esc((string) ($t['name'] ?? ''), 'attr') ?>">
                                </div>
                                <div class="fld">
                                    <label><?= esc(lang(($tf)('form.iconLabel'))) ?></label>
                                    <input name="icon" value="<?= esc((string) ($t['icon'] ?? ''), 'attr') ?>">
                                </div>
                                <div class="fld">
                                    <label><?= esc(lang(($tf)('form.colorLabel'))) ?></label>
                                    <input name="color" value="<?= esc((string) ($t['color'] ?? ''), 'attr') ?>">
                                </div>
                                <div class="fld">
                                    <button type="submit" class="ct-btn"><?= esc(lang(($tf)('form.saveEdit'))) ?></button>
                                </div>
                            </form>
                        </details>

                        <?php if ($isDraft): ?>
                        <details class="ct-inline" style="border:0;margin:0">
                            <summary class="ct-act warn"><?= esc(lang(($tf)('form.deleteTeam'))) ?></summary>
                            <form method="post" action="/gamification/campaigns/<?= esc($ce, 'attr') ?>/teams/<?= esc($te, 'attr') ?>/delete" style="padding:8px 11px">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <span style="color:#94a3b8;font-size:.72rem"><?= esc(lang(($tf)('form.deleteConfirm'))) ?></span>
                                <button type="submit" class="ct-act warn"><?= esc(lang(($tf)('form.deleteYes'))) ?></button>
                            </form>
                        </details>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
