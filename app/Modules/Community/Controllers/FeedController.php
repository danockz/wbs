<?php

declare(strict_types=1);

namespace WBS\Community\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\AccessControl\Config\Services as AccessControlServices;
use WBS\AccessControl\Policy\AccessRequest;
use WBS\Community\Config\Services as CommunityServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Community feed endpoints (SRS FR-COM-001/002/004).
 *
 * Reads are visibility-filtered through the PDP inside FeedService. The viewer
 * id comes from the authenticated session context set by AuthFilter; unauthenticated
 * callers only ever see `public` posts.
 *
 * The feed page now also carries the member WRITE affordances — compose a post,
 * comment, react, and report — plus (for a moderator) an inline hide/lock/delete
 * control. Each browser write POSTs to a webcsrf-guarded route and is PRG-
 * redirected back to the feed with a localized flash so a reload never re-submits
 * and a browser never sees raw JSON. API clients keep the identical JSON payloads.
 */
final class FeedController extends BaseController
{
    public function index()
    {
        $orgId    = $this->orgId();
        $viewerId = $this->actorId();

        $result = CommunityServices::feed()->feed($orgId, $viewerId, [
            'group_id' => $this->field('group_id'),
            'limit'    => (int) $this->field('limit', 20),
        ]);

        return $this->respondWith(
            $result,
            htmlView: 'WBS\Community\Views\feed',
            viewData: [
                'result'       => $result->data,
                'title'        => 'Community feed',
                'csrf'         => (string) ($this->request->wbsCsrf ?? ''),
                'canModerate'  => $this->canModerate($orgId, $viewerId),
                'isAuthed'     => $viewerId !== null,
            ],
        );
    }

    public function create()
    {
        $in     = $this->input();
        $orgId  = $this->orgId();
        $author = $this->currentUserId('author_id');

        return $this->respondFeedDecision(
            CommunityServices::feed()->createPost($orgId, $author, $in),
            'postedFlash',
        );
    }

    public function comment(string $postId = '')
    {
        $in     = $this->input();
        $author = $this->currentUserId('author_id');

        return $this->respondFeedDecision(
            CommunityServices::feed()->comment(
                $postId,
                $author,
                (string) ($in['body'] ?? ''),
                $in['parent_id'] ?? null,
            ),
            'commentedFlash',
        );
    }

    public function react(string $postId = '')
    {
        $in     = $this->input();
        $userId = $this->currentUserId('user_id');

        return $this->respondFeedDecision(
            CommunityServices::feed()->react(
                $postId,
                $userId,
                (string) ($in['reaction'] ?? 'like'),
            ),
            'reactedFlash',
        );
    }

    /**
     * Post/Redirect/Get for a browser feed write: API clients keep the raw Result
     * (JSON); browsers are redirected back to the feed with a localized success
     * flash, or the failing Result's message as an error flash.
     */
    private function respondFeedDecision(Result $result, string $okKey): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondJson($result);
        }

        $to = '/community/feed';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Community.admin.' . $okKey));
    }

    /**
     * ONE PDP call per page render (not per post) to decide whether to show the
     * moderator affordances. Reuses the same AuthorizationService the
     * authorize:community.moderate route filter uses — no parallel permission list.
     * Fault-isolated: any PDP hiccup simply hides the controls.
     */
    private function canModerate(string $orgId, ?string $viewerId): bool
    {
        if ($viewerId === null || $orgId === '') {
            return false;
        }

        try {
            return AccessControlServices::authorization()->isAllowed(new AccessRequest(
                organizationId: $orgId,
                subjectId: $viewerId,
                action: 'community.moderate',
                attributes: ['mfa_level' => $this->mfaLevel() ?? 'none'],
            ));
        } catch (\Throwable) {
            return false;
        }
    }
}
