<?php

declare(strict_types=1);

namespace WBS\Streaming\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Contributions\Services\CauseService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Co-hosts + versioned, sanitized overlays (SRS FR-STR-008).
 *
 * Overlays are configuration DATA of a fixed reviewed type vocabulary — never
 * user code. Text fields are sanitized (tags stripped, control chars removed,
 * length-capped) at write time. Every edit creates a NEW version so historical
 * composited output stays reproducible. Cause-progress overlays derive their
 * amounts live from the ledger via CauseService — a presenter cannot inject a
 * fake raised/target figure.
 *
 * Co-host join tokens are short-lived; only the hash is stored and the plaintext
 * is returned exactly once (same discipline as meeting join tokens).
 */
final class OverlayService
{
    private const OVERLAY_TYPES = ['lower_third', 'cause_progress', 'quote_card'];
    private const COHOST_ROLES  = ['host', 'cohost', 'guest'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly CauseService $causes,
    ) {
    }

    // -- co-hosts -----------------------------------------------------------

    public function inviteCohost(string $streamId, string $userId, string $role = 'cohost'): Result
    {
        if (! in_array($role, self::COHOST_ROLES, true)) {
            return Result::fail('BAD_ROLE', 'overlay.bad_role', 422);
        }
        $stream = $this->db->table('streams')->where('id', $streamId)->get()->getRowArray();
        if ($stream === null) {
            return Result::notFound('stream.not_found', 'STREAM_NOT_FOUND');
        }

        $now      = $this->clock->nowUtcMicro();
        $existing = $this->db->table('stream_cohosts')
            ->where('stream_id', $streamId)->where('user_id', $userId)->get()->getRowArray();
        if ($existing !== null) {
            return Result::ok(['cohost_id' => $existing['id'], 'status' => $existing['status']], 200, ['deduplicated' => true]);
        }

        $id = Uuid::v7();
        $this->db->table('stream_cohosts')->insert([
            'id'         => $id,
            'stream_id'  => $streamId,
            'user_id'    => $userId,
            'role'       => $role,
            'status'     => 'invited',
            'invited_at' => $now,
        ]);

        return Result::created(['cohost_id' => $id, 'role' => $role, 'status' => 'invited']);
    }

    /**
     * Issue a short-lived WebRTC source-feed join token for an invited co-host.
     * Returns the plaintext token ONCE; only the hash is stored.
     */
    public function issueCohostToken(string $streamId, string $userId, int $ttlSeconds = 3600): Result
    {
        $cohost = $this->db->table('stream_cohosts')
            ->where('stream_id', $streamId)->where('user_id', $userId)->get()->getRowArray();
        if ($cohost === null) {
            return Result::denied('overlay.not_a_cohost', 'NOT_A_COHOST');
        }
        if ($cohost['status'] === 'removed') {
            return Result::denied('overlay.cohost_removed', 'COHOST_REMOVED');
        }

        $token   = bin2hex(random_bytes(32));
        $expires = $this->clock->now()->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s.u');
        $this->db->table('stream_cohosts')->where('id', $cohost['id'])->update([
            'join_token_hash'  => hash('sha256', $token),
            'token_expires_at' => $expires,
        ]);

        return Result::created([
            'stream_id'  => $streamId,
            'user_id'    => $userId,
            'join_token' => $token,      // returned once
            'expires_at' => $expires,
            'role'       => $cohost['role'],
        ]);
    }

    public function markJoined(string $streamId, string $userId, string $token): Result
    {
        $cohost = $this->db->table('stream_cohosts')
            ->where('stream_id', $streamId)->where('user_id', $userId)->get()->getRowArray();
        if ($cohost === null || $cohost['join_token_hash'] === null) {
            return Result::denied('overlay.no_token', 'NO_TOKEN');
        }
        if ($cohost['token_expires_at'] !== null && $cohost['token_expires_at'] < $this->clock->nowUtcMicro()) {
            return Result::denied('overlay.token_expired', 'TOKEN_EXPIRED');
        }
        if (! hash_equals($cohost['join_token_hash'], hash('sha256', $token))) {
            return Result::denied('overlay.token_invalid', 'TOKEN_INVALID');
        }

        $this->db->table('stream_cohosts')->where('id', $cohost['id'])->update([
            'status'    => 'joined',
            'joined_at' => $this->clock->nowUtcMicro(),
        ]);

        return Result::ok(['status' => 'joined', 'role' => $cohost['role']]);
    }

    public function removeCohost(string $streamId, string $userId): Result
    {
        $this->db->table('stream_cohosts')
            ->where('stream_id', $streamId)->where('user_id', $userId)
            ->update(['status' => 'removed', 'join_token_hash' => null, 'token_expires_at' => null]);

        return Result::ok(['stream_id' => $streamId, 'user_id' => $userId, 'status' => 'removed']);
    }

    /**
     * Management-console read for the moderator UI (SRS FR-STR-008). Unlike
     * {@see activeOverlays()} (which the relay compositor consumes and which
     * returns only visible overlays), this returns the FULL editing state:
     *
     *  - every co-host with role/status and token lifecycle *facts* — but NEVER
     *    the token hash or plaintext (secret-at-rest discipline). We report only
     *    whether a live token exists and when it expires.
     *  - every overlay including hidden ones, with version and a short config
     *    preview. cause_progress overlays show LIVE ledger figures so the console
     *    matches exactly what the compositor would render — no stale stored total.
     *
     * @return array<string,mixed>|null null when the stream does not exist
     */
    public function console(string $streamId): ?array
    {
        $stream = $this->db->table('streams')->where('id', $streamId)->get()->getRowArray();
        if ($stream === null) {
            return null;
        }

        $now       = $this->clock->nowUtcMicro();
        $cohostRows = $this->db->table('stream_cohosts')
            ->where('stream_id', $streamId)->orderBy('invited_at')->get()->getResultArray();

        $cohosts = [];
        foreach ($cohostRows as $r) {
            $hasToken = $r['join_token_hash'] !== null;
            $expired  = $hasToken && $r['token_expires_at'] !== null && $r['token_expires_at'] < $now;
            $cohosts[] = [
                'cohost_id'    => $r['id'],
                'user_id'      => $r['user_id'],
                'role'         => $r['role'],
                'status'       => $r['status'],
                // Token facts only — never the hash/plaintext.
                'token_active' => $hasToken && ! $expired,
                'token_expires_at' => $hasToken ? $r['token_expires_at'] : null,
                'invited_at'   => $r['invited_at'],
                'joined_at'    => $r['joined_at'],
            ];
        }

        $overlayRows = $this->db->table('stream_overlays')
            ->where('stream_id', $streamId)->orderBy('overlay_type')->get()->getResultArray();

        $overlays = [];
        foreach ($overlayRows as $r) {
            $config = $r['config_json'] !== null ? (json_decode((string) $r['config_json'], true) ?: []) : [];
            if ($r['overlay_type'] === 'cause_progress' && ! empty($config['cause_id'])) {
                $config = array_merge($config, $this->liveCauseProgress((string) $config['cause_id']));
            }
            $overlays[] = [
                'overlay_id'   => $r['id'],
                'overlay_type' => $r['overlay_type'],
                'version'      => (int) $r['version'],
                'visible'      => (bool) $r['visible'],
                'config'       => $config,
                'updated_at'   => $r['updated_at'] ?? $r['created_at'],
            ];
        }

        return [
            'stream' => [
                'id'            => $stream['id'],
                'title'         => $stream['title'],
                'status'        => $stream['status'],
                'access_policy' => $stream['access_policy'],
            ],
            'cohosts'        => $cohosts,
            'overlays'       => $overlays,
            'overlay_types'  => self::OVERLAY_TYPES,
            'cohost_roles'   => self::COHOST_ROLES,
        ];
    }

    // -- overlays -----------------------------------------------------------

    /**
     * Create or replace an overlay of a fixed type. Config is sanitized and a new
     * version is written each time (history preserved via distinct rows only when
     * versioning; here we bump version in place and keep the row auditable).
     *
     * @param array<string,mixed> $config
     */
    public function upsertOverlay(string $streamId, string $overlayType, array $config, ?string $actorId = null): Result
    {
        if (! in_array($overlayType, self::OVERLAY_TYPES, true)) {
            return Result::fail('BAD_OVERLAY_TYPE', 'overlay.bad_type', 422);
        }

        $clean = $this->sanitizeConfig($overlayType, $config);
        if (! $clean->ok) {
            return $clean;
        }
        $configJson = json_encode($clean->data);

        $now      = $this->clock->nowUtcMicro();
        $existing = $this->db->table('stream_overlays')
            ->where('stream_id', $streamId)->where('overlay_type', $overlayType)->get()->getRowArray();

        if ($existing === null) {
            $id = Uuid::v7();
            $this->db->table('stream_overlays')->insert([
                'id'           => $id,
                'stream_id'    => $streamId,
                'overlay_type' => $overlayType,
                'config_json'  => $configJson,
                'version'      => 1,
                'visible'      => 0,
                'created_by'   => $actorId,
                'created_at'   => $now,
            ]);

            return Result::created(['overlay_id' => $id, 'overlay_type' => $overlayType, 'version' => 1]);
        }

        $version = (int) $existing['version'] + 1;
        $this->db->table('stream_overlays')->where('id', $existing['id'])->update([
            'config_json' => $configJson,
            'version'     => $version,
            'created_by'  => $actorId,
            'updated_at'  => $now,
        ]);

        return Result::ok(['overlay_id' => $existing['id'], 'overlay_type' => $overlayType, 'version' => $version]);
    }

    public function setVisibility(string $overlayId, bool $visible): Result
    {
        $this->db->table('stream_overlays')->where('id', $overlayId)->update([
            'visible'    => $visible ? 1 : 0,
            'updated_at' => $this->clock->nowUtcMicro(),
        ]);

        return Result::ok(['overlay_id' => $overlayId, 'visible' => $visible]);
    }

    /**
     * Render-ready overlay state for the relay compositor. Cause-progress amounts
     * are recomputed live from the ledger — never taken from stored config.
     */
    public function activeOverlays(string $streamId): Result
    {
        $rows = $this->db->table('stream_overlays')
            ->where('stream_id', $streamId)->where('visible', 1)->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $config = $r['config_json'] !== null ? (json_decode((string) $r['config_json'], true) ?: []) : [];
            if ($r['overlay_type'] === 'cause_progress' && ! empty($config['cause_id'])) {
                $config = array_merge($config, $this->liveCauseProgress((string) $config['cause_id']));
            }
            $out[] = [
                'overlay_id'   => $r['id'],
                'overlay_type' => $r['overlay_type'],
                'version'      => (int) $r['version'],
                'config'       => $config,
            ];
        }

        return Result::ok(['overlays' => $out]);
    }

    // -- internals ----------------------------------------------------------

    /** @param array<string,mixed> $config */
    private function sanitizeConfig(string $type, array $config): Result
    {
        return match ($type) {
            'lower_third' => Result::ok([
                'title'    => $this->text($config['title'] ?? '', 120),
                'subtitle' => $this->text($config['subtitle'] ?? '', 160),
            ]),
            'quote_card' => Result::ok([
                'quote'      => $this->text($config['quote'] ?? '', 500),
                'attribution' => $this->text($config['attribution'] ?? '', 120),
            ]),
            'cause_progress' => empty($config['cause_id'])
                ? Result::fail('CAUSE_REQUIRED', 'overlay.cause_required', 422)
                : Result::ok([
                    'cause_id' => (string) $config['cause_id'],
                    'label'    => $this->text($config['label'] ?? '', 120),
                ]),
            default => Result::fail('BAD_OVERLAY_TYPE', 'overlay.bad_type', 422),
        };
    }

    /** Strip tags/control chars and length-cap a text field. */
    private function text(mixed $value, int $max): string
    {
        $s = strip_tags((string) $value);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';

        return mb_substr(trim($s), 0, $max);
    }

    /** @return array<string,mixed> live figures from the ledger */
    private function liveCauseProgress(string $causeId): array
    {
        $cause  = $this->causes->find($causeId);
        $raised = $this->causes->raisedMinor($causeId);
        $show   = $cause !== null && \WBS\Contributions\Services\CauseService::targetsArePublic($cause);
        $target = $show && $cause !== null && $cause['target_minor'] !== null ? (int) $cause['target_minor'] : null;

        return [
            'cause_name'   => $cause['name'] ?? null,
            'currency'     => $cause['currency'] ?? null,
            'raised_minor' => $raised,
            'target_minor' => $target,
            'percent'      => $target !== null && $target > 0 ? min(100, (int) floor($raised * 100 / $target)) : null,
        ];
    }
}
