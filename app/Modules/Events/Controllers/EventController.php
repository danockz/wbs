<?php

declare(strict_types=1);

namespace WBS\Events\Controllers;

use Config\Database;
use WBS\Events\Config\Services as EventServices;
use WBS\Events\Services\CalendarSettingsService;
use WBS\Groups\Config\Services as GroupServices;
use WBS\Referrals\Config\Services as ReferralServices;
use WBS\Shared\Config\Services as SharedServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Messages;
use WBS\Shared\Support\Result;

/**
 * Event lifecycle + registration endpoints (SRS FR-EVT-*).
 */
final class EventController extends BaseController
{
    /** GET events/create — render the bespoke "create event" form (issues CSRF). */
    public function createForm()
    {
        return $this->renderForm('WBS\Events\Views\create');
    }

    public function create()
    {
        $in    = $this->input();
        $orgId = $this->orgId();

        $result = EventServices::events()->create(
            $orgId,
            (string) ($this->actorId() ?? ($in['created_by'] ?? '')),
            $in,
        );

        // Browser form submits get redirected to the new event (PRG); a failed
        // create re-renders the form with the error. API clients get JSON.
        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/events/' . (string) ($result->data['id'] ?? ''));
            }

            return $this->renderForm('WBS\Events\Views\create', [
                'error' => $this->humanizeError($result),
                'old'   => $in,
            ]);
        }

        return $this->respondWith($result);
    }

    /**
     * GET events/{id}/edit — render the bespoke "edit event" form (issues CSRF),
     * pre-filled from the current event row. 404s an unknown event; a terminal
     * (cancelled/completed) event is not editable, so the form is not offered.
     */
    public function editForm(string $eventId = '')
    {
        $event = EventServices::events()->find($eventId);
        if ($event === null) {
            return $this->respondWith(Result::notFound('event.not_found', 'EVENT_NOT_FOUND'));
        }
        if (in_array((string) ($event['status'] ?? ''), ['cancelled', 'completed', 'completed_no_attendance'], true)) {
            if ($this->wantsJson()) {
                return $this->respondWith(Result::fail('BAD_STATE', 'event.bad_state', 409, ['status' => $event['status']]));
            }

            return redirect()->to('/events/' . rawurlencode($eventId))->with('error', $this->errText((string) lang('Events.editForm.terminalNote')));
        }

        return $this->renderForm('WBS\Events\Views\edit', [
            'event'    => $event,
            'event_id' => $eventId,
        ]);
    }

    /** POST events/{id} — apply an edit (PRG for browsers, JSON for API). */
    public function update(string $eventId = '')
    {
        $in     = $this->input();
        $result = EventServices::events()->update($eventId, $in);

        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/events/' . rawurlencode($eventId))->with('success', (string) lang('Events.editForm.savedFlash'));
            }

            // Re-render the form with the submitted values sticky and the error
            // shown. Fall back to the stored row for fields the user did not touch.
            $event = EventServices::events()->find($eventId);
            if ($event === null) {
                return $this->respondWith(Result::notFound('event.not_found', 'EVENT_NOT_FOUND'));
            }

            return $this->renderForm('WBS\Events\Views\edit', [
                'event'    => $event,
                'event_id' => $eventId,
                'error'    => $this->humanizeError($result),
                'old'      => $in,
            ]);
        }

        return $this->respondWith($result);
    }

    /**
     * Turn a failed Result's message into human copy for a form banner. The
     * shared EventValidator uses stable `Events.validate.*` lang keys; translate
     * them with English fallback. A non-key message (or an unresolved key) is
     * shown verbatim so nothing is ever swallowed.
     */
    private function humanizeError(Result $result): string
    {
        // FR-ARC-002 sweep: delegate to the central humanizer — translates
        // catalog keys via lang() and humanizes bare machine keys/codes, so a
        // browser flash NEVER renders `event.not_found`-style raw keys. (Method
        // name kept: locked by event_edit_validation_test / lifecycle guards.)
        return Messages::humanize((string) $result->message);
    }

    public function index()
    {
        $orgId = $this->orgId();
        // L4 — ?archived=archived shows the archive; default 'active' hides it.
        // Any other value ('all') falls through to "everything" in the service.
        $archived = (string) $this->field('archived', 'active');
        if (! in_array($archived, ['active', 'archived', 'all'], true)) {
            $archived = 'active';
        }
        $events = EventServices::events()->listForOrg($orgId, (int) $this->field('limit', 50), $archived);

        return $this->respondWith(
            Result::ok(['events' => $events, 'count' => count($events), 'archived' => $archived]),
            htmlView: 'WBS\Events\Views\index',
            viewData: ['result' => ['events' => $events], 'title' => 'Events', 'archived' => $archived],
        );
    }

    /**
     * GET events/analytics — cross-event organizer dashboard (programme birds-eye:
     * totals, RSVP→check-in conversion, fill rates, revenue, upcoming/filling).
     * Browsers get the bespoke view; API clients get the JSON payload. Optional
     * ?group_id= narrows to one organizing group. A pure, scoped read.
     */
    public function analytics()
    {
        $groupId = $this->field('group_id');
        $groupId = $groupId !== null && $groupId !== '' ? (string) $groupId : null;

        $res  = EventServices::eventAnalytics()->dashboard($this->orgId(), $groupId);
        $data = is_array($res->data) ? $res->data : [];

        return $this->respondWith(
            $res,
            htmlView: 'WBS\Events\Views\analytics',
            viewData: [
                'group_id'  => $data['group_id'] ?? $groupId,
                'now'       => (string) ($data['now'] ?? ''),
                'totals'    => $data['totals'] ?? [],
                'people'    => $data['people'] ?? [],
                'revenue'   => $data['revenue'] ?? [],
                'by_mode'   => $data['by_mode'] ?? [],
                'by_status' => $data['by_status'] ?? [],
                'upcoming'  => $data['upcoming'] ?? [],
                'filling'   => $data['filling'] ?? [],
            ],
        );
    }

    /**
     * GET events/calendar — a month grid. Signed-in default looks UP the
     * hierarchy (own group + higher-group events that apply because the current
     * group is a descendant), never down at children. ?month=YYYY-MM selects the
     * month; ?group_id= pins one current group (still plus its ancestors).
     */
    public function calendar()
    {
        $scope = $this->calendarQueryScope();

        // Resolve the month window (UTC), defaulting to the current month.
        $monthIn = (string) ($this->field('month', ''));
        if (preg_match('/^(\d{4})-(\d{2})$/', $monthIn, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            $year  = (int) $m[1];
            $month = (int) $m[2];
        } else {
            $year  = (int) gmdate('Y');
            $month = (int) gmdate('n');
        }
        $first    = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $nextY    = $month === 12 ? $year + 1 : $year;
        $nextM    = $month === 12 ? 1 : $month + 1;
        $next     = sprintf('%04d-%02d-01 00:00:00', $nextY, $nextM);

        $events = $this->calendarEventsInRange($first, $next, $scope);

        return $this->respondWith(
            Result::ok(['events' => $events, 'year' => $year, 'month' => $month]),
            htmlView: 'WBS\Events\Views\calendar',
            viewData: [
                'group_id'      => $scope['selected'],
                'year'          => $year,
                'month'         => $month,
                'events'        => $events,
                'picker'        => $scope['picker'],
                'settings'      => $scope['settings'],
                'can_configure' => $scope['can_configure'],
            ],
        );
    }

    /**
     * GET events/calendar.ics — an iCalendar SUBSCRIPTION feed of the org's
     * PUBLISHED, upcoming events, for Google/Apple/Outlook "add calendar by URL".
     * ?group_id= narrows to one organizing group. Returns text/calendar (never a
     * bespoke view / JSON): calendar clients fetch it directly. Resource-light:
     * ONE bounded, forward-looking read; pure RFC-5545 formatting off the hot
     * path. Cache-safe headers let clients honour their own refresh cadence.
     */
    public function calendarFeed()
    {
        $scope = $this->calendarQueryScope();

        // Forward-looking window: from the start of the current UTC day, so a
        // subscriber sees today's remaining events plus everything upcoming.
        $from   = gmdate('Y-m-d') . ' 00:00:00';
        $events = EventServices::events()->feedInRange(
            $this->orgId(),
            $from,
            $scope['legacy_group'],
            500,
            $scope['group_ids'],
            $scope['kinds'],
        );

        $calName = (string) ($scope['settings']['display_label'] ?? '');
        if ($calName === '' && function_exists('lang')) {
            $t = lang('Events.calendar.title');
            if (is_string($t) && $t !== '' && $t !== 'Events.calendar.title') {
                $calName = $t;
            }
        }
        if ($calName === '') {
            $calName = 'Events';
        }

        $baseUrl = rtrim((string) base_url(), '/');
        $host    = (string) (parse_url($baseUrl, PHP_URL_HOST) ?: 'events.local');
        $ics     = EventServices::eventIcsFeed()->build($events, $calName, $host, gmdate('Y-m-d H:i:s'), $baseUrl);

        return $this->cacheSafe()
            ->setHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->setHeader('Content-Disposition', 'inline; filename="events.ics"')
            ->setBody($ics);
    }

    /**
     * GET events/mine — the signed-in member's own events hub (registrations,
     * attendance, certificates, tickets), split upcoming vs past. Auth-guarded;
     * always scoped to the SESSION user (never a body param). Browsers get the
     * bespoke hub; API clients get the JSON payload.
     */
    public function mine()
    {
        $userId = $this->currentUserId();
        if ($userId === '') {
            return $this->respondWith(Result::fail('AUTH_REQUIRED', 'event.auth_required', 401));
        }

        $res  = EventServices::eventRegistrations()->myEvents($this->orgId(), $userId);
        $data = is_array($res->data) ? $res->data : [];

        // Personal, cookie-less iCalendar subscription URL for this member (a
        // signed token stands in for the session so calendar clients can fetch).
        $token   = EventServices::eventFeedToken()->issue($userId);
        $feedUrl = rtrim((string) base_url(), '/') . '/events/mine.ics?token=' . rawurlencode($token);

        return $this->respondWith(
            $res,
            htmlView: 'WBS\Events\Views\my_events',
            viewData: [
                'user_id'  => (string) ($data['user_id'] ?? $userId),
                'counts'   => $data['counts'] ?? [],
                'upcoming' => $data['upcoming'] ?? [],
                'past'     => $data['past'] ?? [],
                'feed_url' => $feedUrl,
            ],
        );
    }

    /**
     * GET events/mine.ics?token=… — a PERSONAL iCalendar subscription feed of the
     * signed-in member's upcoming events. Cookie-less: the ?token= (minted by
     * FeedTokenService for that user) authorizes read-only access to their own
     * feed, so calendar clients can subscribe without a session. Returns
     * text/calendar. Resource-light: reuses the batched myEvents read + the pure
     * ICS builder. A bad/absent token yields 403 (never leaks another user's data).
     */
    public function mineFeed()
    {
        $token  = (string) ($this->field('token', ''));
        $userId = $token === '' ? null : EventServices::eventFeedToken()->verify($token);
        if ($userId === null || $userId === '') {
            return $this->response->setStatusCode(403)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setBody('Invalid or missing feed token.');
        }

        $res  = EventServices::eventRegistrations()->myEvents($this->orgId(), $userId);
        $data = is_array($res->data) ? $res->data : [];
        // Only future-facing events belong in a subscription; myEvents already
        // splits them, so the personal calendar mirrors "upcoming".
        $events = is_array($data['upcoming'] ?? null) ? $data['upcoming'] : [];

        $calName = 'My events';
        if (function_exists('lang')) {
            $t = lang('Events.myEvents.title');
            if (is_string($t) && $t !== '' && $t !== 'Events.myEvents.title') {
                $calName = $t;
            }
        }

        $baseUrl = rtrim((string) base_url(), '/');
        $host    = (string) (parse_url($baseUrl, PHP_URL_HOST) ?: 'events.local');
        $ics     = EventServices::eventIcsFeed()->build($events, $calName, $host, gmdate('Y-m-d H:i:s'), $baseUrl);

        return $this->cacheSafe()
            ->setHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->setHeader('Content-Disposition', 'inline; filename="my-events.ics"')
            ->setBody($ics);
    }

    public function show(string $eventId = '')
    {
        $event = EventServices::events()->find($eventId);
        if ($event === null) {
            return $this->respondWith(Result::notFound('event.not_found', 'EVENT_NOT_FOUND'));
        }

        // Attendee-facing: the viewer's own RSVP drives the register/cancel panel.
        // API clients keep the flat event payload they had (back-compatible).
        $userId = $this->currentUserId();

        // Publish-readiness checklist (G6) — only meaningful while still a draft;
        // a pure assessment surfaced on the page so an organizer sees blockers
        // (why publish would refuse) and soft warnings before clicking publish.
        $readiness = null;
        if ((string) ($event['status'] ?? '') === 'draft') {
            $rr        = EventServices::events()->readiness($eventId);
            $readiness = is_array($rr->data) ? $rr->data : null;
        }

        return $this->respondWith(
            Result::ok($event),
            htmlView: 'WBS\Events\Views\show',
            viewData: [
                'result'       => $event,
                'title'        => (string) ($event['title'] ?? 'Event'),
                'registration' => $userId === '' ? null : EventServices::eventRegistrations()->registrationFor($eventId, $userId),
                'user_id'      => $userId,
                'csrf'         => (string) ($this->request->wbsCsrf ?? ''),
                'readiness'    => $readiness,
            ],
        );
    }

    /**
     * GET events/{id}/event.ics — a single-event "Add to calendar" download for
     * the event page. Reuses the pure ICS builder over the one event row; no
     * token needed (it mirrors the event page's own visibility — a 404 event is
     * a 404 here too). Returns text/calendar as an attachment so a click adds the
     * one event to the user's calendar. Resource-light: ONE row read.
     */
    public function eventFeed(string $eventId = '')
    {
        $event = EventServices::events()->find($eventId);
        if ($event === null) {
            return $this->response->setStatusCode(404)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setBody('Event not found.');
        }

        $calName = (string) ($event['title'] ?? 'Event');
        $baseUrl = rtrim((string) base_url(), '/');
        $host    = (string) (parse_url($baseUrl, PHP_URL_HOST) ?: 'events.local');
        $ics     = EventServices::eventIcsFeed()->build([$event], $calName, $host, gmdate('Y-m-d H:i:s'), $baseUrl);

        return $this->cacheSafe()
            ->setHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="event.ics"')
            ->setBody($ics);
    }

    public function publish(string $eventId = '')
    {
        return $this->respondLifecycle(EventServices::events()->publish($eventId), $eventId, 'publishedFlash');
    }

    public function complete(string $eventId = '')
    {
        return $this->respondLifecycle(EventServices::events()->complete($eventId), $eventId, 'completedFlash');
    }

    /**
     * POST events/{id}/cancel — cancel an event (gap G7). Guarded + auditable:
     * the service refuses a non-draft/published event and requires a reason; the
     * cancelling actor is recorded. Browsers PRG back to the event page; API
     * clients get JSON.
     */
    public function cancel(string $eventId = '')
    {
        $reason = (string) ($this->input()['reason'] ?? '');
        $result = EventServices::events()->cancel($eventId, $reason, $this->actorId());

        return $this->respondLifecycle($result, $eventId, 'cancelledFlash');
    }

    /**
     * POST events/{id}/archive — file a settled event away (gap L4). The service
     * refuses a live (published) event and is idempotent; the archiving actor is
     * recorded. Browsers PRG back to the event page; API clients get JSON.
     */
    public function archive(string $eventId = '')
    {
        $result = EventServices::events()->archive($eventId, $this->actorId());

        return $this->respondLifecycle($result, $eventId, 'archivedFlash');
    }

    /** POST events/{id}/unarchive — restore an archived event to active lists (gap L4). */
    public function unarchive(string $eventId = '')
    {
        $result = EventServices::events()->unarchive($eventId);

        return $this->respondLifecycle($result, $eventId, 'unarchivedFlash');
    }

    /**
     * PRG for a browser event-lifecycle write (publish / complete / cancel): API
     * clients keep the raw Result (JSON); browsers redirect back to the event page
     * with a localized success flash, or the failing Result's message (translated
     * with English fallback) as an error flash. The controls live on the event
     * show page.
     */
    private function respondLifecycle(Result $result, string $eventId, string $okKey)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $to = $eventId !== '' ? '/events/' . rawurlencode($eventId) : '/events';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->humanizeError($result));
        }

        return redirect()->to($to)->with('success', lang('Events.lifecycle.' . $okKey));
    }

    public function expectedAttendance(string $eventId = '')
    {
        $result = EventServices::events()->expectedAttendance(
            $eventId,
            (float) $this->field('show_rate', 0.6),
        );

        return $this->respondWith(
            $result,
            htmlView: 'WBS\Events\Views\attendance',
            viewData: ['result' => $result->data, 'title' => 'Expected attendance'],
        );
    }

    /**
     * GET events/{id}/invitations — organizer invitations console for an
     * invite-only event (gap G5). Lists issued invitations and offers the issue
     * form. Authorized by the route (`authorize:event.create,any`).
     */
    public function invitations(string $eventId = '')
    {
        $event = EventServices::events()->find($eventId);
        if ($event === null) {
            return $this->respondWith(Result::notFound('event.not_found', 'EVENT_NOT_FOUND'));
        }
        $svc  = EventServices::eventInvitations();
        $list = $svc->listForEvent($this->orgId(), $eventId);
        $link = $svc->getLink($this->orgId(), $eventId);

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok([
                'event_id'    => $eventId,
                'invitations' => $list,
                'link'        => $this->linkPublicView($eventId, $link),
            ]));
        }

        return $this->respondWith(
            Result::ok($event),
            htmlView: 'WBS\\Events\\Views\\invitations',
            viewData: [
                'event'       => $event,
                'event_id'    => $eventId,
                'invitations' => $list,
                'link'        => $this->linkPublicView($eventId, $link),
                'title'       => (string) lang('Events.invite.heading'),
                'csrf'        => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /**
     * POST events/{id}/invitations — issue a DIRECT invitation (user/email/phone).
     * An email/phone invite is ALSO delivered over Notifications carrying the
     * event's shareable link, so the invitee receives the same cloaked URL that
     * is broadcast on social media.
     */
    public function invite(string $eventId = '')
    {
        $in     = $this->input();
        $orgId  = $this->orgId();
        $result = EventServices::eventInvitations()->issue($orgId, $eventId, [
            'channel'         => $in['channel'] ?? 'user',
            'invitee_user_id' => $in['invitee_user_id'] ?? null,
            'invitee_email'   => $in['invitee_email'] ?? null,
            'invitee_phone'   => $in['invitee_phone'] ?? null,
        ], $this->actorId());

        // Deliver the shareable link to a direct email/phone invitee (best-effort;
        // an un-generated link simply means no link is enclosed yet).
        if ($result->ok) {
            $this->deliverDirectInvite($orgId, $eventId, (array) $result->data);
        }

        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $to = '/events/' . rawurlencode($eventId) . '/invitations';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->humanizeError($result));
        }

        return redirect()->to($to)->with('success', (string) lang('Events.invite.invitedFlash'));
    }

    /**
     * POST events/{id}/invitations/link — generate / reconfigure the ONE
     * shareable, cloaked invite link (broadcast on social media + enclosed in
     * direct email/SMS invites). Mode bounds its reach:
     * expiry | max_redemptions | capacity. `rotate` mints a fresh token.
     */
    public function generateInviteLink(string $eventId = '')
    {
        $in     = $this->input();
        $result = EventServices::eventInvitations()->generateLink($this->orgId(), $eventId, [
            'mode'            => $in['mode'] ?? 'expiry',
            'expires_at'      => $in['expires_at'] ?? null,
            'max_redemptions' => $in['max_redemptions'] ?? null,
            'rotate'          => ! empty($in['rotate']),
        ], $this->actorId());

        if ($this->wantsJson()) {
            if (! $result->ok) {
                return $this->respondWith($result);
            }

            return $this->respondWith(Result::ok($this->linkPublicView($eventId, (array) $result->data)));
        }

        $to = '/events/' . rawurlencode($eventId) . '/invitations';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->humanizeError($result));
        }
        $view = $this->linkPublicView($eventId, (array) $result->data);

        return redirect()->to($to)->with('success', (string) lang('Events.invite.linkReadyFlash') . ' ' . (string) ($view['url'] ?? ''));
    }

    /** POST events/{id}/invitations/link/toggle — enable/disable the link. */
    public function toggleInviteLink(string $eventId = '')
    {
        $in     = $this->input();
        $active = ! empty($in['active']);
        $result = EventServices::eventInvitations()->setLinkActive($this->orgId(), $eventId, $active);

        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $to = '/events/' . rawurlencode($eventId) . '/invitations';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->humanizeError($result));
        }

        return redirect()->to($to)->with('success', (string) lang('Events.invite.' . ($active ? 'linkEnabledFlash' : 'linkDisabledFlash')));
    }

    /**
     * Shape a link row for the browser/API: builds the absolute shareable URL
     * from the cloaked token and hides the raw hash. Returns null when no link
     * exists yet.
     *
     * @param array<string,mixed>|null $link
     * @return array<string,mixed>|null
     */
    private function linkPublicView(string $eventId, ?array $link): ?array
    {
        if ($link === null || $link === []) {
            return null;
        }
        $token = (string) ($link['token'] ?? $link['url_token'] ?? '');
        $base  = rtrim((string) base_url(), '/');

        return [
            'mode'            => (string) ($link['mode'] ?? 'expiry'),
            'active'          => (bool) ($link['active'] ?? true),
            'expires_at'      => $link['expires_at'] ?? null,
            'max_redemptions' => $link['max_redemptions'] ?? null,
            'redeemed_count'  => (int) ($link['redeemed_count'] ?? 0),
            'url'             => $token !== '' ? $base . '/events/' . rawurlencode($eventId) . '?invite=' . rawurlencode($token) : '',
        ];
    }

    /**
     * Deliver a direct email/phone invitation over Notifications, enclosing the
     * event's shareable invite link. Best-effort: matches the invitee to a known
     * user (so notification preferences/suppression apply); if no account exists
     * yet the invite row still stands and the person can register as a guest via
     * the link. Marks the invitation notified.
     *
     * @param array<string,mixed> $invite issue() result data
     */
    private function deliverDirectInvite(string $orgId, string $eventId, array $invite): void
    {
        $channel = (string) ($invite['channel'] ?? '');
        if (! in_array($channel, ['email', 'phone'], true)) {
            return; // a `user` invite reaches an in-app recipient via other paths
        }
        $svc  = EventServices::eventInvitations();
        $view = $this->linkPublicView($eventId, $svc->getLink($orgId, $eventId));
        $url  = (string) ($view['url'] ?? '');

        // Resolve the invitee to a known user, if any (notification prefs apply).
        $value  = (string) ($invite['invitee_' . $channel] ?? '');
        $userId = $svc->resolveInviteeUserId($channel, $value);
        if ($userId !== '') {
            EventServices::eventInviteSender()->send(
                $orgId,
                $userId,
                $channel === 'email' ? 'email' : 'sms',
                'event',
                [
                    'priority'   => 'normal',
                    'dedupe_key' => 'event.invite:' . $eventId . ':' . $userId,
                    'context'    => ['event_id' => $eventId, 'invite_url' => $url],
                ],
            );
        }

        if (isset($invite['id'])) {
            $svc->markNotified((string) $invite['id']);
        }
    }

    /** POST events/{id}/invitations/(:segment)/revoke — revoke an invitation. */
    public function revokeInvite(string $eventId = '', string $invitationId = '')
    {
        $result = EventServices::eventInvitations()->revoke($this->orgId(), $invitationId);

        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $to = '/events/' . rawurlencode($eventId) . '/invitations';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->humanizeError($result));
        }

        return redirect()->to($to)->with('success', (string) lang('Events.invite.revokedFlash'));
    }

    public function register(string $eventId = '')
    {
        $in    = $this->input();
        $orgId = $this->orgId();
        // A browser attendee registers THEMSELVES (session identity); an API
        // caller may still name any user_id in the body.
        $userId = $this->currentUserId('user_id');
        $rsvp   = (string) ($in['rsvp_state'] ?? 'yes');
        $rsvp   = in_array($rsvp, ['yes', 'no', 'maybe'], true) ? $rsvp : 'yes';

        $result = EventServices::eventRegistrations()->register(
            $orgId,
            $eventId,
            $userId,
            [
                'group_attribution' => $in['group_attribution'] ?? null,
                'source_ref'        => $in['source_ref'] ?? null,
                'rsvp_state'        => $rsvp,
                // Invite-only events (gap G5): a self-registering attendee may
                // present a link token (from their invite URL) or rely on group
                // membership / a direct invite matched to their identity. This is
                // NOT an assisted registration — the route is plain self-service.
                'invite_token'      => (string) ($in['invite_token'] ?? $this->request->getGet('invite') ?? ''),
            ],
        );

        return $this->respondRegistration($result, $eventId, 'registeredFlash');
    }

    /**
     * POST events/{id}/register-guest — a NOT-signed-in visitor who opened the
     * shareable invite link registers by submitting contact details (gap G5).
     * The visitor is captured as a PROSPECT owned by the link's creator (the
     * sponsor) and registered through the assisted follow-up path. Requires a
     * valid, usable invite link token; otherwise fails closed.
     */
    public function registerGuest(string $eventId = '')
    {
        $in    = $this->input();
        $orgId = $this->orgId();
        $token = (string) ($in['invite_token'] ?? $this->request->getGet('invite') ?? '');

        $event = EventServices::events()->find($eventId);
        if ($event === null) {
            return $this->respondRegistration(Result::notFound('event.not_found', 'EVENT_NOT_FOUND'), $eventId, 'registeredFlash');
        }

        // The shareable link both authorizes the guest AND names the sponsor.
        $link = EventServices::eventInvitations()->getLink($orgId, $eventId);
        $elig = EventServices::eventInvitations()->eligibility(
            (array) $event,
            '', // no user yet
            ['invite_token' => $token],
        );
        if (! $elig->ok || (string) ($elig->data['reason'] ?? '') !== 'invite_link') {
            return $this->respondRegistration(
                Result::fail('INVITE_REQUIRED', 'Events.invite.errInviteRequired', 403),
                $eventId,
                'registeredFlash',
            );
        }

        $sponsorId = (string) ($link['created_by'] ?? '');
        $result    = ReferralServices::contactBook()->captureGuestFromInvite($orgId, $eventId, $sponsorId, [
            'full_name'         => $in['full_name'] ?? '',
            'email'             => $in['email'] ?? null,
            'phone'             => $in['phone'] ?? null,
            'group_attribution' => $in['group_attribution'] ?? null,
            'rsvp_state'        => $in['rsvp_state'] ?? 'yes',
            'consent'           => ! empty($in['consent']), // field-sync: every capture path writes consent
            // Optional integration-decision input (group `capture_inputs`);
            // guest-filled -> self flavor, born pending (onboarding decision).
            'decision_type'     => trim((string) ($in['decision_type'] ?? '')),
            'decision_date'     => trim((string) ($in['decision_date'] ?? '')),
            'decision_note'     => $in['decision_note'] ?? null,
        ]);

        // Count the link redemption once the guest is on the roster.
        if ($result->ok) {
            EventServices::eventInvitations()->consumeFor($eventId, '', 'invite_link', ['invite_token' => $token]);
        }

        return $this->respondRegistration($result, $eventId, 'registeredFlash');
    }

    /** Attendee self-cancels their registration (waitlist auto-promotes). */
    public function cancelRegistration(string $eventId = '')
    {
        $userId = $this->currentUserId('user_id');
        $result = EventServices::eventRegistrations()->cancel($eventId, $userId);

        return $this->respondRegistration($result, $eventId, 'cancelledFlash');
    }

    /**
     * PRG for a browser attendee write (register / cancel RSVP): API clients keep
     * the raw Result (JSON); a browser redirects back to the event page with a
     * localized success flash, or the failing Result's message as an error flash.
     */

    /**
     * GET events/calendar/settings — per-group calendar settings editor.
     * Gated by event.create on the selected group.
     */
    public function calendarSettings()
    {
        $scope = $this->calendarQueryScope();
        $selected = (string) ($scope['selected'] ?? '');
        if ($selected === '' && $scope['picker'] !== []) {
            $selected = (string) ($scope['picker'][0]['id'] ?? '');
        }
        if ($selected === '' || ! $this->canManageGroupScope('event.create', $selected)) {
            return $this->respondWith(Result::fail('FORBIDDEN', 'calendar.settings_forbidden', 403));
        }
        $cal  = $this->calSettings();
        $own  = $cal->findOwn($this->orgId(), $selected);
        $res  = $cal->resolve($this->orgId(), $selected);

        return $this->renderForm('WBS\\Events\\Views\\calendar_settings', [
            'group_id' => $selected,
            'picker'   => $scope['picker'],
            'own'      => $own,
            'resolved' => $res,
        ]);
    }

    public function saveCalendarSettings()
    {
        $groupId = trim((string) ($this->field('group_id') ?? ''));
        if ($groupId === '' || ! $this->canManageGroupScope('event.create', $groupId)) {
            $fail = Result::fail('FORBIDDEN', 'calendar.settings_forbidden', 403);
            if ($this->wantsJson()) {
                return $this->respondWith($fail);
            }

            return redirect()->to('/events/calendar/settings')->with('error', $this->errText((string) $fail->message));
        }
        $result = $this->calSettings()->upsert($this->orgId(), $groupId, $this->input(), $this->actorId());
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }
        $to = '/events/calendar/settings?group_id=' . rawurlencode($groupId);
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Events.calendar.settingsSavedFlash'));
    }

    /**
     * Month-window events for a resolved calendar scope. Own-group rows keep
     * every live status (a leader sees their drafts); HIGHER-group rows are
     * published-only — they apply downward only once the higher-group leader
     * has permitted them by publishing.
     *
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private function calendarEventsInRange(string $fromUtc, string $toUtc, array $scope): array
    {
        $svc   = EventServices::events();
        $org   = $this->orgId();
        $kinds = $scope['kinds'] ?? null;
        $own   = $scope['own_ids'] ?? null;
        $higher = $scope['higher_ids'] ?? null;

        if ($own === null && ($scope['group_ids'] ?? null) === null) {
            return $svc->listInRange($org, $fromUtc, $toUtc, $scope['legacy_group'] ?? null);
        }

        $rows = $svc->listInRange($org, $fromUtc, $toUtc, null, is_array($own) ? $own : [], $kinds);
        if (is_array($higher) && $higher !== []) {
            $up = $svc->listInRange($org, $fromUtc, $toUtc, null, $higher, $kinds, true);
            $seen = [];
            foreach ($rows as $r) {
                $seen[(string) ($r['id'] ?? '')] = true;
            }
            foreach ($up as $r) {
                $id = (string) ($r['id'] ?? '');
                if ($id !== '' && ! isset($seen[$id])) {
                    $rows[] = $r;
                    $seen[$id] = true;
                }
            }
            usort($rows, static fn ($a, $b) => ((string) ($a['starts_at'] ?? '')) <=> ((string) ($b['starts_at'] ?? '')));
        }

        return $rows;
    }

    /**
     * Never go through CI4 getSharedInstance('calendarSettings') — it returns
     * null on this request path. Construct if the factory is missing or null.
     */
    private function calSettings(): CalendarSettingsService
    {
        $svc = EventServices::calendarSettings(false);
        if ($svc instanceof CalendarSettingsService) {
            return $svc;
        }

        return new CalendarSettingsService(Database::connect(), SharedServices::clock());
    }

    /**
     * Hierarchical calendar query. Signed-in default looks UP: membership
     * groups plus groups the actor leads (`event.create`), then each ancestor
     * so higher-group events that apply to the current group are included.
     * Descendants are never pulled in. Anonymous visitors keep the historical
     * org-wide feed; `?group_id=` on a public view still looks UP from that group.
     *
     * @return array{selected:?string,legacy_group:?string,group_ids:?list<string>,own_ids:?list<string>,higher_ids:?list<string>,kinds:?list<string>,settings:array,picker:list<array>,can_configure:bool}
     */
    private function calendarQueryScope(): array
    {
        $cal = $this->calSettings();
        $org = $this->orgId();
        $requested = trim((string) ($this->field('group_id') ?? ''));
        $requested = $requested !== '' ? $requested : null;
        $actor = $this->currentUserId();

        $emptySettings = [
            'group_id' => $requested, 'display_label' => null, 'timezone' => null,
            'visible_kinds' => [], 'inherited_from' => null,
        ];

        if ($actor === '') {
            $groupIds = null;
            $own = null;
            $higher = null;
            $settings = $emptySettings;
            if ($requested !== null) {
                $own    = [$requested];
                $chain  = $cal->expandAncestors([$requested]);
                $higher = array_values(array_filter($chain, static fn ($id) => $id !== $requested));
                $groupIds = $chain;
                $settings = $cal->resolve($org, $requested);
            }

            return [
                'selected'      => $requested,
                'legacy_group'  => $groupIds === null ? $requested : null,
                'group_ids'     => $groupIds,
                'own_ids'       => $own,
                'higher_ids'    => $higher,
                'kinds'         => ($settings['visible_kinds'] ?? []) !== [] ? $settings['visible_kinds'] : null,
                'settings'      => $settings,
                'picker'        => [],
                'can_configure' => false,
            ];
        }

        $seeds = $cal->membershipGroupIds($org, $actor);
        $picker = [];
        $seen = [];
        foreach ($seeds as $id) {
            $seen[$id] = true;
            $picker[] = ['id' => $id, 'name' => $id];
        }
        // Groups this actor leads (event.create) — the calendar is a leader
        // surface; membership alone would hide a body they are permitted to run.
        try {
            foreach (GroupServices::groups()->listForOrg($org, 2000) as $g) {
                $id = (string) ($g['id'] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                if ($this->canManageGroupScope('event.create', $id)) {
                    $seen[$id] = true;
                    $seeds[] = $id;
                    $picker[] = ['id' => $id, 'name' => (string) ($g['name'] ?? $id)];
                }
            }
        } catch (\Throwable) {
            // Composition root not booted (standalone tests) — membership seeds stand.
        }

        $selected = $requested;
        if ($selected !== null && $seeds !== [] && ! in_array($selected, $seeds, true)) {
            $selected = null;
        }
        if ($selected !== null) {
            $own     = [$selected];
            $settings = $cal->resolve($org, $selected);
        } else {
            $own      = $seeds;
            $settings = $emptySettings;
        }
        $chain  = $cal->expandAncestors($own);
        $higher = array_values(array_diff($chain, $own));
        $can    = $selected !== null && $this->canManageGroupScope('event.create', $selected);

        return [
            'selected'      => $selected,
            'legacy_group'  => null,
            'group_ids'     => $chain,
            'own_ids'       => $own,
            'higher_ids'    => $higher,
            'kinds'         => ($settings['visible_kinds'] ?? []) !== [] ? $settings['visible_kinds'] : null,
            'settings'      => $settings,
            'picker'        => $picker,
            'can_configure' => $can,
        ];
    }

    private function respondRegistration(Result $result, string $eventId, string $okKey)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }
        $to = '/events/' . rawurlencode($eventId);
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->humanizeError($result));
        }

        return redirect()->to($to)->with('success', (string) lang('Events.rsvp.' . $okKey));
    }
}
