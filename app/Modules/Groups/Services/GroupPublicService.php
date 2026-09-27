<?php

declare(strict_types=1);

namespace WBS\Groups\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Identity\Security\PhoneNormalizer;
use WBS\Identity\Services\CredentialSetupService;
use WBS\Identity\Services\IdentityPolicyService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Read model for group-specific PUBLIC landing pages.
 *
 * A group is publicly viewable when its status = 'active' (single-org platform;
 * see the visibility decision in the RUNBOOK). This service returns ONLY the
 * fields that are safe to show anonymously — no member PII beyond the public
 * leader/sponsor display name and an aggregate member count, and only PUBLISHED,
 * upcoming events. Everything is read-only.
 */
final class GroupPublicService
{
    /** Bundled landing design templates; falls back to the first if unknown. */
    public const THEMES = ['aurora', 'sunrise', 'forest', 'slate'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly GroupMembershipService $memberships,
        private readonly ?CredentialSetupService $invites = null,
        // users-writer parity (onboarding field-sync): the public join carries
        // an OPTIONAL phone through the SAME normalization/region policy that
        // self-registration uses. Null (older test callers) → phone_input-only.
        private readonly ?PhoneNormalizer $phones = null,
        private readonly ?IdentityPolicyService $identityPolicies = null,
    ) {
    }

    /**
     * Public self-join for a group, by slug. Find-or-creates a lightweight user
     * from the submitted name/email, then delegates to GroupMembershipService so
     * ALL the usual invariants apply (single-active, conflict rules, events,
     * audit). The group leader (or a delegated admin) is recorded as sponsor.
     *
     * Approval: a group with join_policy = 'open' admits immediately; otherwise
     * the membership starts pending until a reviewer approves it.
     *
     * @param array{name?:string,email?:string,phone?:string} $data
     *
     * @return Result data = { membership_id, status, approval_state, sponsor }
     */
    public function selfJoin(string $organizationId, string $slug, array $data): Result
    {
        $group = $this->db->table('groups')
            ->where('organization_id', $organizationId)
            ->where('slug', $slug)
            ->where('status', 'active')
            ->get()->getRowArray();

        if ($group === null) {
            return Result::notFound('group.not_found', 'GROUP_NOT_FOUND');
        }
        if ((int) ($group['public_join'] ?? 0) !== 1) {
            return Result::fail('JOIN_CLOSED', 'group.public_join_closed', 403);
        }

        $email = isset($data['email']) ? strtolower(trim((string) $data['email'])) : '';
        $name  = trim((string) ($data['name'] ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Result::fail('EMAIL_REQUIRED', 'group.join_email_required', 422);
        }
        if ($name === '') {
            return Result::fail('NAME_REQUIRED', 'group.join_name_required', 422);
        }

        // Optional phone — validated with the SAME normalizer/policy as
        // registration (field-sync): a bad number fails closed with a
        // human-readable key; an empty one is simply absent.
        $phoneInput  = trim((string) ($data['phone'] ?? ''));
        $phone       = null;
        $phoneRegion = null;
        if ($phoneInput !== '' && $this->phones !== null) {
            $policy      = $this->identityPolicies?->resolve($organizationId, null);
            $phoneRegion = is_array($policy) ? ($policy['phone_default_region'] ?? null) : null;
            $phone       = $this->phones->normalize($phoneInput, $phoneRegion);
            if ($phone === null) {
                return Result::fail('INVALID_PHONE', 'group.join_invalid_phone', 422);
            }
        }

        [$userId, $isNewUser] = $this->findOrCreateUser($organizationId, $email, $name, $phoneInput !== '' ? $phoneInput : null, $phone, $phoneRegion);
        $groupId = (string) $group['id'];
        $sponsorId = $this->resolveSponsor($organizationId, $group);

        // 'open' groups admit immediately; everything else needs approval.
        $requiresApproval = (string) ($group['join_policy'] ?? 'approval') !== 'open';

        $result = $this->memberships->add($organizationId, $groupId, [
            'user_id'           => $userId,
            'membership_type'   => 'member',
            'role'              => 'member',
            'source'            => 'self_join',
            'requires_approval' => $requiresApproval,
            'actor_id'          => $userId,
            'added_by'          => $sponsorId,          // the sponsor stands as referrer/added_by
        ]);

        if (! $result->ok) {
            return $result;
        }

        // Record the sponsor link (leader/delegated admin) when known.
        if ($sponsorId !== null && $sponsorId !== $userId) {
            $this->recordSponsor($organizationId, $userId, $sponsorId);
        }

        $payload = is_array($result->data) ? $result->data : [];
        $payload['sponsor'] = $sponsorId;
        $payload['group_slug'] = $slug;

        // A newly-created member has no password yet — issue a single-use invite
        // so they can set one and sign in. The plaintext token is returned to the
        // caller (to email / display); delivery is the controller's concern. For
        // an EXISTING account we never mint an invite (they already sign in).
        $payload['is_new_user'] = $isNewUser;
        $payload['invite_token'] = null;
        if ($isNewUser && $this->invites !== null) {
            $invite = $this->invites->issue($organizationId, $userId, 'invite', $sponsorId);
            if ($invite->ok) {
                $payload['invite_token'] = $invite->data['token'] ?? null;
            }
        }

        return Result::ok($payload, $result->status);
    }

    /**
     * Find an existing user by email in this org, or create a minimal one.
     *
     * @return array{0:string,1:bool} [user_id, wasCreated]
     */
    private function findOrCreateUser(
        string $organizationId,
        string $email,
        string $name,
        ?string $phoneInput = null,
        ?string $phone = null,
        ?string $phoneRegion = null,
    ): array {
        $existing = $this->db->table('users')
            ->select('id')
            ->where('organization_id', $organizationId)
            ->where('email', $email)
            ->get()->getRowArray();
        if ($existing !== null) {
            return [(string) $existing['id'], false];
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();

        // users-writer parity (field-sync): write the SAME columns AccountService
        // ::register writes — org-default locale/timezone (not column defaults),
        // the phone trio when supplied, status evidence + transition row.
        $org = $this->db->table('organizations')
            ->where('id', $organizationId)->get()->getRowArray();
        $orgLocale = is_array($org) ? (string) ($org['default_locale'] ?? 'en') : 'en';
        $orgTz     = is_array($org) ? (string) ($org['timezone'] ?? 'UTC') : 'UTC';

        // No password yet: created in a passwordless 'pending_verification' state
        // until the invite is accepted (set-password activates the account).
        $this->db->table('users')->insert([
            'id'                => $id,
            'organization_id'   => $organizationId,
            'email'             => $email,
            'email_verified'    => 0,
            'phone'             => $phone,
            'phone_verified'    => 0,
            'phone_input'       => $phoneInput,
            'phone_region'      => $phoneRegion,
            'password_hash'     => null,
            'display_name'      => $name,
            'status'            => 'pending_verification',
            'status_reason'     => 'group_join',
            'status_changed_at' => $now,
            'locale'            => $orgLocale,
            'timezone'          => $orgTz,
            'mfa_enabled'       => 0,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        // Append-only transition evidence — same contract as register().
        $this->db->table('account_state_transitions')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $organizationId,
            'user_id'         => $id,
            'from_status'     => null,
            'to_status'       => 'pending_verification',
            'reason'          => 'group_join',
            'actor_id'        => null,
            'approval_ref'    => null,
            'evidence'        => null,
            'created_at'      => $this->clock->nowUtcMicro(),
        ]);

        return [$id, true];
    }

    /** The group's leader, else the earliest active leader/admin membership. */
    private function resolveSponsor(string $organizationId, array $group): ?string
    {
        if (! empty($group['leader_user_id'])) {
            return (string) $group['leader_user_id'];
        }

        $row = $this->db->table('group_members')
            ->select('user_id')
            ->where('organization_id', $organizationId)
            ->where('group_id', $group['id'])
            ->where('status', 'active')
            ->whereIn('role', ['leader', 'admin', 'coordinator'])
            ->orderBy('joined_at', 'ASC')
            ->get()->getRowArray();

        return $row !== null ? (string) $row['user_id'] : null;
    }

    /**
     * Maintain a single active sponsor per member in the referrals graph.
     * No-op if an active sponsor already exists (does not override an existing
     * relationship).
     */
    private function recordSponsor(string $organizationId, string $memberId, string $sponsorId): void
    {
        if (! $this->db->tableExists('sponsorships')) {
            return;
        }
        $has = $this->db->table('sponsorships')
            ->where('organization_id', $organizationId)
            ->where('member_id', $memberId)
            ->where('active', 1)
            ->countAllResults();
        if ($has > 0) {
            return;
        }

        $now = $this->clock->nowUtcString();
        $this->db->table('sponsorships')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $organizationId,
            'member_id'       => $memberId,
            'sponsor_id'      => $sponsorId,
            'active'          => 1,
            'effective_from'  => $now,
            'reason'          => 'group_self_join',
            'created_at'      => $now,
        ]);
    }

    /** Groups with no physical venue/country land here (virtual). Sinks last. */
    public const UNLOCATED_KEY = '__virtual__';
    public const VIRTUAL_KEY   = '__virtual__';

    /**
     * Public directory rows for /g and /g/map. Presentation from the group;
     * place from the primary venue + world-geo reference (region → subregion →
     * country → state → city → town/village). No venue / no country → Virtual.
     *
     * @return list<array<string,mixed>>
     */
    public function directory(string $organizationId): array
    {
        return $this->db->table('groups g')
            ->select(
                'g.slug, g.name, g.tagline, g.type, g.hero_theme, g.cover_image_url, '
                . 'g.location_text, g.contact_email, g.contact_phone, '
                . 'g.primary_venue_id, '
                . 'v.latitude, v.longitude, '
                . 'v.country_id AS geo_country_id, v.state_id AS geo_state_id, '
                . 'v.city_id AS geo_city_id, v.town_village_id AS geo_town_id, '
                . 'v.country_label, v.state_label, v.city_label, '
                . 'v.name AS venue_name, v.venue_type AS venue_type, v.capacity AS venue_capacity, '
                . 'va.line1 AS venue_address, '
                . 'co.region_id AS geo_region_id, co.subregion_id AS geo_subregion_id, '
                . 'rg.name AS region_label, sr.name AS subregion_label, '
                . 'tv.name AS town_label',
                false,
            )
            ->join('venues v', 'v.id = g.primary_venue_id AND v.deleted_at IS NULL', 'left')
            ->join('addresses va', 'va.id = v.address_id', 'left')
            ->join('countries co', 'co.id = v.country_id', 'left')
            ->join('subregions sr', 'sr.id = co.subregion_id', 'left')
            ->join('regions rg', 'rg.id = co.region_id', 'left')
            ->join('towns_villages tv', 'tv.id = v.town_village_id', 'left')
            ->where('g.organization_id', $organizationId)
            ->where('g.status', 'active')
            ->orderBy('g.name', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * /g — full place chain (same tree as the map).
     *
     * @return list<array<string,mixed>>
     */
    public function directoryByLocation(string $organizationId): array
    {
        return $this->nestByPlaceChain($this->directory($organizationId));
    }

    /**
     * /g/map — Region ▸ Subregion ▸ Country ▸ State ▸ City ▸ Town ▸ Venue ▸ groups.
     *
     * @return list<array<string,mixed>>
     */
    public function geoDirectory(string $organizationId): array
    {
        return $this->nestByPlaceChain($this->directory($organizationId));
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function nestByGeo(array $rows): array
    {
        return $this->nestByPlaceChain($rows);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function nestByGeoVenue(array $rows): array
    {
        return $this->nestByPlaceChain($rows);
    }

    /**
     * Physical groups (primary venue + country) nest Region → … → Venue.
     * Missing intermediate levels collapse. No venue or no country → Virtual.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function nestByPlaceChain(array $rows): array
    {
        $roots = [];
        foreach ($rows as $g) {
            $n = $this->normalizePlaceRow($g);
            $venueId = trim((string) ($n['primary_venue_id'] ?? ''));
            $countryId = $n['geo_country_id'] ?? null;
            $physical = $venueId !== '' && $countryId !== null && $countryId !== '';

            if (! $physical) {
                if (! isset($roots[self::VIRTUAL_KEY])) {
                    $roots[self::VIRTUAL_KEY] = [
                        'key' => 'virtual', 'label' => '', 'level' => 'virtual',
                        'count' => 0, 'children' => [], 'groups' => [], '_order' => PHP_INT_MAX,
                    ];
                }
                $roots[self::VIRTUAL_KEY]['groups'][] = $this->directoryGroupCard($n);
                $roots[self::VIRTUAL_KEY]['count']++;
                continue;
            }

            $steps = [];
            foreach ([
                ['region', $n['geo_region_id'] ?? null, $n['region_label'] ?? ''],
                ['subregion', $n['geo_subregion_id'] ?? null, $n['subregion_label'] ?? ''],
                ['country', $n['geo_country_id'] ?? null, $n['country_label'] ?? ''],
                ['state', $n['geo_state_id'] ?? null, $n['state_label'] ?? ''],
                ['city', $n['geo_city_id'] ?? null, $n['city_label'] ?? ''],
                ['town', $n['geo_town_id'] ?? null, $n['town_label'] ?? ''],
            ] as [$level, $id, $label]) {
                $label = trim((string) $label);
                if ($id === null || $id === '' || $label === '') {
                    continue;
                }
                $steps[] = ['level' => $level, 'id' => (string) $id, 'label' => $label];
            }
            $steps[] = [
                'level' => 'venue',
                'id'    => $venueId,
                'label' => trim((string) ($n['venue_name'] ?? '')),
                'venue_type' => $n['venue_type'] ?? null,
                'venue_capacity' => isset($n['venue_capacity']) ? (int) $n['venue_capacity'] : null,
                'venue_address' => $n['venue_address'] ?? null,
            ];

            $cursor = &$roots;
            $path   = [];
            foreach ($steps as $step) {
                $k = $step['level'] . ':' . $step['id'];
                if (! isset($cursor[$k])) {
                    $node = [
                        'key' => $step['level'] . '-' . $step['id'],
                        'label' => $step['label'],
                        'level' => $step['level'],
                        'count' => 0,
                        'children' => [],
                        'groups' => [],
                        '_order' => 0,
                    ];
                    if ($step['level'] === 'venue') {
                        $node['venue_type'] = $step['venue_type'] ?? null;
                        $node['venue_capacity'] = $step['venue_capacity'] ?? null;
                        $node['venue_address'] = $step['venue_address'] ?? null;
                    }
                    $cursor[$k] = $node;
                }
                $path[] = $k;
                $cursor = &$cursor[$k]['children'];
            }
            unset($cursor);

            $walk = &$roots;
            foreach ($path as $i => $k) {
                $walk[$k]['count']++;
                if ($i === count($path) - 1) {
                    $walk[$k]['groups'][] = $this->directoryGroupCard($n);
                } else {
                    $walk = &$walk[$k]['children'];
                }
            }
            unset($walk);
        }

        return $this->flattenPlaceTree($roots);
    }

    /**
     * Accept both the joined directory columns and the older nestByGeo /
     * nestByGeoVenue fixtures (country_id, country_name, region_label-as-state).
     *
     * @param array<string,mixed> $g
     * @return array<string,mixed>
     */
    private function normalizePlaceRow(array $g): array
    {
        $hasContinent = ($g['geo_region_id'] ?? $g['region_id'] ?? null) !== null
            && (string) ($g['geo_region_id'] ?? $g['region_id'] ?? '') !== '';
        $stateLabel = trim((string) ($g['state_label'] ?? $g['state_name'] ?? ''));
        if ($stateLabel === '' && ! $hasContinent) {
            $stateLabel = trim((string) ($g['region_label'] ?? ''));
        }
        $g['geo_region_id']     = $g['geo_region_id'] ?? $g['region_id'] ?? null;
        $g['region_label']      = $hasContinent ? trim((string) ($g['region_label'] ?? $g['region_name'] ?? '')) : '';
        $g['geo_subregion_id']  = $g['geo_subregion_id'] ?? $g['subregion_id'] ?? null;
        $g['subregion_label']   = trim((string) ($g['subregion_label'] ?? $g['subregion_name'] ?? ''));
        $g['geo_country_id']    = $g['geo_country_id'] ?? $g['country_id'] ?? null;
        $g['country_label']     = trim((string) ($g['country_label'] ?? $g['country_name'] ?? ''));
        $g['geo_state_id']      = $g['geo_state_id'] ?? $g['state_id'] ?? null;
        $g['state_label']       = $stateLabel;
        $g['geo_city_id']       = $g['geo_city_id'] ?? $g['city_id'] ?? null;
        $g['city_label']        = trim((string) ($g['city_label'] ?? $g['city_name'] ?? ''));
        $g['geo_town_id']       = $g['geo_town_id'] ?? $g['town_village_id'] ?? null;
        $g['town_label']        = trim((string) ($g['town_label'] ?? $g['town_name'] ?? ''));

        return $g;
    }

    /**
     * @param array<string,array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    private function flattenPlaceTree(array $nodes): array
    {
        $list = array_values($nodes);
        usort($list, static function (array $a, array $b): int {
            $oa = (int) ($a['_order'] ?? 0);
            $ob = (int) ($b['_order'] ?? 0);
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }

            return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
        });
        foreach ($list as &$n) {
            $kids = $n['children'] ?? [];
            $n['children'] = is_array($kids) && $kids !== [] ? $this->flattenPlaceTree($kids) : [];
            unset($n['_order']);
        }
        unset($n);

        return $list;
    }

    /**
     * Compact public card for a group in the geo directory.
     *
     * @param array<string,mixed> $g
     * @return array<string,mixed>
     */
    private function directoryGroupCard(array $g): array
    {
        return [
            'slug'          => (string) ($g['slug'] ?? ''),
            'name'          => (string) ($g['name'] ?? ''),
            'tagline'       => $g['tagline'] ?? null,
            'type'          => $g['type'] ?? null,
            'hero_theme'    => $g['hero_theme'] ?? null,
            'location_text' => $g['location_text'] ?? null,
            'contact_email' => $g['contact_email'] ?? null,
            'contact_phone' => $g['contact_phone'] ?? null,
            'cover_image_url' => $g['cover_image_url'] ?? null,
        ];
    }

    /**
     * Full public page payload for one group, resolved by slug.
     *
     * @return Result data = { group, leader, member_count, upcoming_events, causes, ancestors }
     */
    public function page(string $organizationId, string $slug): Result
    {
        $group = $this->db->table('groups')
            ->where('organization_id', $organizationId)
            ->where('slug', $slug)
            ->where('status', 'active')
            ->get()->getRowArray();

        if ($group === null) {
            return Result::notFound('group.not_found', 'GROUP_NOT_FOUND');
        }

        $groupId = (string) $group['id'];

        // Leader / delegated sponsor for the join CTA (public display name only).
        $leader = null;
        if (! empty($group['leader_user_id'])) {
            $leader = $this->db->table('users')
                ->select('id, display_name')
                ->where('id', $group['leader_user_id'])
                ->get()->getRowArray();
        }
        // Fallback sponsor: a delegated leader/admin membership, else any admin.
        if ($leader === null) {
            $leader = $this->db->table('group_members gm')
                ->select('u.id, u.display_name', false)
                ->join('users u', 'u.id = gm.user_id', 'left')
                ->where('gm.organization_id', $organizationId)
                ->where('gm.group_id', $groupId)
                ->where('gm.status', 'active')
                ->whereIn('gm.role', ['leader', 'admin', 'coordinator'])
                ->orderBy('gm.joined_at', 'ASC')
                ->get()->getRowArray();
        }

        $memberCount = $this->db->table('group_members')
            ->where('organization_id', $organizationId)
            ->where('group_id', $groupId)
            ->where('status', 'active')
            ->countAllResults();

        // Published, upcoming events for this group (soonest first).
        $nowUtc = $this->clock->nowUtcString();
        $events = $this->db->table('events e')
            ->select('e.id, e.title, e.slug, e.description, e.mode, e.starts_at, e.ends_at, e.timezone, e.registration_policy, v.name AS venue_name', false)
            ->join('venues v', 'v.id = e.venue_id', 'left')
            ->where('e.organization_id', $organizationId)
            ->where('e.group_id', $groupId)
            ->where('e.status', 'published')
            ->where('e.starts_at >=', $nowUtc)
            ->orderBy('e.starts_at', 'ASC')
            ->limit(6)
            ->get()->getResultArray();

        // Active, PUBLIC causes for this group, if the module is present. Causes
        // carry their own visibility (public|group|private) — only 'public' ones
        // may appear on an anonymous page.
        $causes = [];
        if ($this->db->tableExists('causes')) {
            $causes = $this->db->table('causes')
                ->select('id, name, purpose, currency, target_minor, target_count, show_target, ends_at')
                ->where('organization_id', $organizationId)
                ->where('group_id', $groupId)
                ->where('status', 'active')
                ->where('visibility', 'public')
                ->orderBy('created_at', 'DESC')
                ->limit(4)
                ->get()->getResultArray();
            foreach ($causes as $i => $c) {
                if ((int) ($c['show_target'] ?? 1) !== 1) {
                    $causes[$i]['target_minor'] = null;
                    $causes[$i]['target_count'] = null;
                }
                unset($causes[$i]['show_target']);
            }
        }

        // Breadcrumb ancestors (org > parent > this) for context.
        $ancestors = $this->ancestorTrail($organizationId, $group);

        return Result::ok([
            'group'           => $this->publicGroup($group),
            'leader'          => $leader !== null
                ? ['id' => (string) $leader['id'], 'display_name' => (string) ($leader['display_name'] ?? 'Group leader')]
                : null,
            'member_count'    => $memberCount,
            'upcoming_events' => $events,
            'causes'          => $causes,
            'ancestors'       => $ancestors,
        ]);
    }

    /** Strip the group row down to public-safe fields with a valid theme. */
    private function publicGroup(array $group): array
    {
        $theme = (string) ($group['hero_theme'] ?? 'aurora');
        if (! in_array($theme, self::THEMES, true)) {
            $theme = self::THEMES[0];
        }

        return [
            'id'              => (string) $group['id'],
            'slug'            => (string) $group['slug'],
            'name'            => (string) $group['name'],
            'tagline'         => $group['tagline'] ?? null,
            'type'            => $group['type'] ?? null,
            'description'     => $group['description'] ?? null,
            'location_text'   => $group['location_text'] ?? null,
            'contact_email'   => $group['contact_email'] ?? null,
            'contact_phone'   => $group['contact_phone'] ?? null,
            'website_url'     => $group['website_url'] ?? null,
            'announcement'    => $group['announcement'] ?? null,
            'hero_theme'      => $theme,
            'cover_image_url' => $group['cover_image_url'] ?? null,
            'public_join'     => (int) ($group['public_join'] ?? 1) === 1,
        ];
    }

    /**
     * Resolve ancestor names from the materialized `path` (ids separated by '/').
     *
     * @return list<array{slug:string,name:string}>
     */
    private function ancestorTrail(string $organizationId, array $group): array
    {
        $path = trim((string) ($group['path'] ?? ''), '/');
        if ($path === '') {
            return [];
        }
        $ids = array_values(array_filter(explode('/', $path), static fn ($v) => $v !== '' && $v !== $group['id']));
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->table('groups')
            ->select('id, slug, name')
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->where('status', 'active')
            ->get()->getResultArray();

        // Preserve path order.
        $byId = [];
        foreach ($rows as $r) {
            $byId[(string) $r['id']] = ['slug' => (string) $r['slug'], 'name' => (string) $r['name']];
        }
        $trail = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $trail[] = $byId[$id];
            }
        }

        return $trail;
    }
}
