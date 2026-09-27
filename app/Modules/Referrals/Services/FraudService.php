<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;

/**
 * Click fraud / abuse heuristics for referral links (adaptation of the
 * FraudDetector delegation in clicktracker.md).
 *
 * Adapted to WBS privacy invariants:
 *  - Velocity and self-click checks key off the SALTED ip_hash, never a raw IP.
 *  - Bot heuristics inspect the raw user-agent IN-REQUEST ONLY; only the verdict
 *    (a flag + PII-free reason) is ever persisted, not the UA string.
 *  - Returns a FraudVerdict value object; callers decide what to store.
 *
 * The thresholds are deliberately conservative defaults; a later iteration can
 * move them to config or a per-org policy without changing callers.
 */
final class FraudService
{
    /** Max clicks from one ip_hash on one link within the window before flagging. */
    private const VELOCITY_LIMIT = 10;

    /** Sliding window (seconds) for the velocity check. */
    private const VELOCITY_WINDOW = 60;

    /** Score at/above which a click is marked suspicious. */
    private const SUSPICIOUS_THRESHOLD = 50;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Assess a single click before it is persisted.
     *
     * @param array{link_id:string,ip_hash?:?string,user_agent?:?string,consent?:bool} $ctx
     */
    public function assess(array $ctx): FraudVerdict
    {
        $signals = [];
        $score   = 0;

        // 1. Velocity: too many clicks from the same hashed IP on this link.
        if (! empty($ctx['ip_hash']) && $this->velocityExceeded((string) $ctx['link_id'], (string) $ctx['ip_hash'])) {
            $signals[] = 'velocity';
            $score += 40;
        }

        // 2. Bot / automation signature in the raw UA (inspected, not stored).
        if (! empty($ctx['user_agent']) && $this->looksLikeBot((string) $ctx['user_agent'])) {
            $signals[] = 'bot_ua';
            $score += 30;
        }

        // 3. Missing / empty user-agent is itself weakly suspicious.
        if (empty($ctx['user_agent'])) {
            $signals[] = 'no_ua';
            $score += 20;
        }

        // 4. No consent recorded — weighted low; consent is captured separately
        //    but a click that never carries it is a slightly weaker signal.
        if (empty($ctx['consent'])) {
            $signals[] = 'no_consent';
            $score += 10;
        }

        $score = min($score, 100);

        return new FraudVerdict($score >= self::SUSPICIOUS_THRESHOLD, $score, $signals);
    }

    /** True if this ip_hash has clicked this link more than the limit in the window. */
    private function velocityExceeded(string $linkId, string $ipHash): bool
    {
        $since = $this->clock->now()
            ->modify('-' . self::VELOCITY_WINDOW . ' seconds')
            ->format('Y-m-d H:i:s.u');

        $count = $this->db->table('referral_clicks')
            ->where('link_id', $linkId)
            ->where('ip_hash', $ipHash)
            ->where('created_at >=', $since)
            ->countAllResults();

        return $count >= self::VELOCITY_LIMIT;
    }

    /** Cheap heuristic bot detection on the raw UA string (not persisted). */
    private function looksLikeBot(string $userAgent): bool
    {
        $ua = strtolower($userAgent);
        if (trim($ua) === '') {
            return false; // handled by the no_ua signal instead
        }

        foreach (['bot', 'crawl', 'spider', 'scrape', 'headless', 'python-requests', 'curl/', 'wget', 'httpclient', 'phantomjs', 'puppeteer'] as $needle) {
            if (str_contains($ua, $needle)) {
                return true;
            }
        }

        return false;
    }
}
