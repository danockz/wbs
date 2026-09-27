<?php

declare(strict_types=1);

namespace WBS\AccessControl\Controllers;

use WBS\AccessControl\Config\Services as AccessControlServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Break-glass emergency access (SRS FR-ACL-006).
 *
 * Opening requires strong MFA; the opener's assurance is read from the
 * authenticated request context ({@see BaseController::mfaLevel()}), NEVER from
 * the request body, so a caller cannot self-assert strong MFA. Every invariant
 * (reason, MFA, narrow TTL, no finance/audit bypass, auto-expiry, mandatory
 * post-use review) is enforced in {@see \WBS\AccessControl\Services\BreakGlassService}.
 *
 * Routes are gated with `authorize:access.break_glass` so only holders of the
 * emergency capability can invoke it — it is not available for routine use.
 */
final class BreakGlassController extends BaseController
{
    /** POST break-glass — open an emergency session. */
    public function open()
    {
        $openedBy = $this->currentUserId('opened_by');

        return $this->respondWith(AccessControlServices::breakGlass()->open(
            $this->orgId(),
            $openedBy,
            $this->mfaLevel() ?? 'none',
            $this->input(),
        ));
    }

    /** POST break-glass/{id}/close — end an active session early. */
    public function close(string $sessionId = '')
    {
        $result = AccessControlServices::breakGlass()->close(
            $this->orgId(),
            $this->currentUserId(),
            $sessionId,
        );

        return $this->respondDecision($result, $sessionId, 'closedFlash');
    }

    /** POST break-glass/{id}/review — mandatory post-use review (maker≠checker). */
    public function review(string $sessionId = '')
    {
        $result = AccessControlServices::breakGlass()->review(
            $this->orgId(),
            $this->currentUserId('reviewer_id'),
            $sessionId,
            $this->input(),
        );

        return $this->respondDecision($result, $sessionId, 'reviewedFlash');
    }

    /**
     * Shared PRG helper for the close/review actions: browsers are redirected
     * back to the session detail with a success/error flash; API clients get JSON.
     */
    private function respondDecision(Result $result, string $sessionId, string $flashKey)
    {
        if (! $this->wantsJson()) {
            return redirect()->to('/break-glass/' . rawurlencode($sessionId))->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang('AccessControl.breakGlassView.' . $flashKey) : $result->message),
            );
        }

        return $this->respondWith($result);
    }

    /** GET break-glass/pending-reviews — sessions awaiting post-use review. */
    public function pendingReviews()
    {
        $sessions = AccessControlServices::breakGlass()->pendingReviews($this->orgId());

        // API clients get JSON; browsers get the bespoke review-queue view.
        if ($this->wantsJson()) {
            return $this->respondAdmin(
                Result::ok($sessions),
                lang('AccessControl.breakGlassPending'),
            );
        }

        return $this->respondWith(
            Result::ok($sessions),
            'WBS\AccessControl\Views\break_glass_pending',
            null,
            [
                'sessions' => $sessions,
                // Token the global webcsrfissue filter minted this request, so the
                // inline review forms satisfy the webcsrf check.
                'csrf'     => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** GET break-glass/{id} — read one session. */
    public function show(string $sessionId = '')
    {
        $result = AccessControlServices::breakGlass()->show($this->orgId(), $sessionId);

        // API clients get JSON; browsers get the bespoke break-glass-detail view.
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, lang('AccessControl.breakGlassSession'), $sessionId);
        }

        return $this->respondWith(
            $result,
            'WBS\AccessControl\Views\break_glass_show',
            null,
            [
                'session' => $result->data,
                'csrf'    => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }
}
