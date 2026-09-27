<?php

declare(strict_types=1);

namespace WBS\Shared\Navigation;

/**
 * The "best tier" of the dynamic menu: DERIVE, don't store.
 *
 * A capability word is a pure function of a subject's ROLE-SET, not of their
 * identity. Across a whole tenant there are only a handful of distinct roles, so
 * instead of caching one word per user (O(users)) we cache one word per ROLE
 * (O(roles) -- typically a few dozen bytes total) and DERIVE any user's word on
 * demand by OR-ing their roles' words:
 *
 *     word(user) = OR over r in roles(user) of roleWord[r]
 *
 * This collapses 500k users to ~O(distinct role-sets) distinct answers, and makes
 * adding a user cost ZERO server state -- their word is computed, never stored.
 *
 * The table is tiny, immutable, and versioned. It is built ONCE from role ->
 * permission data (at boot / on a role-permission change), broadcast to every node,
 * and can even be shipped to the edge/client so word derivation happens off the
 * app tier entirely.
 *
 * See docs/DYNAMIC-MENU-FLOOR.md.
 */
final class RoleWordTable
{
    /** @var array<string,int> role code => capability word (uint64) */
    private array $words;

    /** Version stamp: changes whenever ANY role's permission set changes. */
    private string $version;

    /** @param array<string,int> $words role code => word (already bit-packed) */
    public function __construct(array $words)
    {
        ksort($words); // deterministic order for a stable fingerprint
        $this->words   = $words;
        $fp            = '';
        foreach ($words as $code => $w) {
            $fp .= $code . '=' . $w . ';';
        }
        $this->version = substr(hash('xxh128', $fp), 0, 12);
    }

    /**
     * Build the table from role -> permission-CODE lists (as read from
     * role_permissions ⋈ permissions). Each role's word is the OR of its
     * permission bits via the frozen PermissionBits map.
     *
     * @param array<string,list<string>> $rolePermissions role code => [permission codes]
     */
    public static function fromRolePermissions(array $rolePermissions): self
    {
        $words = [];
        foreach ($rolePermissions as $role => $codes) {
            $w = 0;
            foreach ($codes as $code) {
                $bit = PermissionBits::bit($code);
                if ($bit !== null) {
                    $w |= (1 << $bit);
                }
            }
            $words[$role] = $w;
        }

        return new self($words);
    }

    /**
     * DERIVE a user's capability word from their role codes. Pure OR-fold: no DB,
     * no allocation beyond the int, Theta(roles-per-user) -- effectively O(1).
     * Unknown roles contribute 0 (fail-closed: an unrecognised role grants nothing).
     *
     * @param list<string> $roleCodes
     */
    public function deriveWord(array $roleCodes): int
    {
        $w = 0;
        foreach ($roleCodes as $r) {
            $w |= $this->words[$r] ?? 0;
        }

        // Constrain to known bits (defense in depth -- a corrupt table entry can
        // never satisfy the UNSATISFIABLE sentinel or a reserved bit).
        return $w & PermissionBits::allKnownMask();
    }

    /** Word for a single role code (0 if unknown). */
    public function wordFor(string $roleCode): int
    {
        return $this->words[$roleCode] ?? 0;
    }

    /** Version stamp -- compose into ETags/keys so a role-permission edit invalidates. */
    public function version(): string
    {
        return $this->version;
    }

    /** @return array<string,int> the full role => word table (for edge/client handoff). */
    public function toArray(): array
    {
        return $this->words;
    }

    /** Number of roles in the table. */
    public function count(): int
    {
        return count($this->words);
    }
}
