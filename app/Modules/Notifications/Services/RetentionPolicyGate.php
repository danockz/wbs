<?php

declare(strict_types=1);

namespace WBS\Notifications\Services;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use WBS\Shared\Support\Clock;

/**
 * Pre-dispatch retention/consent gate (SRS FR-NOT-006).
 *
 * Evaluated for EVERY send. Non-essential messages are deferred or suppressed
 * rather than repeatedly retried. Essential categories (security/service/
 * payment receipt/legal) bypass preference-based suppression but still honour
 * hard do-not-contact suppression lists where lawful.
 *
 * Returns a decision: send | defer | suppress with a reason.
 */
final class RetentionPolicyGate
{
    /** Categories with a separate lawful basis (FR-ID-008 / FR-NOT-006). */
    private const ESSENTIAL = ['security', 'service', 'payment_receipt', 'legal'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string,mixed> $ctx frequency_cap (int, per 24h), priority
     *
     * @return array{action:'send'|'defer'|'digest'|'suppress', reason:?string, defer_until:?string}
     */
    public function evaluate(string $organizationId, string $userId, string $channel, string $category, array $ctx = []): array
    {
        $essential = in_array($category, self::ESSENTIAL, true);

        // 1. Hard do-not-contact / complaint / STOP suppression — applies to all
        //    but essential legal/security keeps a documented lawful basis.
        $suppressed = $this->db->table('notification_suppressions')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereIn('scope', ['all', 'channel'])
            ->countAllResults() > 0;
        if ($suppressed && ! $essential) {
            return $this->decide('suppress', 'do_not_contact');
        }

        // 2. Verified channel required.
        $verified = $this->db->table('notification_channels')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('channel', $channel)
            ->where('verified_at IS NOT NULL', null, false)
            ->countAllResults() > 0;
        if (! $verified && ! $essential) {
            return $this->decide('suppress', 'unverified_channel');
        }

        $pref = $this->db->table('notification_preferences')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('channel', $channel)
            ->where('category', $category)
            ->get()->getRowArray();

        if (! $essential && $pref !== null) {
            // 3. Hard opt-out / STOP — group policy can never override (FR-ID-008).
            if ((int) $pref['stopped'] === 1) {
                return $this->decide('suppress', 'opt_out');
            }
            if ((int) $pref['opted_in'] === 0) {
                return $this->decide('suppress', 'opt_out');
            }
            $digest = (string) ($pref['digest_frequency'] ?? 'instant');
            if ($digest === 'off') {
                return $this->decide('suppress', 'digest_off');
            }

            // 3b. N4 — DIGEST batching. When the user asked for a daily/weekly
            //     summary, this message is HELD until the next digest window
            //     instead of sending now; the digest builder later bundles all
            //     held messages for the (user, channel) into one summary. Digest
            //     supersedes quiet-hours/frequency-cap (the message won't go out
            //     until the window anyway). Essential categories never digest.
            if ($digest === 'daily' || $digest === 'weekly') {
                return $this->decide('digest', 'digest_' . $digest, $this->digestDefer($pref, $digest));
            }

            // 4. Quiet hours -> defer to end of quiet window in user's timezone.
            $deferUntil = $this->quietHoursDefer($pref);
            if ($deferUntil !== null) {
                return $this->decide('defer', 'quiet_hours', $deferUntil);
            }
        }

        // 5. Frequency cap (non-essential only).
        if (! $essential && isset($ctx['frequency_cap'])) {
            $cap    = (int) $ctx['frequency_cap'];
            $since  = $this->clock->now()->modify('-24 hours')->format('Y-m-d H:i:s');
            $recent = $this->db->table('notification_deliveries')
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('category', $category)
                ->whereIn('status', ['queued', 'sent', 'delivered'])
                ->where('created_at >=', $since)
                ->countAllResults();
            if ($cap > 0 && $recent >= $cap) {
                return $this->decide('defer', 'frequency_cap', $this->clock->now()->modify('+24 hours')->format('Y-m-d H:i:s'));
            }
        }

        return $this->decide('send', null);
    }

    /** @return array{action:string, reason:?string, defer_until:?string} */
    private function decide(string $action, ?string $reason, ?string $deferUntil = null): array
    {
        return ['action' => $action, 'reason' => $reason, 'defer_until' => $deferUntil];
    }

    /**
     * The UTC datetime at which the next digest window closes for this preference
     * (N4). `daily` = the next local midnight; `weekly` = the next local Monday
     * 00:00. This is a "not before" watermark: the digest builder sends bundled
     * messages once their window has passed. Computed in the user's timezone so a
     * digest lands at a sensible local hour.
     *
     * @param array<string,mixed> $pref
     */
    private function digestDefer(array $pref, string $frequency): string
    {
        $tz  = new DateTimeZone(($pref['timezone'] ?? '') ?: 'UTC');
        $now = $this->clock->now()->setTimezone($tz);

        if ($frequency === 'weekly') {
            // Next Monday 00:00 local (strictly in the future).
            $next = $now->modify('monday next week')->setTime(0, 0, 0);
        } else {
            // Next local midnight.
            $next = $now->modify('tomorrow')->setTime(0, 0, 0);
        }

        return $next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /** Returns a defer-until datetime string if currently within quiet hours. */
    private function quietHoursDefer(array $pref): ?string
    {
        if (empty($pref['quiet_start']) || empty($pref['quiet_end'])) {
            return null;
        }
        $tz  = new DateTimeZone($pref['timezone'] ?: 'UTC');
        $now = $this->clock->now()->setTimezone($tz);

        $start = DateTimeImmutable::createFromFormat('H:i:s', substr((string) $pref['quiet_start'], 0, 8), $tz)
            ->setDate((int) $now->format('Y'), (int) $now->format('m'), (int) $now->format('d'));
        $end = DateTimeImmutable::createFromFormat('H:i:s', substr((string) $pref['quiet_end'], 0, 8), $tz)
            ->setDate((int) $now->format('Y'), (int) $now->format('m'), (int) $now->format('d'));

        if ($start <= $end) {
            // Same-day window, e.g. 09:00-17:00.
            if ($now >= $start && $now < $end) {
                return $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        } else {
            // Overnight window, e.g. 22:00-07:00.
            if ($now >= $start) {
                return $end->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
            if ($now < $end) {
                return $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }

        return null;
    }
}
