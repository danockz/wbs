<?php

declare(strict_types=1);

namespace WBS\Events\Controllers;

use WBS\Events\Config\Services as EventServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Event media (SRS FR-EVT-015). Upload/review routes are permission-gated; the
 * public listing returns only clean+approved+public items.
 *
 * GET events/{id}/media/review is a browser MEDIA CONSOLE: the authorized full
 * listing (any state) with a register-by-reference form and per-item approve /
 * reject controls (approval carries a visibility choice; only clean items can be
 * approved — enforced in the service). The public listing stays JSON. Console
 * POSTs are webcsrf-guarded; API clients keep JSON.
 */
final class MediaController extends BaseController
{
    public function register(string $eventId = '')
    {
        $result = EventServices::media()->register(
            $this->orgId(),
            $eventId,
            $this->currentUserId('uploaded_by'),
            $this->input(),
        );

        return $this->respondMedia($result, $eventId, 'registeredFlash');
    }

    public function review(string $mediaId = '')
    {
        $in = $this->input();

        $result = EventServices::media()->review(
            $mediaId,
            (string) ($in['decision'] ?? 'reject'),
            $in['visibility'] ?? null,
        );

        $okKey = ($in['decision'] ?? '') === 'approve' ? 'approvedFlash' : 'rejectedFlash';

        return $this->respondMedia($result, (string) ($result->data['event_id'] ?? ''), $okKey);
    }

    /** Authorized full listing (any state) for organizers/logistics. */
    public function listForReview(string $eventId = '')
    {
        $rows = EventServices::media()->listForReview($eventId);

        // API clients get JSON; browsers get the bespoke media-review console.
        if ($this->wantsJson()) {
            return $this->respondAdmin(Result::ok(['media' => $rows, 'count' => count($rows)]), 'Media review queue', 'event ' . $eventId);
        }

        return $this->renderForm('WBS\Events\Views\media_review', [
            'media'   => $rows,
            'eventId' => $eventId,
        ]);
    }

    /** Public: clean + approved + public media only. */
    public function publicMedia(string $eventId = '')
    {
        $rows = EventServices::media()->publicMedia($eventId);

        return $this->respondPage(
            Result::ok(['media' => $rows, 'count' => count($rows)]),
            'event_media',
            static fn (array $d): array => ['rows' => $d['media'] ?? [], 'count' => $d['count'] ?? null, 'back' => 'events/' . $eventId],
        );
    }

    /**
     * PRG for a browser media write: API clients keep the raw Result (JSON);
     * browsers redirect back to the event media review console with a localized
     * success flash, or the failing Result's message as an error flash.
     */
    private function respondMedia(Result $result, string $eventId, string $okKey)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $to = $eventId !== '' ? '/events/' . rawurlencode($eventId) . '/media/review' : '/events';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Events.mediaReview.' . $okKey));
    }
}
