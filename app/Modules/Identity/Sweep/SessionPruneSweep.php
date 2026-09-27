<?php

declare(strict_types=1);

namespace WBS\Identity\Sweep;

use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C, ID4): expire + prune stale auth material so the
 * server-side tables stay bounded. Originally session-only; ID4 extends it to the
 * token tables that also lazy-expire and were never pruned — API access + refresh
 * tokens and credential-setup / invite tokens. Each underlying operation is the
 * already-idempotent `prune()` on its owning service:
 *
 *   - SessionService::prune()        — sessions
 *   - TokenService::prune()          — access_tokens + refresh_tokens
 *   - CredentialSetupService::prune()— credential_setup_tokens
 *
 * All three tables are global (not per-org), so this ignores the org scope. Every
 * pass first flips still-live rows past their expiry to a terminal state, then
 * hard-deletes rows that reached a terminal state longer than the retention
 * window ago (audit grace before removal).
 */
final class SessionPruneSweep implements SweepContract
{
    public function key(): string
    {
        return 'identity.session-prune';
    }

    public function description(): string
    {
        return 'Expire and prune stale sessions, API access/refresh tokens and credential-setup tokens past the retention window.';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $retentionDays = isset($options['retention_days']) ? (int) $options['retention_days'] : 30;
        $retentionDays = $retentionDays > 0 ? $retentionDays : 30;

        $s = IdentityServices::sessions()->prune($retentionDays);
        $t = IdentityServices::tokens()->prune($retentionDays);
        $c = IdentityServices::credentialSetup()->prune($retentionDays);

        $sessionExpired = (int) ($s['expired'] ?? 0);
        $sessionDeleted = (int) ($s['deleted'] ?? 0);
        $tokenExpired   = (int) ($t['access_expired'] ?? 0) + (int) ($t['refresh_expired'] ?? 0);
        $tokenDeleted   = (int) ($t['access_deleted'] ?? 0) + (int) ($t['refresh_deleted'] ?? 0);
        $inviteExpired  = (int) ($c['expired'] ?? 0);
        $inviteDeleted  = (int) ($c['deleted'] ?? 0);

        $swept = $sessionExpired + $sessionDeleted
            + $tokenExpired + $tokenDeleted
            + $inviteExpired + $inviteDeleted;

        return SweepResult::ok($swept, [
            'session_expired' => $sessionExpired,
            'session_deleted' => $sessionDeleted,
            'access_expired'  => (int) ($t['access_expired'] ?? 0),
            'refresh_expired' => (int) ($t['refresh_expired'] ?? 0),
            'access_deleted'  => (int) ($t['access_deleted'] ?? 0),
            'refresh_deleted' => (int) ($t['refresh_deleted'] ?? 0),
            'invite_expired'  => $inviteExpired,
            'invite_deleted'  => $inviteDeleted,
        ]);
    }
}
