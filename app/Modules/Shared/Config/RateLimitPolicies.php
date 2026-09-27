<?php

declare(strict_types=1);

namespace WBS\Shared\Config;

use CodeIgniter\Config\BaseConfig;
use WBS\Shared\RateLimiting\RatePolicy;

/**
 * Canonical registry of rate-limit policies (SRS FR-RL-001..006).
 *
 * Security/financial policies fail CLOSED (failOpen=false) and never reveal
 * which key dimension tripped. Webhooks get a dedicated quota so a provider
 * burst is not mistaken for a browser attack. Read this via the FQCN
 * `config(RateLimitPolicies::class)` — never the short-name string, which under
 * some test orderings triggers a Factories re-include fatal.
 */
class RateLimitPolicies extends BaseConfig
{
    /** @return list<RatePolicy> */
    public function all(): array
    {
        return [
            // --- Authentication & recovery (fail CLOSED, generic responses) ---
            new RatePolicy('auth.login', 1, RatePolicy::ALGO_SLIDING_WINDOW, 5, 60, 0, ['route', 'ip', 'user'], RatePolicy::ACT_DENY, false),
            new RatePolicy('auth.social_callback', 1, RatePolicy::ALGO_SLIDING_WINDOW, 10, 60, 0, ['route', 'ip'], RatePolicy::ACT_DENY, false),
            new RatePolicy('auth.register', 1, RatePolicy::ALGO_SLIDING_WINDOW, 5, 300, 0, ['route', 'ip'], RatePolicy::ACT_CHALLENGE, false),
            new RatePolicy('auth.password_reset', 1, RatePolicy::ALGO_SLIDING_WINDOW, 3, 900, 0, ['route', 'ip', 'user'], RatePolicy::ACT_DENY, false),
            new RatePolicy('auth.mfa_verify', 1, RatePolicy::ALGO_SLIDING_WINDOW, 6, 300, 0, ['route', 'user'], RatePolicy::ACT_DENY, false),
            // Step-up CHALLENGE page loads (GET /mfa). Enforced only when a group
            // in the signing-in user's ancestry enables the capability
            // 'security.mfa_challenge_ratelimit' (default off). Keyed on the
            // pending principal + ip; fail CLOSED like the rest of auth.*.
            new RatePolicy('auth.mfa_challenge', 1, RatePolicy::ALGO_SLIDING_WINDOW, 12, 300, 0, ['route', 'user', 'ip'], RatePolicy::ACT_DENY, false),
            new RatePolicy('auth.otp_send', 1, RatePolicy::ALGO_SLIDING_WINDOW, 3, 600, 0, ['route', 'user'], RatePolicy::ACT_DENY, false),

            // --- Referral / cloaked links (bursty public traffic) ---
            new RatePolicy('referral.click', 1, RatePolicy::ALGO_TOKEN_BUCKET, 30, 60, 20, ['route', 'ip'], RatePolicy::ACT_SOFT_DELAY, true),

            // --- Events ---
            new RatePolicy('event.rsvp', 1, RatePolicy::ALGO_SLIDING_WINDOW, 20, 60, 0, ['route', 'user', 'ip'], RatePolicy::ACT_DENY, false),
            new RatePolicy('event.checkin', 1, RatePolicy::ALGO_TOKEN_BUCKET, 60, 60, 30, ['route', 'user'], RatePolicy::ACT_SOFT_DELAY, false),
            // Ticket checkout is money movement — fail CLOSED like contributions.
            new RatePolicy('event.checkout', 1, RatePolicy::ALGO_SLIDING_WINDOW, 15, 60, 0, ['route', 'user', 'ip'], RatePolicy::ACT_DENY, false),

            // --- Contributions / VBCS (money movement; fail CLOSED) ---
            new RatePolicy('contribution.checkout', 1, RatePolicy::ALGO_SLIDING_WINDOW, 15, 60, 0, ['route', 'user', 'ip'], RatePolicy::ACT_DENY, false),

            // --- Courses / learning ---
            new RatePolicy('course.enroll', 1, RatePolicy::ALGO_SLIDING_WINDOW, 20, 60, 0, ['route', 'user', 'ip'], RatePolicy::ACT_DENY, false),
            new RatePolicy('course.submit', 1, RatePolicy::ALGO_SLIDING_WINDOW, 30, 300, 0, ['route', 'user'], RatePolicy::ACT_DENY, false),

            // --- Notifications (fan-out protection, fail CLOSED) ---
            new RatePolicy('notification.broadcast', 1, RatePolicy::ALGO_SLIDING_WINDOW, 10, 60, 0, ['route', 'group', 'user'], RatePolicy::ACT_QUEUE, false),

            // --- Streaming realtime ---
            new RatePolicy('stream.chat', 1, RatePolicy::ALGO_TOKEN_BUCKET, 10, 10, 5, ['route', 'user'], RatePolicy::ACT_SOFT_DELAY, true),
            new RatePolicy('stream.react', 1, RatePolicy::ALGO_TOKEN_BUCKET, 20, 20, 10, ['route', 'user'], RatePolicy::ACT_SOFT_DELAY, true),
            new RatePolicy('stream.viewer', 1, RatePolicy::ALGO_TOKEN_BUCKET, 30, 30, 15, ['route', 'ip'], RatePolicy::ACT_SOFT_DELAY, true),
            // In-stream giving initiates money movement -> fail CLOSED (deny).
            new RatePolicy('stream.giving', 1, RatePolicy::ALGO_SLIDING_WINDOW, 15, 60, 0, ['route', 'user', 'ip'], RatePolicy::ACT_DENY, false),

            // --- Uploads / expensive reads ---
            new RatePolicy('media.upload', 1, RatePolicy::ALGO_SLIDING_WINDOW, 30, 300, 0, ['route', 'user'], RatePolicy::ACT_DENY, false),
            new RatePolicy('report.generate', 1, RatePolicy::ALGO_SLIDING_WINDOW, 10, 300, 0, ['route', 'user'], RatePolicy::ACT_DENY, false),
            new RatePolicy('search.query', 1, RatePolicy::ALGO_TOKEN_BUCKET, 30, 60, 15, ['route', 'user', 'ip'], RatePolicy::ACT_SOFT_DELAY, true),

            // --- Provider webhooks (dedicated quota, inbox path; FR-RL-005) ---
            new RatePolicy('webhook.payment', 1, RatePolicy::ALGO_SLIDING_WINDOW, 300, 60, 0, ['route', 'provider'], RatePolicy::ACT_QUEUE, false),

            // --- Admin / privileged actions (fail CLOSED) ---
            new RatePolicy('admin.action', 1, RatePolicy::ALGO_SLIDING_WINDOW, 60, 60, 0, ['route', 'user'], RatePolicy::ACT_DENY, false),
            new RatePolicy('provider.configure', 1, RatePolicy::ALGO_SLIDING_WINDOW, 20, 300, 0, ['route', 'user'], RatePolicy::ACT_DENY, false),
        ];
    }
}
