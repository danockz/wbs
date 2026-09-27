<?php

declare(strict_types=1);

namespace App\Controllers;

use Config\Database;
use Throwable;
use WBS\Shared\Config\Services;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Landing / platform status. Extends the unified BaseController so the same
 * endpoint can serve HTML or JSON (SRS FR-ARC-001/002).
 */
class Home extends BaseController
{
    public function index(): mixed
    {
        $status = [
            'platform'     => 'Win–Build–Send',
            'phase'        => 'Foundation + Identity/MFA + Build (Events, VBCS, Integrations, Courses)',
            'php'          => PHP_VERSION,
            'framework'    => 'CodeIgniter ' . \CodeIgniter\CodeIgniter::CI_VERSION,
            'database'     => $this->dbInfo(),
            'redis'        => $this->redisInfo(),
            'modules'      => $this->modules(),
            'ratePolicies' => count(Services::ratePolicyRegistry(false)->names()),
            'capabilities' => [
                'Unified web/API endpoints (representation negotiation)',
                'Reusable rate limiting (Redis Lua, fail-closed, hierarchical)',
                'Tamper-evident hash-chained audit log',
                'MAC + RBAC + ABAC Policy Decision Point (default-deny)',
                'Adaptive/dynamic MFA: risk engine, TOTP, recovery codes, step-up',
                'Cloaked referral links + unilevel sponsorship (acyclic, single-active)',
                'Group hierarchy (1–9 levels): depth-governed, acyclic, closure-projected',
                'Notification preference centre + preference-enforcing dispatcher → outbox',
                'Gamification: immutable idempotent point ledger, anti-gaming rules, annual season rollover',
                'Events: atomic-capacity RSVP + waitlist, replay-proof QR check-in, cross-group no double-count',
                'Contributions (VBCS): integer-minor double-entry ledger, idempotent webhook inbox, refund maker-checker (SoD), money↔points bridge',
                'Integrations / UPAF: declarative connector safety boundary, encrypted write-only credential vault, immutable certification-gated profiles, hierarchical reuse grants',
                'Courses: native lessons/quizzes, rule-based idempotent completion, drip-feed scheduling, certificate eligibility',
                'Transactional outbox + idempotency keys',
            ],
        ];

        return $this->respondWith(
            Result::ok($status),
            htmlView: 'landing',
            viewData: ['status' => $status],
        );
    }

    private function dbInfo(): string
    {
        try {
            $db = Database::connect();
            $db->query('SELECT 1');

            return $db->getPlatform() . ' ' . ($db->getVersion() ?: '');
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    private function redisInfo(): string
    {
        try {
            $r = Services::redis(false);

            return $r && $r->ping() !== false ? 'connected' : 'unavailable';
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    /** @return list<string> */
    private function modules(): array
    {
        $dirs = glob(APPPATH . 'Modules/*', GLOB_ONLYDIR) ?: [];

        return array_map(static fn ($d) => basename($d), $dirs);
    }
}
