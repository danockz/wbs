<?php

declare(strict_types=1);

namespace WBS\Events\Controllers;

use WBS\Events\Config\Services as EventServices;
use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\BaseController;

/**
 * Event check-in endpoints (SRS FR-EVT-007/008). AJAX-capable, idempotent.
 *
 * The id-scoped POSTs (`qr`, `manual`, `issueNonce`) are the machine surface used
 * by kiosks/scanners and the JSON API. The collection-level `checkinForm` /
 * `checkinDispatch` pair is the human surface: the menu "Check-in" link lands on a
 * page that lets a staff member pick an event and record a MANUAL check-in without
 * ever seeing raw JSON, using the Post/Redirect/Get pattern.
 */
final class CheckinController extends BaseController
{
    public function issueNonce(string $eventId = '')
    {
        return $this->respondWith(EventServices::checkin()->issueNonce($eventId));
    }

    public function qr(string $eventId = '')
    {
        $in    = $this->input();
        $orgId = $this->orgId();

        return $this->respondWith(EventServices::checkin()->checkInByQr(
            $orgId,
            (string) ($in['qr'] ?? ''),
            (string) ($in['user_id'] ?? ''),
            (array) ($in['group_attribution'] ?? []),
        ));
    }

    public function manual(string $eventId = '')
    {
        $in    = $this->input();
        $orgId = $this->orgId();

        return $this->respondWith(EventServices::checkin()->checkInManual(
            $orgId,
            $eventId,
            (string) ($in['user_id'] ?? ''),
            (string) ($in['staff_id'] ?? ''),
            (string) ($in['reason'] ?? ''),
            (array) ($in['group_attribution'] ?? []),
        ));
    }

    /**
     * GET events/{id}/reconcile — post-event attendance reconciliation (gap L6).
     * Aggregate-only: matched vs walk-in attendance, no-shows and paid-but-absent.
     * API clients get JSON; browsers get the bespoke reconciliation view.
     */
    public function reconcile(string $eventId = '')
    {
        $result = EventServices::checkin()->reconcile($eventId);

        if ($this->wantsJson()) {
            return $this->respondAdmin($result, 'Attendance reconciliation', 'event ' . $eventId);
        }

        return $this->respondWith(
            $result,
            'WBS\Events\Views\attendance_reconcile',
            null,
            ['recon' => is_array($result->data) ? $result->data : null],
        );
    }

    /**
     * GET events/checkin — bespoke MANUAL check-in launcher (menu landing page).
     * Lists the org's events for selection and renders the manual-check-in form;
     * `renderForm()` mints the CSRF token the webcsrf-guarded POST needs.
     */
    public function checkinForm()
    {
        return $this->renderForm('WBS\Events\Views\checkin', [
            'events' => EventServices::events()->listForOrg($this->orgId()),
            // user_id (attendee) and staff_id (recorder) are entity references →
            // offer the org roster so both are picked, not typed as raw ids.
            'roster' => IdentityServices::accounts()->listMembers($this->orgId(), 'active'),
        ]);
    }

    /**
     * POST events/checkin — browser dispatcher for a manual check-in. Reads the
     * chosen event from the body and delegates to the same CheckinService::manual
     * path the id-scoped route uses, then Post/Redirect/Gets: success → the event
     * page; failure → re-render the form with $error and sticky $old values.
     */
    public function checkinDispatch()
    {
        $in      = $this->input();
        $eventId = (string) ($in['event_id'] ?? '');

        // group_attribution accepts a comma/space separated list from the form.
        $attr = $in['group_attribution'] ?? [];
        if (is_string($attr)) {
            $attr = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $attr) ?: [])));
        }

        $result = EventServices::checkin()->checkInManual(
            $this->orgId(),
            $eventId,
            (string) ($in['user_id'] ?? ''),
            (string) ($in['staff_id'] ?? ($this->actorId() ?? '')),
            (string) ($in['reason'] ?? ''),
            (array) $attr,
        );

        if (! $this->wantsJson()) {
            if ($result->ok && $eventId !== '') {
                return redirect()->to('/events/' . $eventId)->with('message', 'events.checkin_recorded');
            }

            return $this->renderForm('WBS\Events\Views\checkin', [
                'events' => EventServices::events()->listForOrg($this->orgId()),
                'roster' => IdentityServices::accounts()->listMembers($this->orgId(), 'active'),
                'error'  => (string) $result->message,
                'old'    => $in,
            ]);
        }

        return $this->respondWith($result);
    }
}
