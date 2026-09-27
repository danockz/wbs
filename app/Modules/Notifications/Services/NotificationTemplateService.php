<?php

declare(strict_types=1);

namespace WBS\Notifications\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * CRUD + send-time resolution for `notification_templates`.
 *
 * Variant rows: a template is identified by
 *   (org, group_id|null, key_name, channel, locale, audience_kind, audience_role).
 * `audience_kind` is '' (base), 'platform' (users.role / role_assignments code) or
 * 'membership' (group_members.role). Saving an edit inserts the next version;
 * retire flips status — never a hard delete.
 *
 * Resolution (identity first, then locale):
 *   group+role → group base → org+role → org base
 *   then recipient locale → org default locale → en.
 */
final class NotificationTemplateService
{
    public const KIND_BASE        = '';
    public const KIND_PLATFORM    = 'platform';
    public const KIND_MEMBERSHIP  = 'membership';
    public const CHANNELS         = ['email', 'sms', 'inapp', 'push', 'in_app'];
    public const LOCALES          = ['en', 'fr', 'es', 'pt', 'zh', 'ar'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly TemplateRenderer $renderer,
    ) {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listFor(string $organizationId, ?string $groupId = null, ?string $keyName = null): array
    {
        $q = $this->db->table('notification_templates')
            ->where('organization_id', $organizationId);
        if ($groupId !== null && $groupId !== '') {
            $q->where('group_id', $groupId);
        }
        if ($keyName !== null && $keyName !== '') {
            $q->where('key_name', $keyName);
        }

        return $q->orderBy('key_name', 'ASC')
            ->orderBy('channel', 'ASC')
            ->orderBy('locale', 'ASC')
            ->orderBy('version', 'DESC')
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function find(string $organizationId, string $templateId): ?array
    {
        $row = $this->db->table('notification_templates')->where('id', $templateId)->get()->getRowArray();
        if ($row === null || (string) ($row['organization_id'] ?? '') !== $organizationId) {
            return null;
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(string $organizationId, array $data): Result
    {
        $parsed = $this->parse($data, null);
        if ($parsed instanceof Result) {
            return $parsed;
        }
        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        $this->db->table('notification_templates')->insert($parsed + [
            'id'              => $id,
            'organization_id' => $organizationId,
            'version'         => 1,
            'status'          => 'active',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return Result::created(['template_id' => $id, 'version' => 1]);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function revise(string $organizationId, string $templateId, array $data): Result
    {
        $current = $this->find($organizationId, $templateId);
        if ($current === null) {
            return Result::notFound('notification.template_not_found', 'TEMPLATE_NOT_FOUND');
        }
        if ((string) ($current['status'] ?? '') === 'retired') {
            return Result::fail('BAD_STATE', 'notification.template_retired', 409);
        }
        $parsed = $this->parse($data, $current);
        if ($parsed instanceof Result) {
            return $parsed;
        }
        $next = ((int) ($current['version'] ?? 1)) + 1;
        $id   = Uuid::v7();
        $now  = $this->clock->nowUtcString();
        $this->db->table('notification_templates')->insert($parsed + [
            'id'              => $id,
            'organization_id' => $organizationId,
            'version'         => $next,
            'status'          => 'active',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return Result::created(['template_id' => $id, 'version' => $next, 'supersedes' => $templateId]);
    }

    public function retire(string $organizationId, string $templateId): Result
    {
        $current = $this->find($organizationId, $templateId);
        if ($current === null) {
            return Result::notFound('notification.template_not_found', 'TEMPLATE_NOT_FOUND');
        }
        if ((string) ($current['status'] ?? '') === 'retired') {
            return Result::ok(['template_id' => $templateId, 'status' => 'retired', 'deduplicated' => true]);
        }
        $this->db->table('notification_templates')->where('id', $templateId)->update([
            'status'     => 'retired',
            'updated_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['template_id' => $templateId, 'status' => 'retired']);
    }

    /**
     * Pick the body the system should send. Returns null when nothing matches
     * (caller keeps current fail-closed "no body" behaviour).
     *
     * @param array<string,mixed> $opts group_id, locale, roles.platform, roles.membership
     * @return array{id:string,version:int,subject:?string,body:string,locale:string}|null
     */
    public function resolveForSend(
        string $organizationId,
        string $keyName,
        string $channel,
        array $opts = [],
    ): ?array {
        if ($organizationId === '' || $keyName === '') {
            return null;
        }
        $groupId = trim((string) ($opts['group_id'] ?? ''));
        $groupId = $groupId !== '' ? $groupId : null;
        $locales = $this->localeChain($organizationId, (string) ($opts['locale'] ?? ''));
        $roles   = $this->roleCandidates($opts);

        $identities = [];
        if ($groupId !== null) {
            foreach ($roles as $role) {
                $identities[] = [$groupId, $role[0], $role[1]];
            }
            $identities[] = [$groupId, self::KIND_BASE, ''];
        }
        foreach ($roles as $role) {
            $identities[] = [null, $role[0], $role[1]];
        }
        $identities[] = [null, self::KIND_BASE, ''];

        foreach ($identities as [$gid, $kind, $role]) {
            foreach ($locales as $locale) {
                $row = $this->activeRow($organizationId, $gid, $keyName, $channel, $locale, $kind, $role);
                if ($row !== null) {
                    return [
                        'id'      => (string) $row['id'],
                        'version' => (int) ($row['version'] ?? 1),
                        'subject' => $row['subject'] ?? null,
                        'body'    => (string) ($row['body'] ?? ''),
                        'locale'  => (string) ($row['locale'] ?? $locale),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Render a stored body against a send context. Supports both the seeded
     * `:name` convention and the allowlisted `{{namespace.field}}` tokens.
     *
     * @param array<string,mixed> $context
     */
    public function renderBody(string $body, array $context, bool $escape): string
    {
        $flat = $this->flattenContext($context);
        $out  = $this->renderer->render($body, $flat, $escape);
        $out  = (string) preg_replace_callback(
            '/:([a-z][a-z0-9_]*)/i',
            static function (array $m) use ($flat, $escape): string {
                $key = strtolower($m[1]);
                if (! array_key_exists($key, $flat)) {
                    return $m[0];
                }
                $value = (string) $flat[$key];

                return $escape ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
            },
            $out,
        );

        return $out;
    }

    /**
     * Recipient locale + membership/platform roles used at send time.
     *
     * @return array{locale:string,membership_role:?string,platform_roles:list<string>}
     */
    public function recipientProfile(string $organizationId, string $userId, ?string $groupId): array
    {
        $out = ['locale' => '', 'membership_role' => null, 'platform_roles' => []];
        if ($userId === '') {
            return $out;
        }
        $user = $this->db->table('users')->select('locale')->where('id', $userId)->get()->getRowArray();
        $out['locale'] = strtolower(trim((string) ($user['locale'] ?? '')));
        if ($groupId !== null && $groupId !== '') {
            $m = $this->db->table('group_members')
                ->select('role')
                ->where('user_id', $userId)
                ->where('group_id', $groupId)
                ->where('status', 'active')
                ->get()->getRowArray();
            $role = strtolower(trim((string) ($m['role'] ?? '')));
            $out['membership_role'] = $role !== '' ? $role : null;
        }
        $assignments = $this->db->table('role_assignments')
            ->select('role_id')
            ->where('user_id', $userId)
            ->get()->getResultArray();
        $roleIds = [];
        foreach ($assignments as $a) {
            $rid = (string) ($a['role_id'] ?? '');
            if ($rid !== '') {
                $roleIds[] = $rid;
            }
        }
        if ($roleIds !== []) {
            foreach ($roleIds as $rid) {
                $r = $this->db->table('roles')->select('code')->where('id', $rid)->get()->getRowArray();
                $code = strtolower(trim((string) ($r['code'] ?? '')));
                if ($code !== '') {
                    $out['platform_roles'][] = $code;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed>|null $current
     * @return array<string,mixed>|Result
     */
    private function parse(array $data, ?array $current): array|Result
    {
        $key = strtolower(trim((string) ($data['key_name'] ?? $current['key_name'] ?? '')));
        $body = (string) ($data['body'] ?? $current['body'] ?? '');
        if ($key === '' || ! preg_match('/^[a-z][a-z0-9_]{1,78}$/', $key) || trim($body) === '') {
            return Result::fail('MISSING_FIELDS', 'notification.template_missing_fields', 422);
        }
        $channel = strtolower(trim((string) ($data['channel'] ?? $current['channel'] ?? 'email')));
        if (! in_array($channel, self::CHANNELS, true)) {
            return Result::fail('CHANNEL_INVALID', 'notification.template_channel_invalid', 422);
        }
        $locale = strtolower(trim((string) ($data['locale'] ?? $current['locale'] ?? 'en')));
        if (! in_array($locale, self::LOCALES, true)) {
            return Result::fail('LOCALE_INVALID', 'notification.template_locale_invalid', 422);
        }
        $kind = strtolower(trim((string) ($data['audience_kind'] ?? $current['audience_kind'] ?? '')));
        if ($kind === 'base') {
            $kind = self::KIND_BASE;
        }
        if (! in_array($kind, [self::KIND_BASE, self::KIND_PLATFORM, self::KIND_MEMBERSHIP], true)) {
            return Result::fail('AUDIENCE_INVALID', 'notification.template_audience_invalid', 422);
        }
        $role = strtolower(trim((string) ($data['audience_role'] ?? $current['audience_role'] ?? '')));
        if ($kind === self::KIND_BASE) {
            $role = '';
        } elseif ($role === '' || preg_match('/^[a-z][a-z0-9_.-]{0,38}$/', $role) !== 1) {
            return Result::fail('AUDIENCE_INVALID', 'notification.template_audience_invalid', 422);
        }
        $groupId = array_key_exists('group_id', $data)
            ? trim((string) $data['group_id'])
            : (string) ($current['group_id'] ?? '');
        $subject = $data['subject'] ?? $current['subject'] ?? null;
        $subject = $subject !== null ? trim((string) $subject) : null;
        $category = trim((string) ($data['category'] ?? $current['category'] ?? 'general'));
        if ($category === '') {
            $category = 'general';
        }

        return [
            'group_id'      => $groupId !== '' ? $groupId : null,
            'key_name'      => $key,
            'channel'       => $channel,
            'locale'        => $locale,
            'audience_kind' => $kind,
            'audience_role' => $role,
            'category'      => $category,
            'subject'       => $subject !== '' ? $subject : null,
            'body'          => $body,
        ];
    }

    /** @return list<string> */
    private function localeChain(string $organizationId, string $userLocale): array
    {
        $org = $this->db->table('organizations')
            ->select('default_locale')
            ->where('id', $organizationId)
            ->get()->getRowArray();
        $orgLoc = strtolower(trim((string) ($org['default_locale'] ?? 'en')));
        $userLocale = strtolower(trim($userLocale));
        $chain = [];
        foreach ([$userLocale, $orgLoc, 'en'] as $loc) {
            if ($loc !== '' && ! in_array($loc, $chain, true)) {
                $chain[] = $loc;
            }
        }

        return $chain !== [] ? $chain : ['en'];
    }

    /**
     * @param array<string,mixed> $opts
     * @return list<array{0:string,1:string}>
     */
    private function roleCandidates(array $opts): array
    {
        $out = [];
        $seen = [];
        $mem = strtolower(trim((string) ($opts['membership_role'] ?? '')));
        if ($mem !== '') {
            $out[] = [self::KIND_MEMBERSHIP, $mem];
            $seen['membership:' . $mem] = true;
        }
        $platform = $opts['platform_roles'] ?? [];
        if (is_string($platform)) {
            $platform = [$platform];
        }
        if (is_array($platform)) {
            foreach ($platform as $p) {
                $code = strtolower(trim((string) $p));
                $k = 'platform:' . $code;
                if ($code !== '' && ! isset($seen[$k])) {
                    $out[] = [self::KIND_PLATFORM, $code];
                    $seen[$k] = true;
                }
            }
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function activeRow(
        string $organizationId,
        ?string $groupId,
        string $keyName,
        string $channel,
        string $locale,
        string $kind,
        string $role,
    ): ?array {
        $q = $this->db->table('notification_templates')
            ->where('organization_id', $organizationId)
            ->where('key_name', $keyName)
            ->where('channel', $channel)
            ->where('locale', $locale)
            ->where('audience_kind', $kind)
            ->where('audience_role', $role)
            ->where('status', 'active');
        if ($groupId === null || $groupId === '') {
            $q->where('group_id', null);
        } else {
            $q->where('group_id', $groupId);
        }

        return $q->orderBy('version', 'DESC')->get()->getRowArray() ?: null;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,string>
     */
    private function flattenContext(array $context): array
    {
        $flat = [];
        foreach ($context as $k => $v) {
            if (is_array($v)) {
                continue;
            }
            $flat[strtolower((string) $k)] = (string) $v;
        }
        $aliases = [
            'name'        => $flat['name'] ?? $flat['member.preferred_name'] ?? $flat['member.first_name'] ?? '',
            'event_title' => $flat['event_title'] ?? $flat['title'] ?? $flat['event.title'] ?? '',
            'title'       => $flat['title'] ?? $flat['event_title'] ?? $flat['event.title'] ?? '',
            'starts_at'   => $flat['starts_at'] ?? $flat['event.start_local'] ?? '',
            'location'    => $flat['location'] ?? $flat['event.venue'] ?? $flat['venue'] ?? '',
            'timezone'    => $flat['timezone'] ?? '',
        ];
        foreach ($aliases as $k => $v) {
            if ($v !== '' && ! isset($flat[$k])) {
                $flat[$k] = $v;
            }
        }

        return $flat;
    }
}
