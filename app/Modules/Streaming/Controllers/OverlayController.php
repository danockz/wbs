<?php

declare(strict_types=1);

namespace WBS\Streaming\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;
use WBS\Streaming\Config\Services as StreamingServices;

/**
 * Co-hosts + versioned overlays (SRS FR-STR-008).
 *
 * The moderator console is now a bespoke WRITE surface (it used to render read-
 * only, sending every management action to the generic data-page): invite a
 * co-host, issue a short-lived join token, remove a co-host, create/update an
 * overlay of a fixed type, and show/hide an overlay. Each control POSTs to a
 * webcsrf-guarded route; the controller PRG-redirects back to the console with a
 * localized flash (JSON preserved for API clients). The overlay author identity
 * comes from the authenticated session.
 *
 * Secret discipline: a co-host join token is returned ONCE by the service. On a
 * browser flow it is surfaced once via a distinct one-shot flash so the moderator
 * can hand it to the co-host; it is never persisted to a rendered page or shown
 * again. The console read itself never exposes the token hash/plaintext.
 */
final class OverlayController extends BaseController
{
    /**
     * Moderator management console (SRS FR-STR-008): co-hosts + all overlays
     * (including hidden), with live cause figures. HTML when browsed, JSON when
     * negotiated. Behind authorize:stream.moderate on the route.
     */
    public function console(string $streamId = '')
    {
        $console = StreamingServices::overlays()->console($streamId);
        if ($console === null) {
            return $this->respondWith(Result::notFound('stream.not_found', 'STREAM_NOT_FOUND'));
        }

        return $this->respondWith(
            Result::ok($console),
            htmlView: 'WBS\Streaming\Views\overlays',
            viewData: [
                'result'   => $console,
                'title'    => 'Overlays & co-hosts',
                'streamId' => $streamId,
                // user_id on the invite-co-host form is an entity reference →
                // offer the org roster (view excludes existing co-hosts).
                'roster'   => IdentityServices::accounts()->listMembers($this->orgId(), 'active'),
                'csrf'     => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    public function inviteCohost(string $streamId = '')
    {
        $in = $this->input();

        return $this->respondOverlayDecision(
            StreamingServices::overlays()->inviteCohost(
                $streamId,
                (string) ($in['user_id'] ?? ''),
                (string) ($in['role'] ?? 'cohost'),
            ),
            $streamId,
            'invitedFlash',
        );
    }

    public function issueCohostToken(string $streamId = '')
    {
        $in     = $this->input();
        $result = StreamingServices::overlays()->issueCohostToken(
            $streamId,
            (string) ($in['user_id'] ?? ''),
            (int) ($in['ttl_seconds'] ?? 3600),
        );

        // Surface the one-time plaintext token to the moderator via a one-shot
        // flash so they can hand it to the co-host; never rendered again.
        $token = $result->ok ? (string) ($result->data['join_token'] ?? '') : '';

        return $this->respondOverlayDecision($result, $streamId, 'tokenIssuedFlash', $token);
    }

    public function removeCohost(string $streamId = '', string $userId = '')
    {
        // The DELETE route passes the user id in the URL; the browser POST alias
        // passes it as a field. Honor whichever is present.
        if ($userId === '') {
            $userId = (string) ($this->field('user_id', '') ?? '');
        }

        return $this->respondOverlayDecision(
            StreamingServices::overlays()->removeCohost($streamId, $userId),
            $streamId,
            'removedFlash',
        );
    }

    public function upsertOverlay(string $streamId = '')
    {
        $in    = $this->input();
        $actor = $this->actorId();

        return $this->respondOverlayDecision(
            StreamingServices::overlays()->upsertOverlay(
                $streamId,
                (string) ($in['overlay_type'] ?? ''),
                (array) ($in['config'] ?? []),
                $actor,
            ),
            $streamId,
            'overlaySavedFlash',
        );
    }

    public function setVisibility(string $overlayId = '')
    {
        return $this->respondOverlayDecision(
            StreamingServices::overlays()->setVisibility(
                $overlayId,
                (bool) $this->field('visible', false),
            ),
            (string) ($this->field('stream_id', '') ?? ''),
            'visibilityFlash',
        );
    }

    public function active(string $streamId = '')
    {
        return $this->respondWith(StreamingServices::overlays()->activeOverlays($streamId));
    }

    /**
     * Post/Redirect/Get for a browser overlay/co-host write: API clients keep the
     * raw Result (JSON); browsers are redirected back to the stream's console with
     * a localized success flash (plus an optional one-shot secret flash), or the
     * failing Result's message as an error flash.
     */
    private function respondOverlayDecision(Result $result, string $streamId, string $okKey, string $tokenOnce = ''): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $streamId = (string) $streamId;
        $to = $streamId !== '' ? '/streams/' . rawurlencode($streamId) . '/console' : '/streams';

        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        $redirect = redirect()->to($to)->with('success', lang('Streaming.overlaysConsole.' . $okKey));
        if ($tokenOnce !== '') {
            $redirect = $redirect->with('cohost_token', $tokenOnce);
        }

        return $redirect;
    }
}
