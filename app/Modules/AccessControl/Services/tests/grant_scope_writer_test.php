<?php

declare(strict_types=1);

/**
 * GrantScopeWriter unit test — locks down the SHARED full-scope-model writer that
 * every grant writer (role assignments, rules, delegations, access requests,
 * break-glass) now depends on, so the scope contract is expressed identically
 * everywhere and matches what the PDP reads back (SRS FR-ACL-003).
 *
 * Covers:
 *   - parse() for all four modes: self, self_and_descendants, descendants_only,
 *     groups — plus the legacy include_descendants boolean mapping;
 *   - validation failures: groups mode with no set; descendants modes with no
 *     anchor group; and the (allowed) org-wide self with a null anchor;
 *   - include_crosscut opt-in (default OFF);
 *   - columns() shape incl. backward-compatible include_descendants derivation;
 *   - syncGroupSet()/groupSet() round-trip, and that switching AWAY from groups
 *     mode clears the hand-picked set.
 *
 * Uses a tiny in-memory fake of the CI4 query builder (no DB, no framework).
 *
 *   php app/Modules/AccessControl/Services/tests/grant_scope_writer_test.php
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
    class QB
    {
        /** @var array<string,mixed> */
        private array $eq = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($s)
        {
            return $this;
        }

        public function where($k, $v = null)
        {
            $this->eq[(string) $k] = $v;

            return $this;
        }

        public function insert(array $row): bool
        {
            $this->db->rows[$this->t][] = $row;

            return true;
        }

        public function delete(): bool
        {
            $this->db->rows[$this->t] = array_values(array_filter(
                $this->db->rows[$this->t] ?? [],
                fn ($r) => ! $this->matches($r),
            ));

            return true;
        }

        public function get(): RS
        {
            $rows = array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));

            return new RS($rows);
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

    class RS
    {
        public function __construct(private array $r)
        {
        }

        public function getResultArray(): array
        {
            return $this->r;
        }

        public function getRowArray(): ?array
        {
            return $this->r[0] ?? null;
        }
    }
}

namespace {
    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/ScopeMode.php';
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/AccessControl/Services/GrantScopeWriter.php';

    use WBS\AccessControl\Services\GrantScopeWriter;
    use WBS\Shared\Support\Clock;
    use WBS\Shared\Support\ScopeMode;

    $pass = 0;
    $fail = 0;
    function chk(string $label, bool $ok, string $detail = ''): void
    {
        global $pass, $fail;
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    }

    $db     = new \CodeIgniter\Database\BaseConnection();
    $clock  = new Clock();
    $writer = new GrantScopeWriter($db, $clock);

    echo "parse() — modes\n";

    // self (default) with no anchor is allowed (org-wide historical shape)
    $s = $writer->parse([]);
    chk('empty -> self, ok', $s['ok'] && $s['mode'] === ScopeMode::SELF);
    chk('self anchor null allowed', $s['group_id'] === null);
    chk('self does not include descendants', $s['include_descendants'] === false);
    chk('include_crosscut default OFF', $s['include_crosscut'] === false);

    // self_and_descendants with an anchor
    $s = $writer->parse(['scope_mode' => 'self_and_descendants', 'scope_group_id' => 'g1']);
    chk('self_and_descendants ok', $s['ok'] && $s['mode'] === ScopeMode::SELF_AND_DESCENDANTS);
    chk('self_and_descendants includes descendants', $s['include_descendants'] === true);
    chk('self_and_descendants anchor kept', $s['group_id'] === 'g1');

    // descendants_only with an anchor (excludes the group itself)
    $s = $writer->parse(['scope_mode' => 'descendants_only', 'scope_group_id' => 'g1']);
    chk('descendants_only ok', $s['ok'] && $s['mode'] === ScopeMode::DESCENDANTS_ONLY);
    chk('descendants_only includes descendants', $s['include_descendants'] === true);
    chk('descendants_only excludes self', ScopeMode::includesSelf($s['mode']) === false);

    // legacy include_descendants boolean maps to a mode when no mode given
    $s = $writer->parse(['include_descendants' => 1, 'scope_group_id' => 'g9']);
    chk('legacy include_descendants=1 -> self_and_descendants', $s['mode'] === ScopeMode::SELF_AND_DESCENDANTS);
    $s = $writer->parse(['include_descendants' => 0]);
    chk('legacy include_descendants=0 -> self', $s['mode'] === ScopeMode::SELF);

    // groups mode with a hand-picked (deduped) set
    $s = $writer->parse(['scope_mode' => 'groups', 'scope_groups' => ['a', 'b', 'a', 'c']]);
    chk('groups ok', $s['ok'] && $s['mode'] === ScopeMode::GROUPS);
    chk('groups deduped', $s['groups'] === ['a', 'b', 'c'], json_encode($s['groups']));
    chk('groups mode ignores descendants flag', $s['include_descendants'] === false);

    // include_crosscut opt-in
    $s = $writer->parse(['scope_mode' => 'self_and_descendants', 'scope_group_id' => 'g1', 'include_crosscut' => true]);
    chk('include_crosscut opt-in honored', $s['include_crosscut'] === true);

    echo "parse() — validation failures\n";

    $s = $writer->parse(['scope_mode' => 'groups', 'scope_groups' => []]);
    chk('groups with empty set fails', $s['ok'] === false && $s['message'] === 'acl.scope_groups_required');

    $s = $writer->parse(['scope_mode' => 'groups']); // missing scope_groups entirely
    chk('groups with no set key fails', $s['ok'] === false && $s['message'] === 'acl.scope_groups_required');

    $s = $writer->parse(['scope_mode' => 'descendants_only']); // no anchor
    chk('descendants_only without anchor fails', $s['ok'] === false && $s['message'] === 'acl.scope_group_required');

    $s = $writer->parse(['scope_mode' => 'self_and_descendants']); // no anchor
    chk('self_and_descendants without anchor fails', $s['ok'] === false && $s['message'] === 'acl.scope_group_required');

    // unknown mode string normalizes to self (safe default)
    $s = $writer->parse(['scope_mode' => 'wobbly']);
    chk('unknown mode -> self', $s['ok'] && $s['mode'] === ScopeMode::SELF);

    echo "columns()\n";
    $scope = $writer->parse(['scope_mode' => 'descendants_only', 'scope_group_id' => 'g1', 'include_crosscut' => true]);
    $cols  = $writer->columns($scope);
    chk('columns scope_group_id', $cols['scope_group_id'] === 'g1');
    chk('columns scope_mode', $cols['scope_mode'] === ScopeMode::DESCENDANTS_ONLY);
    chk('columns include_descendants derived (1)', $cols['include_descendants'] === 1);
    chk('columns include_crosscut (1)', $cols['include_crosscut'] === 1);
    $cols2 = $writer->columns($writer->parse(['scope_mode' => 'self', 'scope_group_id' => 'g2']));
    chk('columns include_descendants derived (0) for self', $cols2['include_descendants'] === 0);

    echo "syncGroupSet()/groupSet() round-trip\n";
    $g = $writer->parse(['scope_mode' => 'groups', 'scope_groups' => ['x', 'y', 'z']]);
    $writer->syncGroupSet('org1', 'delegation', 'D1', $g);
    $set = $writer->groupSet('delegation', 'D1');
    sort($set);
    chk('groups persisted + read back', $set === ['x', 'y', 'z'], json_encode($set));
    chk('rows carry org + grant identity', ($db->rows['grant_scope_groups'][0]['organization_id'] ?? null) === 'org1'
        && ($db->rows['grant_scope_groups'][0]['grant_type'] ?? null) === 'delegation'
        && ($db->rows['grant_scope_groups'][0]['grant_id'] ?? null) === 'D1');

    // a second grant's set is isolated
    $writer->syncGroupSet('org1', 'break_glass', 'B1', $writer->parse(['scope_mode' => 'groups', 'scope_groups' => ['q']]));
    chk('other grant isolated', $writer->groupSet('break_glass', 'B1') === ['q']);
    chk('first grant unaffected', count($writer->groupSet('delegation', 'D1')) === 3);

    // re-sync D1 with a narrower set REPLACES (not appends)
    $writer->syncGroupSet('org1', 'delegation', 'D1', $writer->parse(['scope_mode' => 'groups', 'scope_groups' => ['x']]));
    chk('re-sync replaces set', $writer->groupSet('delegation', 'D1') === ['x']);

    // switching AWAY from groups mode clears the hand-picked set
    $writer->syncGroupSet('org1', 'delegation', 'D1', $writer->parse(['scope_mode' => 'self_and_descendants', 'scope_group_id' => 'g1']));
    chk('non-groups mode clears set', $writer->groupSet('delegation', 'D1') === []);

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}
