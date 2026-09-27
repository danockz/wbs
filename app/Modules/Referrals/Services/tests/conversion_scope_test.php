<?php

declare(strict_types=1);

/**
 * Referral conversion scope test (Phase 0: gap R2).
 *
 * attributeConversion previously flipped EVERY still-`captured` prospect of a
 * referrer to `converted` in one unscoped UPDATE. This test proves it now flips
 * ONLY the prospect who actually converted (matched by linked_user_id, or an
 * explicit prospect_id / email_hash opt) and leaves the referrer's other captured
 * prospects untouched.
 *
 *   php app/Modules/Referrals/Services/tests/conversion_scope_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
        }
    }
}

namespace Fake {
    class RS
    {
        public function __construct(private array $rows)
        {
        }

        public function getRowArray()
        {
            return $this->rows[0] ?? null;
        }

        public function getResultArray()
        {
            return $this->rows;
        }
    }

    class QB
    {
        private array $eq = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function where($k, $v = null)
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function get(): RS
        {
            return new RS($this->matching());
        }

        public function countAllResults(): int
        {
            return count($this->matching());
        }

        public function insert(array $row): bool
        {
            // Simulate the UNIQUE(converted_user_id, conversion_type, source_ref)
            // key on referral_attributions so a replay throws, driving dedup.
            if ($this->t === 'referral_attributions') {
                foreach (($this->db->rows[$this->t] ?? []) as $existing) {
                    if (($existing['converted_user_id'] ?? null) === ($row['converted_user_id'] ?? null)
                        && ($existing['conversion_type'] ?? null) === ($row['conversion_type'] ?? null)
                        && ($existing['source_ref'] ?? null) === ($row['source_ref'] ?? null)) {
                        throw new \RuntimeException('duplicate attribution');
                    }
                }
            }
            $this->db->rows[$this->t][] = $row;

            return true;
        }

        public function update(array $set): bool
        {
            foreach (($this->db->rows[$this->t] ?? []) as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->t][$i] = array_merge($r, $set);
                }
            }

            return true;
        }

        private function matching(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if (($r[$k] ?? null) !== $v) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Referrals\Services\ReferralService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Referrals/Services/JourneySignalPort.php';
    require_once $root . '/app/Modules/Referrals/Services/ReferralService.php';

    // In-memory JourneySignalPort spy (M4 entry-signal emission).
    $journeySpy = new class implements WBS\Referrals\Services\JourneySignalPort {
        /** @var list<array<string,mixed>> */
        public array $calls = [];
        public bool $throw = false;

        public function ingest(string $organizationId, array $data): WBS\Shared\Support\Result
        {
            $this->calls[] = ['org' => $organizationId] + $data;
            if ($this->throw) {
                throw new \RuntimeException('journey boom');
            }

            return WBS\Shared\Support\Result::ok(['matched' => 1]);
        }
    };

    $passed = 0;
    $failed = 0;
    function chk(string $label, bool $cond): void
    {
        global $passed, $failed;
        if ($cond) {
            $passed++;
        } else {
            $failed++;
            echo "  FAIL {$label}\n";
        }
    }

    $ORG = 'org-1';
    $clock = new Clock();

    $mkDb = static function () use ($ORG): BaseConnection {
        $db = new BaseConnection();
        $db->rows['referral_attributions'] = [];
        // Referrer r-1 captured THREE prospects; only p-2 (linked to u-2) converts.
        $db->rows['prospects'] = [
            ['id' => 'p-1', 'organization_id' => $ORG, 'referrer_id' => 'r-1', 'state' => 'captured', 'linked_user_id' => 'u-1', 'email_hash' => 'h1', 'link_id' => 'l1'],
            ['id' => 'p-2', 'organization_id' => $ORG, 'referrer_id' => 'r-1', 'state' => 'captured', 'linked_user_id' => 'u-2', 'email_hash' => 'h2', 'link_id' => 'l2'],
            ['id' => 'p-3', 'organization_id' => $ORG, 'referrer_id' => 'r-1', 'state' => 'captured', 'linked_user_id' => 'u-3', 'email_hash' => 'h3', 'link_id' => 'l3'],
        ];

        return $db;
    };

    $stateOf = static function (BaseConnection $db, string $pid): string {
        foreach ($db->rows['prospects'] as $r) {
            if ($r['id'] === $pid) {
                return (string) $r['state'];
            }
        }

        return '';
    };

    // ---- default: match by linked_user_id ----------------------------------
    $db = $mkDb();
    $svc = new ReferralService($db, $clock);
    $r = $svc->attributeConversion($ORG, 'r-1', 'u-2', 'join_group', 'src-1');
    chk('attribution created', $r->ok);
    chk('R2 only the converting prospect flips', $stateOf($db, 'p-2') === 'converted');
    chk('R2 other prospect p-1 untouched', $stateOf($db, 'p-1') === 'captured');
    chk('R2 other prospect p-3 untouched', $stateOf($db, 'p-3') === 'captured');

    // ---- explicit prospect_id opt ------------------------------------------
    $db = $mkDb();
    $svc = new ReferralService($db, $clock);
    $svc->attributeConversion($ORG, 'r-1', 'u-x', 'signup', 'src-2', ['prospect_id' => 'p-3']);
    chk('R2 prospect_id opt flips only that row', $stateOf($db, 'p-3') === 'converted');
    chk('R2 prospect_id opt leaves p-1 captured', $stateOf($db, 'p-1') === 'captured');
    chk('R2 prospect_id opt leaves p-2 captured', $stateOf($db, 'p-2') === 'captured');

    // ---- email_hash opt ----------------------------------------------------
    $db = $mkDb();
    $svc = new ReferralService($db, $clock);
    $svc->attributeConversion($ORG, 'r-1', 'u-y', 'signup', 'src-3', ['email_hash' => 'h1']);
    chk('R2 email_hash opt flips only matching', $stateOf($db, 'p-1') === 'converted');
    chk('R2 email_hash opt leaves p-2 captured', $stateOf($db, 'p-2') === 'captured');

    // ---- no identifier match: convert NOTHING (not everything) --------------
    $db = $mkDb();
    $svc = new ReferralService($db, $clock);
    $svc->attributeConversion($ORG, 'r-1', 'u-unknown', 'signup', 'src-4');
    chk('R2 unmatched conversion flips nothing (p-1)', $stateOf($db, 'p-1') === 'captured');
    chk('R2 unmatched conversion flips nothing (p-2)', $stateOf($db, 'p-2') === 'captured');
    chk('R2 unmatched conversion flips nothing (p-3)', $stateOf($db, 'p-3') === 'captured');

    // ======================= M4 — conversion opens a journey ================
    // A successful conversion emits `journey.signal.member.converted` for the
    // CONVERTED user so an entry rule opens their journey.
    $db = $mkDb();
    $journeySpy->calls = [];
    $svc = new ReferralService($db, $clock, 'wbs-ip-salt', null, $journeySpy);
    $r = $svc->attributeConversion($ORG, 'r-1', 'u-2', 'join_group', 'src-1', ['actor_id' => 'staff-1']);
    chk('M4 attribution ok', $r->ok);
    chk('M4 one journey signal emitted', count($journeySpy->calls) === 1);
    $c = $journeySpy->calls[0] ?? [];
    chk('M4 action = member.converted', ($c['action'] ?? '') === 'journey.signal.member.converted');
    chk('M4 signal targets the CONVERTED user', ($c['user_id'] ?? '') === 'u-2');
    chk('M4 journey context org-wide (group_id null)', array_key_exists('group_id', $c) && $c['group_id'] === null);
    chk('M4 evidence type = referral', ($c['evidence_type'] ?? '') === 'referral');
    chk('M4 evidence ref points at the attribution', str_starts_with((string) ($c['evidence_ref'] ?? ''), 'attribution:'));
    chk('M4 attrs carry referrer + conversion_type', ($c['attributes']['referrer_id'] ?? '') === 'r-1'
        && ($c['attributes']['conversion_type'] ?? '') === 'join_group');
    chk('M4 actor threaded', ($c['actor_id'] ?? '') === 'staff-1');

    // Dedup replay (same converted_user + type + source) does NOT re-emit.
    $journeySpy->calls = [];
    $dup = $svc->attributeConversion($ORG, 'r-1', 'u-2', 'join_group', 'src-1');
    chk('M4 duplicate conversion is deduped', ($dup->meta['deduplicated'] ?? false) === true);
    chk('M4 duplicate emits NO journey signal', count($journeySpy->calls) === 0);

    // Self-referral is rejected before any emit.
    $journeySpy->calls = [];
    $self = $svc->attributeConversion($ORG, 'u-9', 'u-9', 'signup', 'src-self');
    chk('M4 self-referral rejected', ! $self->ok);
    chk('M4 self-referral emits nothing', count($journeySpy->calls) === 0);

    // Fault isolation: a throwing journey port never breaks the attribution.
    $db = $mkDb();
    $journeySpy->calls = [];
    $journeySpy->throw = true;
    $svc = new ReferralService($db, $clock, 'wbs-ip-salt', null, $journeySpy);
    $r = $svc->attributeConversion($ORG, 'r-1', 'u-2', 'join_group', 'src-1');
    chk('M4 throwing port still records attribution', $r->ok);
    chk('M4 throwing port still flips the prospect', $stateOf($db, 'p-2') === 'converted');
    chk('M4 throwing port emit was attempted', count($journeySpy->calls) === 1);
    $journeySpy->throw = false;

    // No port wired: attribution still works (signal optional).
    $db = $mkDb();
    $svc = new ReferralService($db, $clock);
    $r = $svc->attributeConversion($ORG, 'r-1', 'u-2', 'join_group', 'src-1');
    chk('M4 no port: attribution still ok', $r->ok && $stateOf($db, 'p-2') === 'converted');

    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
