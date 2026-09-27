<?php

declare(strict_types=1);

namespace WBS\Referrals\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Referrals\Config\Services as ReferralServices;
use WBS\Referrals\Services\ContactBookService;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Member/staff ADDRESS BOOK & outreach (dashboard). A member sees a birds-eye
 * view of their downline — contacts at every journey stage and follow-up
 * temperature — can add contacts (with consent-gated GPS tagging), record dated
 * decisions, log follow-ups, and (staff) bulk-add on behalf of assigned groups.
 *
 * All routes sit behind the `auth` filter. HTML for browsers; JSON for API.
 * Ownership is enforced in the service: a member can only touch contacts they own.
 */
final class ContactBookController extends BaseController
{
    private function svc(): ContactBookService
    {
        return ReferralServices::contactBook();
    }

    /** Address-book landing: birds-eye summary + filtered list. */
    public function index(): ResponseInterface
    {
        $orgId  = $this->orgId();
        $userId = $this->currentUserId();

        $filters = array_filter([
            'temperature'   => $this->field('temperature'),
            'journey_stage' => $this->field('stage'),
            'group_id'      => $this->field('group_id'),
            'due'           => $this->field('due') ? true : null,
        ], static fn ($v) => $v !== null && $v !== '');

        $book            = $this->svc();
        $contacts        = $book->listForOwner($orgId, $userId, $filters);
        $summary         = $book->summaryForOwner($orgId, $userId);
        $placementGroup  = ReferralServices::prospectGroups()->placementFor($orgId, $userId, null);
        // Fail-closed: never call IntegrationService through the CI4 shared
        // locator (it returns null during accounts→journey construction).
        $decisionInputs  = $book->captureInputsFor($placementGroup);

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok(['summary' => $summary, 'contacts' => $contacts]));
        }

        // Dashboard forms POST to webcsrf-guarded routes, so mint a CSRF token and
        // set the matching wbs_csrf cookie (double-submit) on this page response.
        $csrf = $this->issueCsrf();
        // target_id on the attend/register form is an entity reference whose kind
        // depends on the "type" toggle → offer events + courses so it's picked.
        $events  = \WBS\Events\Config\Services::events()->listForOrg($orgId);
        $courses = \WBS\Courses\Config\Services::courses()->listForOrg($orgId);
        $body = view('WBS\Referrals\Views\contacts_index', [
            'summary'  => $summary,
            'contacts' => $contacts,
            'filters'  => $filters,
            'csrf'     => $csrf,
            'temps'    => ContactBookService::TEMPERATURES,
            'stages'   => self::STAGES,
            'decisions'=> ContactBookService::DECISION_TYPES,
            'decisionInputs' => $decisionInputs,
            'events'   => $events,
            'courses'  => $courses,
        ]);

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($body)
            ->setCookie($this->csrfCookie($csrf));
    }

    /** Journey stage vocabulary surfaced in the dashboard filters/forms. */
    private const STAGES = ['prospect', 'first_timer', 'new_believer', 'in_foundation', 'established', 'worker', 'leader', 'sender'];

    /** Create a contact (member address book). */
    public function create(): ResponseInterface
    {
        $orgId  = $this->orgId();
        $userId = $this->currentUserId();
        $in     = $this->input();

        $res = $this->svc()->createContact($orgId, [
            'owner_user_id'            => $userId,
            'created_by'               => $userId,
            'source'                   => 'member',
            'full_name'                => $in['full_name'] ?? '',
            'phone'                    => $in['phone'] ?? null,
            'email'                    => $in['email'] ?? null,
            'notes'                    => $in['notes'] ?? null,
            'assigned_group_id'        => $in['assigned_group_id'] ?? null,
            'journey_stage'            => $in['journey_stage'] ?? 'prospect',
            'next_follow_up_at'        => $in['next_follow_up_at'] ?? null,
            'invite_context_type'      => $in['invite_context_type'] ?? null,
            'invite_context_id'        => $in['invite_context_id'] ?? null,
            'consent'                  => ! empty($in['consent']),
            // Optional integration-decision input (group `capture_inputs`).
            'decision_type'            => trim((string) ($in['decision_type'] ?? '')),
            'decision_date'            => trim((string) ($in['decision_date'] ?? '')),
            'decision_note'            => $in['decision_note'] ?? null,
            // Consent-gated GPS: BOTH the prospect's verbal agreement AND the
            // member's checkbox confirmation are required before coords persist.
            'latitude'                 => $in['latitude'] ?? null,
            'longitude'                => $in['longitude'] ?? null,
            'coords_consent_verbal'    => ! empty($in['coords_consent_verbal']),
            'coords_consent_confirmed' => ! empty($in['coords_consent_confirmed']),
        ]);

        if ($this->wantsJson()) {
            return $this->respondWith($res);
        }

        // FR-ARC-002 sweep: a browser failure must FLASH — silent PRG hid errors.
        if (! $res->ok) {
            return redirect()->to('/me/contacts')->with('error', $this->errText((string) $res->message));
        }

        return redirect()->to('/me/contacts');
    }

    /** Log a follow-up touch. */
    public function followUp(string $contactId = ''): ResponseInterface
    {
        $userId = $this->currentUserId();
        $in     = $this->input();

        $res = $this->svc()->recordFollowUp($contactId, $userId, [
            'temperature'       => $in['temperature'] ?? null,
            'next_follow_up_at' => $in['next_follow_up_at'] ?? null,
            'notes'             => $in['notes'] ?? null,
        ]);

        if ($this->wantsJson()) {
            return $this->respondWith($res);
        }

        // FR-ARC-002 sweep: a browser failure must FLASH — silent PRG hid errors.
        if (! $res->ok) {
            return redirect()->to('/me/contacts')->with('error', $this->errText((string) $res->message));
        }

        return redirect()->to('/me/contacts');
    }

    /** Record a dated decision (one type is join_group). */
    public function decide(string $contactId = ''): ResponseInterface
    {
        $userId = $this->currentUserId();
        $in     = $this->input();

        $res = $this->svc()->recordDecision($contactId, $userId, [
            'decision_type'   => $in['decision_type'] ?? '',
            'decision_date'   => $in['decision_date'] ?? '',
            'target_group_id' => $in['target_group_id'] ?? null,
            'note'            => $in['note'] ?? null,
        ]);

        if ($this->wantsJson()) {
            return $this->respondWith($res);
        }

        // FR-ARC-002 sweep: a browser failure must FLASH — silent PRG hid errors.
        if (! $res->ok) {
            return redirect()->to('/me/contacts')->with('error', $this->errText((string) $res->message));
        }

        return redirect()->to('/me/contacts');
    }

    /**
     * Register a contact for an event or course as part of a follow-up.
     * body: type=event|course, target_id, [rsvp_state], [status], [group_attribution].
     */
    public function attend(string $contactId = ''): ResponseInterface
    {
        $userId = $this->currentUserId();
        $in     = $this->input();

        $res = $this->svc()->recordAttendance($contactId, $userId, [
            'type'              => $in['type'] ?? '',
            'target_id'         => $in['target_id'] ?? '',
            'rsvp_state'        => $in['rsvp_state'] ?? null,
            'status'            => $in['status'] ?? null,
            'group_attribution' => $in['group_attribution'] ?? null,
            'cohort_id'         => $in['cohort_id'] ?? null,
        ]);

        if ($this->wantsJson()) {
            return $this->respondWith($res);
        }

        // FR-ARC-002 sweep: a browser failure must FLASH — silent PRG hid errors.
        if (! $res->ok) {
            return redirect()->to('/me/contacts')->with('error', $this->errText((string) $res->message));
        }

        return redirect()->to('/me/contacts');
    }

    /**
     * Staff bulk create on behalf of an assigned hierarchical group. The route
     * requires authentication + CSRF; the SERVICE additionally enforces that the
     * assigned group is within the caller's OWN leadership scope (containment),
     * so no global org-admin capability is needed — a leader can only bulk-add on
     * behalf of groups they actually hold.
     */
    public function bulk(): ResponseInterface
    {
        $orgId  = $this->orgId();
        $userId = $this->currentUserId();
        $in     = $this->input();

        $groupId = (string) ($in['assigned_group_id'] ?? '');
        if ($groupId === '') {
            return $this->respondWith(Result::fail('GROUP_REQUIRED', 'contact.group_required', 422));
        }

        $rows = $in['rows'] ?? [];
        if (! is_array($rows) || $rows === []) {
            return $this->respondWith(Result::fail('ROWS_REQUIRED', 'contact.rows_required', 422));
        }

        $res = $this->svc()->bulkCreate($orgId, $userId, $groupId, $rows);

        if ($this->wantsJson()) {
            return $this->respondWith($res);
        }

        // FR-ARC-002 sweep: a browser failure must FLASH — silent PRG hid errors.
        if (! $res->ok) {
            return redirect()->to('/me/contacts')->with('error', $this->errText((string) $res->message));
        }

        return redirect()->to('/me/contacts');
    }

    /** Issue a CSRF token for the dashboard forms (double-submit). */
    private function issueCsrf(): string
    {
        return \WBS\Identity\Config\Services::webAuth()->issueCsrf();
    }

    /**
     * Double-submit CSRF cookie matching WebSessionController's shape (HttpOnly,
     * SameSite=Lax, Secure over HTTPS).
     *
     * @return array<string,mixed>
     */
    private function csrfCookie(string $value): array
    {
        $secure = str_contains(strtolower($this->request->getHeaderLine('X-Forwarded-Proto')), 'https')
            || (method_exists($this->request, 'isSecure') && $this->request->isSecure());

        return [
            'name'     => 'wbs_csrf',
            'value'    => $value,
            'expires'  => 7200,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
