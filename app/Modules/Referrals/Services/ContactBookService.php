<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Geo\Services\LocationService;
use WBS\Identity\Security\PhoneNormalizer;
use WBS\Identity\Services\IdentityPolicyService;
use WBS\Referrals\Support\IntegrationDecision;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\ScopeMode;
use WBS\Shared\Support\Uuid;

/**
 * Address book / outreach contact management, built on the enriched `prospects`
 * table (see migration 000062). Gives a member or staff a birds-eye view of
 * their downline at every journey stage and follow-up temperature (hot/warm/
 * cold), consent-gated GPS tagging, staff bulk sign-up/invite on behalf of
 * assigned hierarchical groups, and dated decision recording.
 *
 * Privacy: a contact's location is PRIVATE to its owner/assigned group. Precise
 * coordinates are only persisted when BOTH the prospect's verbal consent AND the
 * member's checkbox confirmation are present; the coordinates live on the Geo
 * `addresses` row and precision is coarsened by LocationService otherwise.
 *
 * SAFE: no eval; parameterized queries throughout.
 */
final class ContactBookService
{
    public const TEMPERATURES = ['hot', 'warm', 'cold'];

    /**
     * R4 temperature-decay windows (days since last_contacted_at). A contact
     * cools one step down when it has gone untouched longer than the threshold
     * for its CURRENT temperature: hot→warm after 14 days, warm→cold after 30.
     * Cold is terminal (no lower step). Configurable later via EffectiveConfig;
     * these are the sensible defaults so the birds-eye triage board stops lying.
     */
    private const DECAY_AFTER_DAYS = ['hot' => 14, 'warm' => 30];

    /** One step cooler. */
    private const COOLER = ['hot' => 'warm', 'warm' => 'cold'];

    /** Canonical decision types (org-configurable; these are the seeded defaults). */
    public const DECISION_TYPES = [
        'salvation',
        'rededication',
        'water_baptism',
        'holy_spirit_baptism',
        'foundation_course',
        'join_group',
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ?LocationService $locations = null,
        private readonly ?GroupScopeResolver $scope = null,
        private readonly ?SponsorResolver $sponsorResolver = null,
        private readonly ?SponsorshipService $sponsorships = null,
        // Follow-up attendance seams: when wired, a contact's event/course
        // registration flows through the platform's OWN registrar/enroller so the
        // pre-event gates (published-only, atomic capacity, waitlist) apply.
        // Null → legacy direct-write fallback (kept for tests that don't wire them).
        private readonly ?EventRegistrarPort $eventRegistrar = null,
        private readonly ?CourseEnrollerPort $courseEnroller = null,
        // `join_group` side-effect seams (gap R3): a recorded join_group decision
        // must become a real belonging + a journey signal, not a dead log row.
        // Null → the decision is still recorded (append-only history preserved),
        // just without the membership/journey side effects — keeps older callers
        // and tests working.
        private readonly ?GroupMembershipPort $groupMemberships = null,
        private readonly ?JourneySignalPort $journeySignals = null,
        // R4 follow-up sweep seam: reminds a contact's owner a follow-up is due.
        // Null → the sweep still decays temperature, just skips notifications.
        private readonly ?FollowUpNotifierPort $followUpNotifier = null,
        // Onboarding placement: a prospect joins the group of the MENTOR/SPONSOR
        // who owns them and is never offered a choice of group. Null → the
        // caller-supplied group is used as-is (legacy callers/tests).
        private readonly ?ProspectGroupResolver $groupResolver = null,
        // Inactivity transfer: when a DIFFERENT mentor follows the contact up
        // (typically to attend their event) after the hierarchically-configured
        // quiet period, the belonging + sponsor move to that mentor's group.
        private readonly ?ProspectTransferService $transfers = null,
        // Maker-checker seam: when the subtree sets
        // `referrals.prospect_transfer.requires_review`, a due transfer is QUEUED
        // for a second leader instead of being applied on the spot. Null → always
        // auto-apply (the default behaviour, and what older callers/tests get).
        private readonly ?ProspectTransferReviewService $transferReviews = null,
        // users-writer parity (onboarding field-sync): contact→user promotion
        // carries the contact's phone (best-effort E.164 via the SAME normalizer
        // registration uses) and reads the org's default locale/timezone — never
        // a hardcoded region. Null (older positional test callers) degrades to
        // phone_input-only / 'en' + 'UTC', never a throw.
        private readonly ?PhoneNormalizer $phones = null,
        private readonly ?IdentityPolicyService $identityPolicies = null,
        // Optional integration-decision inputs on the assisted capture forms
        // (onboarding decision): when wired AND the placement group's
        // `referrals.integration_decisions.capture_inputs` lists the type, a
        // create/bulk submission may carry decision_type/date/note and the row
        // is recorded ASSISTED (born confirmed, recorded_by = owner). Null
        // (older positional test callers) -> decision fields are ignored.
        private readonly ?IntegrationService $integration = null,
    ) {
    }

    /**
     * Decision types the Add-contact form may offer for this placement group.
     * Fail-closed: empty when the integration engine is not wired or the
     * group's `capture_inputs` list is empty (default).
     *
     * @return list<string>
     */
    public function captureInputsFor(?string $groupId): array
    {
        return $this->integration !== null ? $this->integration->captureInputsFor($groupId) : [];
    }

    // -- Create / update -----------------------------------------------------

    /**
     * Create a contact owned by a member (from their dashboard address book) or
     * by staff. Returns the new contact id.
     *
     * @param array<string,mixed> $data
     */
    public function createContact(string $organizationId, array $data): Result
    {
        $ownerId = (string) ($data['owner_user_id'] ?? '');
        if ($ownerId === '') {
            return Result::fail('OWNER_REQUIRED', 'contact.owner_required', 422);
        }

        $name = trim((string) ($data['full_name'] ?? $data['display_name'] ?? ''));
        if ($name === '') {
            return Result::fail('NAME_REQUIRED', 'contact.name_required', 422);
        }

        $temp = $this->normalizeTemperature($data['temperature'] ?? 'cold');
        $now  = $this->clock->nowUtcString();
        $id   = Uuid::v7();

        // Optional consent-gated coordinate tagging on create.
        $addressId = $this->maybeTagCoordinates($organizationId, null, $data);

        $email = isset($data['email']) ? trim((string) $data['email']) : null;

        // A prospect is NOT given a choice of group: they are placed in the group
        // of the mentor/sponsor who owns them (staff bulk places them in the
        // leader's own group, which is the same rule seen from the leader's side).
        $assignedGroupId = $this->placementFor($organizationId, $ownerId, $data['assigned_group_id'] ?? null);

        // Optional integration-decision input (onboarding decision): GATE
        // BEFORE any insert — a stale/tampered form (type not offered for this
        // placement group, bad/future date) must not leave a partial record.
        $decisionType = trim((string) ($data['decision_type'] ?? ''));
        $decisionDate = trim((string) ($data['decision_date'] ?? ''));
        if ($decisionType !== '' && $this->integration !== null) {
            $gate = $this->integration->captureInputGate($assignedGroupId, $decisionType, $decisionDate);
            if ($gate !== null) {
                return $gate;
            }
        }

        $this->db->table('prospects')->insert([
            'id'                       => $id,
            'organization_id'          => $organizationId,
            'referrer_id'              => $ownerId,          // legacy column; owner doubles as referrer
            'owner_user_id'            => $ownerId,
            'assigned_group_id'        => $assignedGroupId,
            'created_by'               => $data['created_by'] ?? $ownerId,
            'source'                   => (string) ($data['source'] ?? 'member'),
            'display_name'             => $name,
            'full_name'                => $name,
            'phone'                    => $data['phone'] ?? null,
            'email'                    => $email,
            'email_hash'               => $email !== null && $email !== '' ? hash('sha256', strtolower($email)) : null,
            'address_id'               => $addressId,
            'notes'                    => $data['notes'] ?? null,
            'journey_stage'            => (string) ($data['journey_stage'] ?? 'prospect'),
            'temperature'              => $temp,
            'next_follow_up_at'        => $data['next_follow_up_at'] ?? null,
            'invite_context_type'      => $this->normalizeContextType($data['invite_context_type'] ?? null),
            'invite_context_id'        => $data['invite_context_id'] ?? null,
            'coords_consent_verbal'    => ! empty($data['coords_consent_verbal']) ? 1 : 0,
            'coords_consent_confirmed' => ! empty($data['coords_consent_confirmed']) ? 1 : 0,
            'coords_consent_at'        => $addressId !== null ? $now : null,
            'consent'                  => ! empty($data['consent']) ? 1 : 0,
            'state'                    => 'captured',
            'created_at'               => $now,
            'updated_at'               => $now,
        ]);

        // Record the assisted decision (born CONFIRMED — assisted counts at
        // once). Runs after the insert: the gate above already proved the type
        // is offered + the date validates, so this is insert-only.
        if ($decisionType !== '' && $this->integration !== null) {
            $decision = $this->integration->recordAssistedForContact($organizationId, $id, $ownerId, [
                'decision_type' => $decisionType,
                'decision_date' => $decisionDate,
                'decision_note' => $data['decision_note'] ?? null,
            ]);
            if (! $decision->ok) {
                return $decision;
            }
        }

        return Result::created([
            'id'                => $id,
            'temperature'       => $temp,
            'address_id'        => $addressId,
            'assigned_group_id' => $assignedGroupId,
            'placement_source'  => 'mentor_group',
        ]);
    }

    /**
     * Update mutable follow-up fields on a contact the caller owns.
     *
     * @param array<string,mixed> $data
     */
    public function updateContact(string $contactId, string $ownerId, array $data): Result
    {
        $row = $this->owned($contactId, $ownerId);
        if ($row === null) {
            return Result::denied('contact.not_found_or_forbidden', 'CONTACT_FORBIDDEN');
        }

        $fields = ['updated_at' => $this->clock->nowUtcString()];
        // NOTE: `assigned_group_id` is deliberately NOT editable here. Placement
        // has exactly three writers, so the birds-eye board can always answer why
        // a contact sits where it does:
        //   • createContact()          — derived from the mentor's group;
        //   • ProspectTransferService  — the inactivity rule, with provenance;
        //   • onGroupTornDown()        — group lifecycle (merge survivor / nearest
        //                                live ancestor).
        // A free-text field edit would move a person between groups with no
        // sponsor re-parent, no membership change and no audit trail.
        foreach (['full_name', 'phone', 'email', 'notes', 'journey_stage',
                  'next_follow_up_at', 'invite_context_id'] as $k) {
            if (array_key_exists($k, $data)) {
                $fields[$k] = $data[$k];
            }
        }
        if (array_key_exists('temperature', $data)) {
            $fields['temperature'] = $this->normalizeTemperature($data['temperature']);
        }
        if (array_key_exists('invite_context_type', $data)) {
            $fields['invite_context_type'] = $this->normalizeContextType($data['invite_context_type']);
        }
        if (array_key_exists('full_name', $data) && $fields['full_name'] !== null) {
            $fields['display_name'] = $fields['full_name'];
        }

        // Optional (re)tagging of coordinates on update.
        if (isset($data['latitude'], $data['longitude'])) {
            $addressId = $this->maybeTagCoordinates(
                (string) $row['organization_id'],
                $row['address_id'] ?? null,
                $data,
            );
            if ($addressId !== null) {
                $fields['address_id']            = $addressId;
                $fields['coords_consent_verbal'] = ! empty($data['coords_consent_verbal']) ? 1 : 0;
                $fields['coords_consent_confirmed'] = ! empty($data['coords_consent_confirmed']) ? 1 : 0;
                $fields['coords_consent_at']     = $this->clock->nowUtcString();
            }
        }

        $this->db->table('prospects')->where('id', $contactId)->update($fields);

        return Result::ok(['id' => $contactId]);
    }

    /** Record a follow-up touch: bumps counters, sets last/next dates + temperature. */
    public function recordFollowUp(string $contactId, string $ownerId, array $data = []): Result
    {
        $row = $this->owned($contactId, $ownerId);
        if ($row === null) {
            return Result::denied('contact.not_found_or_forbidden', 'CONTACT_FORBIDDEN');
        }

        $now    = $this->clock->nowUtcString();
        $fields = [
            'last_contacted_at' => $now,
            'follow_up_count'   => (int) ($row['follow_up_count'] ?? 0) + 1,
            'next_follow_up_at' => $data['next_follow_up_at'] ?? null,
            'updated_at'        => $now,
        ];
        if (! empty($data['temperature'])) {
            $fields['temperature'] = $this->normalizeTemperature($data['temperature']);
        }
        if (array_key_exists('notes', $data)) {
            $fields['notes'] = $data['notes'];
        }

        $this->db->table('prospects')->where('id', $contactId)->update($fields);

        return Result::ok(['id' => $contactId, 'follow_up_count' => $fields['follow_up_count']]);
    }

    /**
     * R4 — scheduled follow-up sweep. Two jobs, both bounded and idempotent:
     *
     *   1. **remind due** — for contacts whose `next_follow_up_at <= now` with an
     *      owner, remind the owner exactly once per due date (dedupe_key includes
     *      `next_follow_up_at`, so re-runs on the same day don't re-notify — the
     *      CommitmentService pattern).
     *   2. **decay temperature** — cool a contact one step (hot→warm→cold) once it
     *      has gone untouched past the window for its current temperature
     *      (measured from `last_contacted_at`, falling back to `created_at`), so
     *      the downline board reflects reality instead of the last human-set value.
     *
     * Decay is idempotent: a contact only ever steps DOWN, and only when overdue,
     * so a second pass on the same day changes nothing further. Reminders are
     * idempotent via the notification dedupe key.
     *
     * @return array{reminded:int, decayed:int}
     */
    public function processFollowUps(?string $organizationId, int $limit = 500): array
    {
        $limit = max(1, min(5000, $limit));
        $now   = $this->clock->nowUtcString();

        // ---- 1. remind owners of due follow-ups --------------------------------
        $reminded = 0;
        if ($this->followUpNotifier !== null) {
            $dq = $this->db->table('prospects')
                ->where('next_follow_up_at IS NOT NULL', null, false)
                ->where('next_follow_up_at <=', $now)
                ->where('owner_user_id IS NOT NULL', null, false);
            if ($organizationId !== null && $organizationId !== '') {
                $dq->where('organization_id', $organizationId);
            }
            $due = $dq->orderBy('next_follow_up_at', 'ASC')->get($limit)->getResultArray();

            foreach ($due as $c) {
                $owner = (string) ($c['owner_user_id'] ?? '');
                if ($owner === '') {
                    continue;
                }
                $this->followUpNotifier->remindFollowUpDue(
                    (string) $c['organization_id'],
                    $owner,
                    (string) $c['id'],
                    'outreach_follow_up_due:' . $c['id'] . ':' . $c['next_follow_up_at'],
                    [
                        'contact_id'        => (string) $c['id'],
                        'display_name'      => (string) ($c['display_name'] ?? $c['full_name'] ?? ''),
                        'temperature'       => (string) ($c['temperature'] ?? 'cold'),
                        'next_follow_up_at' => (string) $c['next_follow_up_at'],
                    ],
                );
                $reminded++;
            }
        }

        // ---- 2. decay temperature ---------------------------------------------
        $decayed  = 0;
        $nowTs    = strtotime($now) ?: time();
        foreach (self::DECAY_AFTER_DAYS as $temp => $days) {
            $cutoff = date('Y-m-d H:i:s', $nowTs - ($days * 86400));

            $q = $this->db->table('prospects')
                ->where('temperature', $temp)
                // untouched past the window: use last_contacted_at, else created_at.
                ->where('COALESCE(last_contacted_at, created_at) <=', $cutoff);
            if ($organizationId !== null && $organizationId !== '') {
                $q->where('organization_id', $organizationId);
            }
            $stale = $q->orderBy('created_at', 'ASC')->get($limit)->getResultArray();

            foreach ($stale as $c) {
                $this->db->table('prospects')->where('id', $c['id'])
                    // Guard on the observed temperature so a concurrent human
                    // update isn't clobbered by a stale decay.
                    ->where('temperature', $temp)
                    ->update([
                        'temperature' => self::COOLER[$temp],
                        'updated_at'  => $now,
                    ]);
                if ((int) $this->db->affectedRows() === 1) {
                    $decayed++;
                }
            }
        }

        return ['reminded' => $reminded, 'decayed' => $decayed];
    }

    // -- Decisions -----------------------------------------------------------

    /**
     * Record a dated decision for a contact. `join_group` may carry a
     * target_group_id (the group they decided to join). Append-only history — the
     * same type can be recorded again as a new dated occurrence.
     *
     * @param array<string,mixed> $data
     */
    public function recordDecision(string $contactId, string $ownerId, array $data): Result
    {
        $row = $this->owned($contactId, $ownerId);
        if ($row === null) {
            return Result::denied('contact.not_found_or_forbidden', 'CONTACT_FORBIDDEN');
        }

        $type = (string) ($data['decision_type'] ?? '');
        if ($type === '') {
            return Result::fail('DECISION_TYPE_REQUIRED', 'contact.decision_type_required', 422);
        }
        // Close the free-text gap (FR-REF-3b): only the catalogued decision
        // types may be recorded — shared with the self-declaration path.
        if (! IntegrationDecision::isValidType($type)) {
            return Result::fail('DECISION_TYPE_INVALID', 'contact.decision_type_invalid', 422, ['allowed' => IntegrationDecision::TYPES]);
        }

        $date = (string) ($data['decision_date'] ?? '');
        if ($date === '' || strtotime($date) === false) {
            return Result::fail('DECISION_DATE_REQUIRED', 'contact.decision_date_required', 422);
        }
        if ($date > $this->clock->now()->format('Y-m-d')) {
            return Result::fail('DECISION_DATE_FUTURE', 'contact.decision_date_future', 422);
        }

        $orgId         = (string) $row['organization_id'];
        $targetGroupId = $type === 'join_group'
            ? $this->joinTargetFor($orgId, $row, $data['target_group_id'] ?? null)
            : null;

        $id = Uuid::v7();
        $this->db->table('prospect_decisions')->insert([
            'id'              => $id,
            'organization_id' => $orgId,
            'prospect_id'     => $contactId,
            'decision_type'   => $type,
            'decision_date'   => date('Y-m-d', strtotime($date)),
            'target_group_id' => $targetGroupId,
            'note'            => $data['note'] ?? null,
            'recorded_by'     => $ownerId,
            'recorded_at'     => $this->clock->nowUtcString(),
            // Assisted (staff/member on the contact's behalf) rows are born
            // confirmed: the recorder is the vouching party. Self-declarations
            // from public/self-service surfaces are IntegrationService's half
            // and are born `pending`.
            'status'          => 'confirmed',
            'source'          => 'assisted',
        ]);

        // R3: a `join_group` decision is the hinge of conversion→integration. Turn
        // it into a real belonging + a journey signal instead of a dead record.
        $sideEffects = [];
        if ($type === 'join_group' && $targetGroupId !== null) {
            $sideEffects = $this->applyJoinGroupSideEffects($orgId, $contactId, $ownerId, $targetGroupId, $row);
        }

        return Result::created(['id' => $id, 'decision_type' => $type] + $sideEffects);
    }

    /**
     * The group a `join_group` decision joins: the one the contact is ALREADY
     * placed in — i.e. their mentor's group. A prospect is not offered a choice
     * of group, so a submitted `target_group_id` cannot relocate them; it is used
     * only when the contact has no placement yet (a legacy row captured before
     * placement was derived), and even then it is resolved through the mentor.
     *
     * A genuine move to a different body is not a decision — it is a transfer
     * ({@see ProspectTransferService}), which carries the inactivity rule, the
     * sponsor re-parent and the provenance row.
     *
     * @param array<string,mixed> $row the owned prospect row
     */
    private function joinTargetFor(string $organizationId, array $row, mixed $submitted): ?string
    {
        $placed = trim((string) ($row['assigned_group_id'] ?? ''));
        if ($placed !== '') {
            return $placed;
        }

        return $this->placementFor(
            $organizationId,
            (string) ($row['owner_user_id'] ?? ''),
            $submitted,
        );
    }

    /**
     * Side effects of a `join_group` decision (gap R3): (a) ensure the contact
     * has a linked platform user, (b) create/request a group_members row in the
     * target group under the group's join policy, and (c) emit a journey signal
     * so the stage ladder can react. Best-effort and non-fatal: the decision is
     * already persisted, so a missing collaborator or a downstream failure never
     * loses the recorded history — it just returns what did/didn't happen.
     *
     * @param array<string,mixed> $row the owned prospect row
     * @return array<string,mixed> membership/journey outcome hints for the caller
     */
    private function applyJoinGroupSideEffects(string $orgId, string $contactId, string $ownerId, string $targetGroupId, array $row): array
    {
        $out = ['target_group_id' => $targetGroupId];

        // (a) Ensure a linked user for the contact (creates one if needed).
        $userId = $this->ensureContactUser($orgId, $row);
        $out['linked_user_id'] = $userId;

        // (b) Create or request the membership. Honour the group's join policy:
        // 'open' → active immediately, anything else → pending until approved.
        if ($this->groupMemberships !== null) {
            $group = $this->db->table('groups')
                ->where('id', $targetGroupId)->where('organization_id', $orgId)
                ->get()->getRowArray();
            if ($group !== null) {
                $requiresApproval = (string) ($group['join_policy'] ?? 'approval') !== 'open';
                $res = $this->groupMemberships->join($orgId, $targetGroupId, $userId, [
                    'membership_type'   => 'member',
                    'role'              => 'member',
                    'source'            => 'referral',
                    'requires_approval' => $requiresApproval,
                    'added_by'          => $ownerId,
                    'actor_id'          => $ownerId,
                ]);
                $out['membership_ok']     = $res->ok;
                $out['membership_status'] = $res->ok ? (($res->data['status'] ?? null) ?? ($requiresApproval ? 'pending' : 'active')) : null;
            } else {
                $out['membership_ok'] = false;
                $out['membership_error'] = 'GROUP_NOT_FOUND';
            }
        }

        // (c) Emit a journey signal so membership rules can advance the person's
        // stage. Journey context = null (advance the ORG-WIDE journey) while the
        // originating group scopes which leaders' rules may fire.
        if ($this->journeySignals !== null) {
            $sig = $this->journeySignals->ingest($orgId, [
                'user_id'        => $userId,
                'action'         => 'journey.signal.group.joined',
                'group_id'       => null,
                'scope_group_id' => $targetGroupId,
                'actor_id'       => $ownerId,
                'attributes'     => ['target_group_id' => $targetGroupId, 'prospect_id' => $contactId],
            ]);
            $out['journey_signal_ok'] = $sig->ok;
            if ($sig->ok && is_array($sig->data)) {
                $out['journey_matched'] = (int) ($sig->data['matched'] ?? 0);
            }
        }

        // Move the prospect's own mirrored stage forward off 'prospect' so the
        // birds-eye board reflects that they've begun integrating. Never regress
        // a stage that is already past 'prospect'.
        if ((string) ($row['journey_stage'] ?? 'prospect') === 'prospect') {
            $this->db->table('prospects')->where('id', $contactId)->update([
                'journey_stage' => 'new_believer',
                'updated_at'    => $this->clock->nowUtcString(),
            ]);
            $out['journey_stage'] = 'new_believer';
        }

        return $out;
    }

    /** All decisions for a contact, newest first. @return list<array<string,mixed>> */
    public function decisionsFor(string $contactId): array
    {
        return $this->db->table('prospect_decisions')
            ->where('prospect_id', $contactId)
            ->orderBy('decision_date', 'DESC')
            ->orderBy('recorded_at', 'DESC')
            ->get()->getResultArray();
    }

    // -- Birds-eye / listing -------------------------------------------------

    /**
     * A member's own address book with optional triage filters.
     *
     * @param array<string,mixed> $filters temperature, journey_stage, group_id, due (bool)
     * @return list<array<string,mixed>>
     */
    public function listForOwner(string $organizationId, string $ownerId, array $filters = []): array
    {
        $q = $this->db->table('prospects')
            ->where('organization_id', $organizationId)
            ->where('owner_user_id', $ownerId);

        return $this->applyFiltersAndFetch($q, $filters);
    }

    /**
     * Staff birds-eye across a hierarchical group AND all its descendants (uses
     * group_closure), for downline oversight and reporting.
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function listForGroupSubtree(string $organizationId, string $rootGroupId, array $filters = []): array
    {
        $descendants = $this->db->table('group_closure')
            ->select('descendant_id')
            ->where('ancestor_id', $rootGroupId)
            ->get()->getResultArray();
        $ids = array_map(static fn ($r) => $r['descendant_id'], $descendants);
        if ($ids === []) {
            $ids = [$rootGroupId];
        }

        $q = $this->db->table('prospects')
            ->where('organization_id', $organizationId)
            ->whereIn('assigned_group_id', $ids);

        return $this->applyFiltersAndFetch($q, $filters);
    }

    /**
     * Downline summary counts (birds-eye dashboard tiles): totals by temperature,
     * by journey stage, and how many follow-ups are due now.
     *
     * @return array{total:int, by_temperature:array<string,int>, by_stage:array<string,int>, due:int}
     */
    public function summaryForOwner(string $organizationId, string $ownerId): array
    {
        return $this->summarize($this->listForOwner($organizationId, $ownerId));
    }

    /** @return array{total:int, by_temperature:array<string,int>, by_stage:array<string,int>, due:int} */
    public function summaryForGroupSubtree(string $organizationId, string $rootGroupId): array
    {
        return $this->summarize($this->listForGroupSubtree($organizationId, $rootGroupId));
    }

    // -- G5: invite-source validity on group teardown ------------------------

    /** Terminal / hidden group states (mirrors GroupScopeResolver::withoutDeadGroups). */
    private const DEAD_GROUP_STATUSES = ['dissolved', 'merged', 'archived'];

    /**
     * G5 (group-half) — keep INVITE SOURCES valid when a group dies.
     *
     * A pending outreach contact can be pinned to a group in two ways that both
     * become stale the moment that group is torn down:
     *
     *   - `invite_context_type = 'group'` + `invite_context_id = <dead group>` —
     *     the contact was invited to JOIN that specific group. Once the group is
     *     gone the invite target no longer exists, so admitting the contact
     *     against it would silently place them in a dead node. G5 is FAIL-CLOSED
     *     for invite-only flows, so a dissolve CLEARS the stale group context
     *     (the contact stays in the book, but the dead invite target is dropped,
     *     forcing a fresh, valid invite). A merge RE-POINTS the context to the
     *     SURVIVOR, so an in-flight invite lands in the group that genuinely
     *     continues.
     *
     *   - `assigned_group_id = <dead group>` — the contact is owned/triaged under
     *     that group's downline board. On merge this follows the survivor; on
     *     dissolve it rolls UP to the nearest LIVE ancestor so the contact stays
     *     visible to a real leader instead of vanishing from every board.
     *
     * Contacts, decisions and follow-up history are never deleted — only the two
     * group pointers move. SYSTEM authority, fault-isolated caller (JobRouter),
     * idempotent (once a contact names a live group it no longer matches the dead
     * one).
     *
     * @return Result data: cleared_invite_contexts, repointed_invite_contexts,
     *                reassigned_contacts, target_group_id (?string), mode
     */
    public function onGroupTornDown(
        string $organizationId,
        string $groupId,
        string $reasonCode,
        ?string $survivorId = null,
    ): Result {
        if ($organizationId === '' || $groupId === '') {
            return Result::fail('CONTACT_TEARDOWN_BAD_INPUT', 'contact.teardown_bad_input', 422);
        }

        $now      = $this->clock->nowUtcString();
        $survivor = $survivorId !== null ? trim($survivorId) : '';
        $isMerge  = $survivor !== '' && $survivor !== $groupId && $this->isLiveGroup($organizationId, $survivor);
        // Where group-pinned things should land after teardown: the survivor on a
        // merge, otherwise the nearest live ancestor (null => org level).
        $target = $isMerge ? $survivor : $this->nearestLiveAncestor($organizationId, $groupId);

        // 1) Group-context INVITES.
        $inviteBase = fn () => $this->db->table('prospects')
            ->where('organization_id', $organizationId)
            ->where('invite_context_type', 'group')
            ->where('invite_context_id', $groupId);

        $clearedInvites   = 0;
        $repointedInvites = 0;

        if ($isMerge) {
            // Re-point the invite to the survivor — the invite still means "join
            // the group that continues".
            $repointedInvites = (int) $inviteBase()->countAllResults(false);
            if ($repointedInvites > 0) {
                $inviteBase()->update([
                    'invite_context_id' => $survivor,
                    'updated_at'        => $now,
                ]);
            }
        } else {
            // Dissolve: fail-closed — drop the dead group context so no one is
            // admitted against a group that no longer exists.
            $clearedInvites = (int) $inviteBase()->countAllResults(false);
            if ($clearedInvites > 0) {
                $inviteBase()->update([
                    'invite_context_type' => null,
                    'invite_context_id'   => null,
                    'updated_at'          => $now,
                ]);
            }
        }

        // 2) ASSIGNED group (downline board ownership) — follows the survivor on
        // merge, rolls up to the nearest live ancestor on dissolve (null = org).
        $assignBase = fn () => $this->db->table('prospects')
            ->where('organization_id', $organizationId)
            ->where('assigned_group_id', $groupId);
        $reassigned = (int) $assignBase()->countAllResults(false);
        if ($reassigned > 0) {
            $assignBase()->update([
                'assigned_group_id' => $target, // may be null => org level
                'updated_at'        => $now,
            ]);
        }

        return Result::ok([
            'cleared_invite_contexts'   => $clearedInvites,
            'repointed_invite_contexts' => $repointedInvites,
            'reassigned_contacts'       => $reassigned,
            'target_group_id'           => $target,
            'mode'                      => $isMerge ? 'merge' : 'dissolve',
            'reason_code'               => $reasonCode,
        ]);
    }

    /** True when the group exists in the org and is NOT in a dead/hidden state. */
    private function isLiveGroup(string $organizationId, string $groupId): bool
    {
        $row = $this->db->table('groups')
            ->select('status')
            ->where('id', $groupId)
            ->where('organization_id', $organizationId)
            ->get()->getRowArray();

        return $row !== null && ! in_array((string) ($row['status'] ?? ''), self::DEAD_GROUP_STATUSES, true);
    }

    /**
     * Walk `groups.parent_id` upward from the dead group and return the nearest
     * ancestor still live, or NULL when the chain reaches the root (or a broken /
     * dead link) without one. A visited-set guards a cyclic parent chain.
     */
    private function nearestLiveAncestor(string $organizationId, string $groupId): ?string
    {
        $seen    = [$groupId => true];
        $current = $this->db->table('groups')
            ->select('parent_id')
            ->where('id', $groupId)
            ->where('organization_id', $organizationId)
            ->get()->getRowArray();
        $parentId = $current !== null ? (string) ($current['parent_id'] ?? '') : '';

        while ($parentId !== '' && ! isset($seen[$parentId])) {
            $seen[$parentId] = true;
            $row = $this->db->table('groups')
                ->select('status, parent_id')
                ->where('id', $parentId)
                ->where('organization_id', $organizationId)
                ->get()->getRowArray();
            if ($row === null) {
                return null;
            }
            if (! in_array((string) ($row['status'] ?? ''), self::DEAD_GROUP_STATUSES, true)) {
                return $parentId;
            }
            $parentId = (string) ($row['parent_id'] ?? '');
        }

        return null;
    }

    // -- Staff bulk ----------------------------------------------------------

    /**
     * Staff bulk create of contacts on behalf of an assigned hierarchical group.
     * Each row is a contact; consent flags default off (staff-entered leads are
     * typically address-only until a member follows up and tags coords).
     *
     * SCOPE-BOUNDED: the staff/leader may only bulk-add on behalf of a group that
     * falls within THEIR OWN leadership scope (union of their active
     * role_assignments, mode-aware over the hierarchy). This mirrors the
     * containment rule used across Delegation/AccessRequest/BreakGlass — no
     * separate org-admin capability is required or assumed.
     *
     * @param list<array<string,mixed>> $rows
     * @return Result data: { created:int, ids:list<string>, errors:list<array{index:int,message:string}> }
     */
    public function bulkCreate(string $organizationId, string $staffUserId, string $assignedGroupId, array $rows): Result
    {
        if (! $this->userScopeCovers($organizationId, $staffUserId, $assignedGroupId)) {
            return Result::denied('contact.group_out_of_scope', 'GROUP_OUT_OF_SCOPE');
        }

        $ids    = [];
        $errors = [];
        foreach ($rows as $i => $row) {
            $payload = $row + [
                'owner_user_id'     => $row['owner_user_id'] ?? $staffUserId,
                'assigned_group_id' => $assignedGroupId,
                'created_by'        => $staffUserId,
                'source'            => 'staff_bulk',
            ];
            // Temperature is AUTOMATED at capture (decision 2026-09-24): bulk
            // rows never set it — the service default (cold) applies, and only
            // the follow-up triage form / decay sweep may change it later.
            unset($payload['temperature']);
            $res = $this->createContact($organizationId, $payload);
            if ($res->ok) {
                $ids[] = $res->data['id'];
            } else {
                $errors[] = ['index' => $i, 'message' => (string) $res->message];
            }
        }

        return Result::ok([
            'created' => count($ids),
            'ids'     => $ids,
            'errors'  => $errors,
        ]);
    }

    // -- Scope (leadership containment) --------------------------------------

    /**
     * The set of group ids a user's leadership scope covers, expanded over the
     * hierarchy (union of their active, in-window role_assignments; mode-aware).
     * Returns null for an ORG-WIDE holder (unbounded — covers every group).
     *
     * @return list<string>|null
     */
    public function groupsInScopeForUser(string $organizationId, string $userId): ?array
    {
        if ($this->scope === null) {
            // No resolver wired -> cannot bound; treat as unbounded (legacy).
            return null;
        }

        $now  = $this->clock->nowUtcString();
        $rows = $this->db->table('role_assignments')
            ->select('id, scope_group_id, scope_mode, include_descendants, include_crosscut')
            ->where('organization_id', $organizationId)
            ->where('subject_id', $userId)
            ->where('status', 'active')
            ->groupStart()
                ->where('effective_from <=', $now)->orWhere('effective_from', null)
            ->groupEnd()
            ->groupStart()
                ->where('effective_to >', $now)->orWhere('effective_to', null)
            ->groupEnd()
            ->get()->getResultArray();

        $all = [];
        foreach ($rows as $r) {
            $scopeId = isset($r['scope_group_id']) && $r['scope_group_id'] !== '' ? (string) $r['scope_group_id'] : null;
            $mode    = ScopeMode::normalize($r['scope_mode'] ?? null, $r['include_descendants'] ?? null);
            $set     = $mode === ScopeMode::GROUPS ? $this->assignmentGroupSet((string) $r['id']) : [];
            $cc      = ! empty($r['include_crosscut']);

            $resolved = $this->scope->resolveScopeGroups($scopeId, $mode, $set, $cc);
            if ($resolved === null) {
                return null; // an org-wide assignment => unbounded
            }
            foreach ($resolved as $g) {
                $all[$g] = true;
            }
        }

        return array_keys($all);
    }

    /** True when the user's leadership scope covers $targetGroupId. */
    public function userScopeCovers(string $organizationId, string $userId, string $targetGroupId): bool
    {
        $groups = $this->groupsInScopeForUser($organizationId, $userId);
        if ($groups === null) {
            return true; // org-wide
        }

        return in_array($targetGroupId, $groups, true);
    }

    /** @return list<string> hand-picked group set for a GROUPS-mode role assignment. */
    private function assignmentGroupSet(string $assignmentId): array
    {
        $rows = $this->db->table('grant_scope_groups')
            ->select('group_id')
            ->where('grant_type', 'role_assignment')
            ->where('grant_id', $assignmentId)
            ->get()->getResultArray();

        return array_values(array_map(static fn ($r): string => (string) $r['group_id'], $rows));
    }

    // -- Attendance / registrations (within follow-ups) ----------------------

    /**
     * Register a contact for an EVENT or COURSE as part of following them up. The
     * contact must have (or be given) a linked user id so the platform's own
     * registration tables carry the row; if the contact has no linked user yet,
     * we create a lightweight pending user (mirrors GroupPublicService self-join)
     * so attendance can be tracked before full account activation.
     *
     * The registration flows through the platform's OWN registrar/enroller when
     * wired, so it obeys the SAME pre-event gates as a self-registration —
     * published-only, ATOMIC capacity + live holds, and ordered waitlisting for a
     * full event — instead of writing straight into the roster table. It also
     * means a follow-up registration is picked up by the event's change/cancel
     * and pre-event reminder notifications (Events G3), because it is a first-class
     * roster row. A `source_ref = contact:{id}` marker preserves the provenance so
     * organizers can see which registrations came from outreach follow-ups.
     *
     * Idempotent per (event|course, user): the registrar/enroller dedupes.
     *
     * @param array<string,mixed> $data type=event|course, target_id, plus event
     *                                   group_attribution / rsvp_state / status.
     */
    /**
     * Capture a GUEST who registered for an event via its shareable invite link
     * without a platform account (gap G5). The guest becomes a PROSPECT owned by
     * the link's creator (the sponsor), then is registered for the event through
     * the assisted path (the sponsor vouches, satisfying the invite gate). This
     * turns a broadcast-link sign-up into a real outreach lead for follow-up.
     *
     * @param array<string,mixed> $data full_name, email?, phone?, group_attribution?
     * @return Result data: {contact_id, registration}
     */
    public function captureGuestFromInvite(string $organizationId, string $eventId, string $sponsorId, array $data): Result
    {
        if (trim($sponsorId) === '') {
            return Result::fail('SPONSOR_REQUIRED', 'contact.owner_required', 422);
        }
        $name = trim((string) ($data['full_name'] ?? $data['display_name'] ?? ''));
        if ($name === '') {
            return Result::fail('NAME_REQUIRED', 'contact.name_required', 422);
        }

        // 1) ONE person is ONE contact: a guest already in the book is reused,
        //    never duplicated (a second mentor's invite must not fork history).
        $existing = $this->findExistingContact($organizationId, $data);
        $reused   = $existing !== null;
        $transfer = null;

        if ($existing !== null) {
            $contactId = (string) $existing['id'];

            // A DIFFERENT mentor following this person up to attend THEIR event
            // takes the relationship over once the hierarchically-configured quiet
            // period has passed. Evaluated BEFORE anything is written, so this
            // touch can never reset the clock it is being measured against.
            if ($this->transfers !== null) {
                $evaluation = $this->transfers->evaluate($organizationId, $existing, $sponsorId);
                if (! empty($evaluation['due'])) {
                    $trigger = [
                        'type'     => 'event_invite',
                        'id'       => $eventId,
                        'actor_id' => $sponsorId,
                    ];
                    // The policy is read off the group that currently holds the
                    // contact (same source evaluate() uses for the threshold), so a
                    // body can require review for its own subtree only.
                    $policyGroup = $evaluation['from_group_id'] ?? $evaluation['to_group_id'] ?? null;
                    if ($this->transferReviews !== null
                        && $this->transfers->requiresReview($organizationId, $policyGroup)) {
                        // Queued, not applied: nothing moves until a checker — who
                        // is not the mentor that triggered this — approves it.
                        $queued = $this->transferReviews->submitFromEvaluation(
                            $organizationId,
                            $existing,
                            $evaluation,
                            $trigger,
                        );
                        if ($queued->ok && is_array($queued->data)) {
                            $transfer = $queued->data + ['applied' => false, 'status' => 'pending_review'];
                        }
                    } else {
                        $applied = $this->transfers->apply($organizationId, $contactId, $sponsorId, $evaluation, $trigger);
                        if ($applied->ok && is_array($applied->data)) {
                            $transfer                        = $applied->data;
                            $existing['owner_user_id']       = $sponsorId;
                            $existing['assigned_group_id']   = $evaluation['to_group_id'];
                        }
                    }
                }
            }
        } else {
            $created = $this->createContact($organizationId, [
                'owner_user_id'       => $sponsorId,
                'full_name'           => $name,
                'email'               => $data['email'] ?? null,
                'phone'               => $data['phone'] ?? null,
                'source'              => 'event_invite_link',
                'temperature'         => 'warm', // a self-selecting sign-up is a warm lead
                'invite_context_type' => 'event',
                'invite_context_id'   => $eventId,
                'consent'             => ! empty($data['consent']), // field-sync: consent written on every path
                // Placement comes from the sponsor's own group, not the link's
                // attribution hint (a prospect is not offered a choice).
                'assigned_group_id'   => $data['group_attribution'] ?? null,
            ]);
            if (! $created->ok) {
                return $created;
            }
            $contactId = (string) $created->data['id'];

        }

        // 2) Register the guest for the event via the assisted follow-up path. The
        //    host may register a guest they do not (yet) own: ownership only moves
        //    through the inactivity transfer above, never as a side effect of an RSVP.
        // Optional integration-decision input on the guest self-register form
        // (onboarding decision): the GUEST fills it -> SELF flavor (source=
        // event_guest, born PENDING until the owner/sponsor confirms). Covers
        // both the fresh-create and the dedupe/reuse branch. Public path: a
        // gate failure or any error skips the optional enrichment silently —
        // it never fails the registration itself.
        $guestDecision = trim((string) ($data['decision_type'] ?? ''));
        if ($guestDecision !== '' && $this->integration !== null) {
            $decisionGroup = $reused
                ? ($existing['assigned_group_id'] ?? null)
                : ($created->data['assigned_group_id'] ?? null);
            if ($this->integration->captureInputAllowed($decisionGroup, $guestDecision)) {
                try {
                    $this->integration->declareForContact($organizationId, $contactId, [
                        'decision_type' => $guestDecision,
                        'decision_date' => trim((string) ($data['decision_date'] ?? '')),
                        'note'          => $data['decision_note'] ?? null,
                        'source'        => 'event_guest',
                    ]);
                } catch (Throwable) {
                    // optional enrichment — never block the capture
                }
            }
        }

        $reg = $this->recordAttendance($contactId, $sponsorId, [
            'type'              => 'event',
            'target_id'         => $eventId,
            'group_attribution' => $data['group_attribution'] ?? null,
            'rsvp_state'        => (string) ($data['rsvp_state'] ?? 'yes'),
        ], true);
        if (! $reg->ok) {
            return $reg;
        }

        // A subtree that requires review QUEUES the move instead of making it, so
        // `transferred` stays honest: it is only true when the belonging actually
        // moved. Callers that need to say "a checker must look at this" read
        // `transfer_queued` / `transfer.request_id`.
        $queued = is_array($transfer) && ($transfer['applied'] ?? null) === false;
        $payload = [
            'contact_id'      => $contactId,
            'registration'    => $reg->data,
            'reused'          => $reused,
            'transferred'     => $transfer !== null && ! $queued,
            'transfer_queued' => $queued,
            'transfer'        => $transfer,
        ];

        return $reused ? Result::ok($payload) : Result::created($payload);
    }

    public function recordAttendance(string $contactId, string $ownerId, array $data, bool $bypassOwnership = false): Result
    {
        // $bypassOwnership is for the invite-link path only: the guest used THIS
        // mentor's event link, so registering them for that event is legitimate
        // even when another mentor still owns the relationship (ownership only
        // moves through the inactivity transfer, never as a side effect of an
        // RSVP). Everything else stays owner-scoped.
        $row = $bypassOwnership ? $this->anyContact($contactId) : $this->owned($contactId, $ownerId);
        if ($row === null) {
            return Result::denied('contact.not_found_or_forbidden', 'CONTACT_FORBIDDEN');
        }

        $type = strtolower((string) ($data['type'] ?? ''));
        if (! in_array($type, ['event', 'course'], true)) {
            return Result::fail('BAD_ATTENDANCE_TYPE', 'contact.attendance_bad_type', 422);
        }
        $targetId = (string) ($data['target_id'] ?? '');
        if ($targetId === '') {
            return Result::fail('TARGET_REQUIRED', 'contact.attendance_target_required', 422);
        }

        $orgId  = (string) $row['organization_id'];
        $userId = $this->ensureContactUser($orgId, $row);
        $now    = $this->clock->nowUtcString();

        if ($type === 'event') {
            $groupAttribution = $data['group_attribution'] ?? ($row['assigned_group_id'] ?? null);
            $result           = $this->registerForEvent($orgId, $targetId, $userId, $contactId, [
                'group_attribution' => $groupAttribution,
                'rsvp_state'        => (string) ($data['rsvp_state'] ?? 'yes'),
                'status'            => (string) ($data['status'] ?? 'registered'),
                // The follower vouches for the contact → assisted registration.
                'assisted_by'       => $ownerId,
            ]);
        } else { // course
            $result = $this->enrollInCourse($orgId, $targetId, $userId, [
                'cohort_id' => $data['cohort_id'] ?? null,
                'status'    => (string) ($data['status'] ?? 'active'),
            ]);
        }

        // A failed pre-event gate (e.g. event not published / registration closed)
        // must NOT be recorded as a successful follow-up touch — surface it so the
        // follower knows the registration did not take.
        if (! $result->ok) {
            return $result;
        }

        // Reflect the touch on the contact (a successful registration IS a
        // follow-up action).
        $this->db->table('prospects')->where('id', $contactId)->update([
            'last_contacted_at' => $now,
            'follow_up_count'   => (int) ($row['follow_up_count'] ?? 0) + 1,
            'updated_at'        => $now,
        ]);

        $regId = (string) ($result->data['registration_id'] ?? $result->data['enrollment_id'] ?? $result->data['id'] ?? '');

        return Result::created([
            'id'      => $regId,
            'type'    => $type,
            'user_id' => $userId,
            // Surface the effective roster state (registered|waitlisted|active) so
            // the follower learns when a full event waitlisted the contact.
            'status'  => (string) ($result->data['status'] ?? ''),
        ]);
    }

    /**
     * Register the contact's user for an event. Routes through the platform
     * registrar (pre-event gates) when wired; else falls back to the legacy
     * direct write (kept only for unit tests that construct the service without
     * the Events seam).
     *
     * @param array<string,mixed> $opts group_attribution, rsvp_state, status
     */
    private function registerForEvent(string $orgId, string $eventId, string $userId, string $contactId, array $opts): Result
    {
        if ($this->eventRegistrar !== null) {
            return $this->eventRegistrar->register($orgId, $eventId, $userId, [
                'group_attribution' => $opts['group_attribution'] ?? null,
                'source_ref'        => 'contact:' . $contactId,
                'rsvp_state'        => (string) ($opts['rsvp_state'] ?? 'yes'),
                // A follow-up registration is ASSISTED by the follower (an
                // authorized member/leader/staffer working their contact), so it
                // satisfies an invite-only event's gate (gap G5).
                'assisted_by'       => $opts['assisted_by'] ?? null,
            ]);
        }

        // -- Legacy direct-write fallback (no registrar wired) ----------------
        $now      = $this->clock->nowUtcString();
        $existing = $this->db->table('event_registrations')
            ->where('event_id', $eventId)->where('user_id', $userId)->get()->getRowArray();
        $fields = [
            'organization_id'   => $orgId,
            'event_id'          => $eventId,
            'user_id'           => $userId,
            'group_attribution' => $opts['group_attribution'] ?? null,
            'source_ref'        => 'contact:' . $contactId,
            'status'            => (string) ($opts['status'] ?? 'registered'),
            'rsvp_state'        => (string) ($opts['rsvp_state'] ?? 'yes'),
            'updated_at'        => $now,
        ];
        if ($existing !== null) {
            $this->db->table('event_registrations')->where('id', $existing['id'])->update($fields);
            $regId = (string) $existing['id'];
        } else {
            $fields['id']         = Uuid::v7();
            $fields['created_at'] = $now;
            $this->db->table('event_registrations')->insert($fields);
            $regId = $fields['id'];
        }

        return Result::created(['registration_id' => $regId, 'status' => $fields['status']]);
    }

    /**
     * Enrol the contact's user in a course. Routes through the platform enroller
     * when wired; else falls back to the legacy direct write.
     *
     * @param array<string,mixed> $opts cohort_id, status
     */
    private function enrollInCourse(string $orgId, string $courseId, string $userId, array $opts): Result
    {
        if ($this->courseEnroller !== null) {
            return $this->courseEnroller->enroll($orgId, $courseId, $userId, $opts['cohort_id'] ?? null);
        }

        // -- Legacy direct-write fallback (no enroller wired) -----------------
        $now      = $this->clock->nowUtcString();
        $existing = $this->db->table('enrollments')
            ->where('course_id', $courseId)->where('user_id', $userId)->get()->getRowArray();
        if ($existing !== null) {
            $this->db->table('enrollments')->where('id', $existing['id'])->update([
                'status' => (string) ($opts['status'] ?? 'active'),
            ]);
            $regId = (string) $existing['id'];
        } else {
            $regId = Uuid::v7();
            $this->db->table('enrollments')->insert([
                'id'              => $regId,
                'organization_id' => $orgId,
                'course_id'       => $courseId,
                'user_id'         => $userId,
                'cohort_id'       => $opts['cohort_id'] ?? null,
                'status'          => (string) ($opts['status'] ?? 'active'),
                'enrolled_at'     => $now,
            ]);
        }

        return Result::created(['enrollment_id' => $regId, 'status' => (string) ($opts['status'] ?? 'active')]);
    }

    /**
     * All event + course registrations tied to a contact's linked user, for the
     * follow-up view. Empty when the contact has no linked user yet.
     *
     * @return array{events:list<array<string,mixed>>, courses:list<array<string,mixed>>}
     */
    public function attendanceFor(string $contactId): array
    {
        $row = $this->db->table('prospects')->where('id', $contactId)->get()->getRowArray();
        $userId = $row['linked_user_id'] ?? null;
        if ($userId === null || $userId === '') {
            return ['events' => [], 'courses' => []];
        }

        $events = $this->db->table('event_registrations')
            ->where('user_id', $userId)->orderBy('created_at', 'DESC')->get()->getResultArray();
        $courses = $this->db->table('enrollments')
            ->where('user_id', $userId)->orderBy('enrolled_at', 'DESC')->get()->getResultArray();

        return ['events' => $events, 'courses' => $courses];
    }

    /**
     * Ensure the contact has a linked platform user so registrations can be
     * recorded against it. Reuses an existing user by email; otherwise creates a
     * lightweight pending_verification user and stores linked_user_id on the
     * contact. Returns the user id.
     *
     * @param array<string,mixed> $row the prospect row
     */
    private function ensureContactUser(string $organizationId, array $row): string
    {
        $existingLink = $row['linked_user_id'] ?? null;
        if (is_string($existingLink) && $existingLink !== '') {
            // Also re-asserted for accounts linked BEFORE the belonging invariant
            // existed, so an old contact converges instead of staying unplaced.
            $this->ensureUserBelongs($organizationId, $existingLink, $row);

            return $existingLink;
        }

        $email = isset($row['email']) ? strtolower(trim((string) $row['email'])) : '';
        $user  = null;
        if ($email !== '') {
            $user = $this->db->table('users')
                ->where('organization_id', $organizationId)->where('email', $email)->get()->getRowArray();
        }

        if ($user !== null) {
            $userId = (string) $user['id'];
        } else {
            $userId = Uuid::v7();
            $now    = $this->clock->nowUtcString();

            // users-writer parity (field-sync): org defaults, never a
            // hardcoded locale/region; the contact's phone travels with the person;
            // status evidence mirrors AccountService::register.
            $org = $this->db->table('organizations')
                ->where('id', $organizationId)->get()->getRowArray();
            $orgLocale = is_array($org) ? (string) ($org['default_locale'] ?? 'en') : 'en';
            $orgTz     = is_array($org) ? (string) ($org['timezone'] ?? 'UTC') : 'UTC';

            $contactPhone = trim((string) ($row['phone'] ?? ''));
            $phoneInput   = $contactPhone !== '' ? $contactPhone : null;
            $phone        = null;
            $phoneRegion  = null;
            if ($phoneInput !== null && $this->phones !== null) {
                $policy       = $this->identityPolicies?->resolve($organizationId, null);
                $phoneRegion  = is_array($policy) ? ($policy['phone_default_region'] ?? null) : null;
                $phone        = $this->phones->normalize($phoneInput, $phoneRegion);
                if ($phone === null) {
                    // Best-effort, unlike self-registration: an as-entered book
                    // number may be national/garbled — keep the raw evidence,
                    // leave the verified column empty.
                    $phoneInput = $contactPhone;
                } else {
                    $phoneInput = $contactPhone;
                }
            }

            $this->db->table('users')->insert([
                'id'                => $userId,
                'organization_id'   => $organizationId,
                'email'             => $email !== '' ? $email : ($userId . '@contacts.invalid'),
                'email_verified'    => 0,
                'phone'             => $phone,
                'phone_verified'    => 0,
                'phone_input'       => $phoneInput,
                'phone_region'      => $phoneRegion,
                'password_hash'     => null,
                'display_name'      => (string) ($row['full_name'] ?? $row['display_name'] ?? 'Contact'),
                'status'            => 'pending_verification',
                'status_reason'     => 'contact_promotion',
                'status_changed_at' => $now,
                'locale'            => $orgLocale,
                'timezone'          => $orgTz,
                'mfa_enabled'       => 0,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);

            // Append-only transition evidence — same contract as register().
            $this->db->table('account_state_transitions')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'user_id'         => $userId,
                'from_status'     => null,
                'to_status'       => 'pending_verification',
                'reason'          => 'contact_promotion',
                'actor_id'        => $row['owner_user_id'] ?? null,
                'approval_ref'    => null,
                'evidence'        => null,
                'created_at'      => $this->clock->nowUtcMicro(),
            ]);

            // FR-MEM-001: a freshly-minted member gets a sponsor too. Prefer the
            // contact owner (the member/staffer working this lead), else the
            // hierarchical leader of the contact's assigned group.
            $this->linkSponsor($organizationId, $userId, [
                'explicit_sponsor_id' => $row['owner_user_id'] ?? null,
                'group_id'            => $row['assigned_group_id'] ?? null,
            ]);
        }

        $this->ensureUserBelongs($organizationId, $userId, $row);

        $this->db->table('prospects')->where('id', (string) $row['id'])->update([
            'linked_user_id' => $userId,
            'updated_at'     => $this->clock->nowUtcString(),
        ]);

        return $userId;
    }

    /**
     * Best-effort automatic sponsor for a newly-created member. No-op when the
     * referrals collaborators are not wired, when the member already has an
     * active sponsor, or when no eligible leader can be resolved. Never throws.
     *
     * @param array{explicit_sponsor_id?:mixed, group_id?:mixed} $ctx
     */
    private function linkSponsor(string $organizationId, string $newUserId, array $ctx): void
    {
        if ($this->sponsorResolver === null || $this->sponsorships === null) {
            return;
        }

        try {
            $sponsorId = $this->sponsorResolver->resolve($organizationId, [
                'explicit_sponsor_id' => isset($ctx['explicit_sponsor_id']) ? (string) $ctx['explicit_sponsor_id'] : null,
                'group_id'            => isset($ctx['group_id']) ? (string) $ctx['group_id'] : null,
                'exclude_user_id'     => $newUserId,
            ]);
            if ($sponsorId === null || $sponsorId === $newUserId) {
                return;
            }
            // Do NOT override an existing active sponsor (assign() would re-parent).
            if ($this->sponsorships->activeSponsor($newUserId) !== null) {
                return;
            }
            $this->sponsorships->assign($organizationId, $newUserId, $sponsorId, 'contact_promoted');
        } catch (Throwable) {
            // sponsorships table absent / transient — never block contact linking.
        }
    }

    // -- Internals -----------------------------------------------------------

    /**
     * Persist coordinates ONLY when the two-part consent is satisfied (prospect
     * verbally agreed + member confirmed via checkbox) and both lat/long are
     * present. Returns the address id (existing or new), or the existing address
     * id unchanged when consent is missing. Precise precision is requested; the
     * Geo layer coarsens if a broader consent policy is not met.
     *
     * @param array<string,mixed> $data
     */
    private function maybeTagCoordinates(string $organizationId, ?string $existingAddressId, array $data): ?string
    {
        $lat = $data['latitude'] ?? null;
        $lng = $data['longitude'] ?? null;
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return $existingAddressId;
        }

        $verbal    = ! empty($data['coords_consent_verbal']);
        $confirmed = ! empty($data['coords_consent_confirmed']);
        if (! ($verbal && $confirmed)) {
            // No/partial consent -> do NOT store precise coords.
            return $existingAddressId;
        }

        if ($this->locations === null) {
            return $existingAddressId;
        }

        $res = $this->locations->upsertAddress($organizationId, [
            'id'                 => $existingAddressId, // null => new
            'latitude'           => (float) $lat,
            'longitude'          => (float) $lng,
            'location_precision' => 'exact',
            'location_source'    => 'member_tagged',
            'line1'              => $data['line1'] ?? null,
            'city_id'            => $data['city_id'] ?? null,
            'state_id'           => $data['state_id'] ?? null,
            'country_id'         => $data['country_id'] ?? null,
        ]);

        return $res->ok ? (string) $res->data['id'] : $existingAddressId;
    }

    /**
     * @param \CodeIgniter\Database\BaseBuilder $q
     * @param array<string,mixed>               $filters
     * @return list<array<string,mixed>>
     */
    private function applyFiltersAndFetch($q, array $filters): array
    {
        if (! empty($filters['temperature'])) {
            $q->where('temperature', $this->normalizeTemperature($filters['temperature']));
        }
        if (! empty($filters['journey_stage'])) {
            $q->where('journey_stage', (string) $filters['journey_stage']);
        }
        if (! empty($filters['group_id'])) {
            $q->where('assigned_group_id', (string) $filters['group_id']);
        }
        if (! empty($filters['due'])) {
            $q->where('next_follow_up_at <=', $this->clock->nowUtcString());
        }

        return $q->orderBy('next_follow_up_at', 'ASC')
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{total:int, by_temperature:array<string,int>, by_stage:array<string,int>, due:int}
     */
    private function summarize(array $rows): array
    {
        $byTemp  = ['hot' => 0, 'warm' => 0, 'cold' => 0];
        $byStage = [];
        $due     = 0;
        $now     = $this->clock->nowUtcString();

        foreach ($rows as $r) {
            $t = (string) ($r['temperature'] ?? 'cold');
            $byTemp[$t] = ($byTemp[$t] ?? 0) + 1;

            $s = (string) ($r['journey_stage'] ?? 'prospect');
            $byStage[$s] = ($byStage[$s] ?? 0) + 1;

            if (! empty($r['next_follow_up_at']) && (string) $r['next_follow_up_at'] <= $now) {
                $due++;
            }
        }

        return [
            'total'          => count($rows),
            'by_temperature' => $byTemp,
            'by_stage'       => $byStage,
            'due'            => $due,
        ];
    }

    private function normalizeTemperature(mixed $t): string
    {
        $t = strtolower(trim((string) $t));

        return in_array($t, self::TEMPERATURES, true) ? $t : 'cold';
    }

    private function normalizeContextType(mixed $t): ?string
    {
        if ($t === null || $t === '') {
            return null;
        }
        $t = strtolower(trim((string) $t));

        return in_array($t, ['cause', 'course', 'event', 'group'], true) ? $t : null;
    }

    /** @return array<string,mixed>|null the row if owned by $ownerId, else null. */
    /**
     * Where a new contact is placed: the MENTOR's group. A prospect is never
     * offered a choice, so a submitted group id is advisory only — the resolver
     * honours it just when it already agrees with the mentor's home group (staff
     * bulk sign-up, which passes the leader's own group). Without a resolver
     * wired the submitted value is used, preserving legacy callers.
     */
    private function placementFor(string $organizationId, string $ownerId, mixed $requested): ?string
    {
        $requested = is_string($requested) && trim($requested) !== '' ? trim($requested) : null;
        if ($this->groupResolver === null) {
            return $requested;
        }

        return $this->groupResolver->placementFor($organizationId, $ownerId, $requested);
    }

    /**
     * Find the contact that is ALREADY in the book for this person, so a repeat
     * invite reuses them: same email (compared on the stored `email_hash`) or the
     * same phone number (compared on the last nine digits, then confirmed by
     * normalizing both sides, because the book stores numbers as entered —
     * "024 123 4567", "+233241234567" and "233241234567" are one person).
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>|null
     */
    private function findExistingContact(string $organizationId, array $data): ?array
    {
        $email = isset($data['email']) ? strtolower(trim((string) $data['email'])) : '';
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $row = $this->db->table('prospects')
                ->where('organization_id', $organizationId)
                ->where('email_hash', hash('sha256', $email))
                ->orderBy('created_at', 'ASC')
                ->get()->getRowArray();
            if ($row !== null) {
                return $row;
            }
        }

        $key = self::phoneKey($data['phone'] ?? null);
        if ($key === null) {
            return null;
        }
        $tail = substr($key, -9);
        $candidates = $this->db->table('prospects')
            ->where('organization_id', $organizationId)
            ->like('phone', $tail, 'before')   // '%<tail>' — portable suffix match
            ->orderBy('created_at', 'ASC')
            ->limit(25)
            ->get()->getResultArray();
        foreach ($candidates as $c) {
            if (self::phoneKey($c['phone'] ?? null) === $key) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Digits-only comparison key for a phone number: separators dropped, an
     * international `00`/`+233` Ghana prefix folded to the local `0` form. Null
     * when it cannot be a phone number (fewer than 7 digits).
     */
    public static function phoneKey(mixed $phone): ?string
    {
        if (! is_string($phone) && ! is_int($phone)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', ltrim(trim((string) $phone), '+')) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '233') && strlen($digits) === 12) {
            $digits = '0' . substr($digits, 3);
        }

        return strlen($digits) >= 7 ? $digits : null;
    }

    /**
     * Assert the "every system user belongs to a particular group" invariant for
     * a contact's linked account. Idempotent: a user who already holds an active
     * membership anywhere is left alone (belonging is never moved here — that is
     * {@see ProspectTransferService}'s job); otherwise they join the contact's
     * group, which is the mentor's group (see {@see placementFor()}).
     *
     * @param array<string,mixed> $row the prospects row
     */
    private function ensureUserBelongs(string $organizationId, string $userId, array $row): void
    {
        $this->groupMemberships?->ensureBelonging($organizationId, $userId, $row['assigned_group_id'] ?? null, [
            'source'   => 'system',
            'added_by' => $row['owner_user_id'] ?? null,
            'actor_id' => $row['owner_user_id'] ?? null,
        ]);
    }

    /** Load a contact regardless of who owns it (still one row, by id). */
    private function anyContact(string $contactId): ?array
    {
        return $this->db->table('prospects')->where('id', $contactId)->get()->getRowArray();
    }

    private function owned(string $contactId, string $ownerId): ?array
    {
        $row = $this->db->table('prospects')->where('id', $contactId)->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        return (string) ($row['owner_user_id'] ?? '') === $ownerId ? $row : null;
    }
}
