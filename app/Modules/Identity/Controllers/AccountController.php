<?php

declare(strict_types=1);

namespace WBS\Identity\Controllers;

use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Account lifecycle + identity-merge administration (SRS FR-ID-009, FR-ID-002).
 *
 * Every mutating endpoint is authenticated and permission-gated
 * (`authorize:identity.manage`) in Routes.php. The acting administrator (actor)
 * always comes from the authenticated session, never from client input, so the
 * maker-checker on merge approval cannot be spoofed. Each transition/merge
 * action requires a stated reason.
 */
final class AccountController extends BaseController
{
    /**
     * GET members — the member roster (optionally by ?status=). Browsers get the
     * bespoke roster view (avatars + status); API clients get JSON. Read-only;
     * gated by identity.manage in Routes.php.
     */
    public function index()
    {
        $status  = $this->field('status');
        $members = IdentityServices::accounts()->listMembers(
            $this->orgId(),
            $status !== null ? (string) $status : null,
        );

        return $this->respondWith(
            Result::ok(['members' => $members]),
            'WBS\Identity\Views\members',
            null,
            ['members' => $members, 'csrf' => (string) ($this->request->wbsCsrf ?? '')],
        );
    }

    // ------------------------------------------------------------- lifecycle

    /** Generic transition: body { status, reason, approval_ref?, evidence? }. */
    public function transition(string $userId = '')
    {
        $in = $this->input();

        return $this->respondWith(IdentityServices::accountLifecycle()->transition(
            $this->orgId(),
            $userId,
            (string) ($in['status'] ?? ''),
            (string) ($in['reason'] ?? ''),
            [
                'actor_id'     => $this->currentUserId('actor_id'),
                'approval_ref' => $in['approval_ref'] ?? null,
                'evidence'     => $in['evidence'] ?? null,
            ],
        ));
    }

    public function suspend(string $userId = '')
    {
        return $this->respondWith(IdentityServices::accountLifecycle()->suspend(
            $this->orgId(),
            $userId,
            (string) $this->field('reason', ''),
            $this->currentUserId('actor_id'),
        ));
    }

    public function lock(string $userId = '')
    {
        return $this->respondWith(IdentityServices::accountLifecycle()->lock(
            $this->orgId(),
            $userId,
            (string) $this->field('reason', ''),
            $this->currentUserId('actor_id'),
        ));
    }

    public function reactivate(string $userId = '')
    {
        return $this->respondWith(IdentityServices::accountLifecycle()->reactivate(
            $this->orgId(),
            $userId,
            (string) $this->field('reason', ''),
            $this->currentUserId('actor_id'),
        ));
    }

    public function deactivate(string $userId = '')
    {
        return $this->respondWith(IdentityServices::accountLifecycle()->deactivate(
            $this->orgId(),
            $userId,
            (string) $this->field('reason', ''),
            $this->currentUserId('actor_id'),
        ));
    }

    public function anonymize(string $userId = '')
    {
        return $this->respondWith(IdentityServices::accountLifecycle()->anonymize(
            $this->orgId(),
            $userId,
            (string) $this->field('reason', ''),
            $this->currentUserId('actor_id'),
        ));
    }

    /** Read-only lifecycle history for an account. */
    public function history(string $userId = '')
    {
        $rows = IdentityServices::accountLifecycle()->history($this->orgId(), $userId);
        if ($this->wantsJson()) {
            return $this->respondAdmin(
                Result::ok(['user_id' => $userId, 'transitions' => $rows, 'count' => count($rows)]),
                'Account transitions',
                'user ' . $userId,
            );
        }

        return $this->respondWith(
            Result::ok(['user_id' => $userId, 'transitions' => $rows, 'count' => count($rows)]),
            'WBS\Identity\Views\account_history',
            null,
            ['transitions' => $rows, 'userId' => $userId],
        );
    }

    // ----------------------------------------------------------------- merge

    /** Open a merge review: body { primary_user_id, duplicate_user_id, reason }. */
    public function submitMerge()
    {
        $in = $this->input();

        return $this->respondWith(IdentityServices::accountLifecycle()->submitMerge(
            $this->orgId(),
            (string) ($in['primary_user_id'] ?? ''),
            (string) ($in['duplicate_user_id'] ?? ''),
            $this->currentUserId('requested_by'),
            (string) ($in['reason'] ?? ''),
        ));
    }

    public function pendingMerges()
    {
        $rows = IdentityServices::accountLifecycle()->pendingMerges($this->orgId());
        if ($this->wantsJson()) {
            return $this->respondAdmin(
                Result::ok(['merges' => $rows, 'count' => count($rows)]),
                'Account merges — pending',
            );
        }

        return $this->respondWith(
            Result::ok(['merges' => $rows, 'count' => count($rows)]),
            'WBS\Identity\Views\merges_pending',
            null,
            [
                'merges' => $rows,
                // Token the global webcsrfissue filter minted this request, so the
                // inline approve/reject forms satisfy the webcsrf check.
                'csrf'   => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    public function showMerge(string $mergeId = '')
    {
        $merge = IdentityServices::accountLifecycle()->findMerge($this->orgId(), $mergeId);

        if ($this->wantsJson()) {
            if ($merge === null) {
                return $this->respondAdmin(
                    Result::notFound('identity.merge_not_found', 'MERGE_NOT_FOUND'),
                    'Account merge',
                    $mergeId,
                );
            }

            return $this->respondAdmin(Result::ok($merge), 'Account merge', $mergeId);
        }

        $result = $merge === null
            ? Result::notFound('identity.merge_not_found', 'MERGE_NOT_FOUND')
            : Result::ok($merge);

        return $this->respondWith($result, 'WBS\Identity\Views\merge_show', null, [
            'merge' => $merge,
            'csrf'  => (string) ($this->request->wbsCsrf ?? ''),
        ]);
    }

    public function approveMerge(string $mergeId = '')
    {
        $result = IdentityServices::accountLifecycle()->approveMerge(
            $this->orgId(),
            $mergeId,
            $this->currentUserId('actor_id'),
            $this->field('note'),
        );

        return $this->respondMergeDecision($result, $mergeId, 'approvedFlash');
    }

    public function rejectMerge(string $mergeId = '')
    {
        $result = IdentityServices::accountLifecycle()->rejectMerge(
            $this->orgId(),
            $mergeId,
            $this->currentUserId('actor_id'),
            $this->field('note'),
        );

        return $this->respondMergeDecision($result, $mergeId, 'rejectedFlash');
    }

    public function cancelMerge(string $mergeId = '')
    {
        $result = IdentityServices::accountLifecycle()->cancelMerge(
            $this->orgId(),
            $mergeId,
            $this->currentUserId('actor_id'),
            $this->field('note'),
        );

        return $this->respondMergeDecision($result, $mergeId, 'cancelledFlash');
    }

    /**
     * PRG for a browser merge decision: redirect to the merge detail with a
     * success/error flash; API clients keep the JSON Result. Success copy comes
     * from the localized mergeShow flash keys; failures surface the Result message
     * (SoD self-approval, bad-state, etc. — the service localizes these).
     */
    private function respondMergeDecision(Result $result, string $mergeId, string $flashKey)
    {
        if (! $this->wantsJson()) {
            return redirect()->to('/identity/merges/' . rawurlencode($mergeId))->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang('Identity.mergeShow.' . $flashKey) : $result->message),
            );
        }

        return $this->respondWith($result);
    }
}
