<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Admin\Services\EffectiveConfigResolver;
use WBS\Audit\Services\AuditLogger;
use WBS\Referrals\Support\IntegrationConfig;
use WBS\Referrals\Support\IntegrationDecision;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Integration lifecycle (FR-REF-3b) — the dated decisions a person makes on
 * the way into the family, EACH STANDING ALONE: salvation, water baptism,
 * Holy Spirit baptism, and the foundation course (four required decisions —
 * the baptisms are never bundled). They may all happen in one day or on
 * different dates, at an invitation, an event, or staff/member-assisted
 * registration.
 *
 * This service is the non-assisted half of `prospect_decisions` (the assisted
 * path stays in {@see ContactBookService::recordDecision}, which shares the
 * {@see IntegrationDecision} catalog):
 *
 *   - SELF-DECLARATION: a visitor on a cloaked invite landing or an event guest
 *     form, or a member on their own "my integration" page, states a decision
 *     and its date. It is stored `pending` and only COUNTS once the owning
 *     mentor/sponsor confirms it (maker-checker; assisted staff entry counts
 *     immediately).
 *   - DERIVATION: a real foundation-category course enrolment (and optionally
 *     completion) auto-appends the matching `foundation_course` row, so nobody
 *     double-enters a fact the platform already knows.
 *   - STATE + GATE: the checklist verdict (integrated / outstanding) and the
 *     Journey gate (a member cannot be advanced into a gated stage — default
 *     In Foundation / Established — until integrated).
 *
 * Everything is gated by hierarchical group config (`referrals.integration_decisions`),
 * DEFAULT OFF; no config resolver wired ⇒ everything reads as OFF and no row is
 * ever written.
 */
final class IntegrationService
{
    public const SOURCE_ASSISTED           = 'assisted';
    public const SOURCE_SELF               = 'self';
    public const SOURCE_LANDING            = 'landing';
    public const SOURCE_EVENT_GUEST        = 'event_guest';
    public const SOURCE_DERIVED_COURSE     = 'derived_course';
    public const SOURCE_DERIVED_COMPLETION = 'derived_completion';
    public const SOURCE_SYSTEM             = 'system';

    /** Sources that are a person speaking for themselves (pending until confirmed). */
    private const SELF_SOURCES = [
        self::SOURCE_SELF,
        self::SOURCE_LANDING,
        self::SOURCE_EVENT_GUEST,
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ?EffectiveConfigResolver $config = null,
        private readonly ?GroupScopeResolver $scope = null,
        private readonly ?SponsorshipService $sponsorships = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    // ---- Config ---------------------------------------------------------------

    /** The effective config for a group, or the OFF shape. */
    public function configFor(?string $groupId): array
    {
        if ($this->config === null || $groupId === null || $groupId === '') {
            return IntegrationConfig::normalize(null);
        }

        try {
            $res = $this->config->resolve($groupId, IntegrationConfig::CAPABILITY);
            if (! $res->ok || ! is_array($res->data)) {
                return IntegrationConfig::normalize(null);
            }

            return IntegrationConfig::normalize($res->data['value'] ?? null);
        } catch (Throwable) {
            return IntegrationConfig::normalize(null);
        }
    }

    // ---- Self-declaration ------------------------------------------------------

    /**
     * A visitor/member declares a decision against a CONTACT (public invite
     * landing, event guest form, or an owner declaring on the contact's behalf).
     * The declaration is `pending` when confirmation is required, and only
     * counts once the owning mentor confirms it.
     *
     * @param array<string,mixed> $data decision_type, decision_date, note, source
     */
    /**
     * Which decision types the CAPTURE FORMS for this group may offer as
     * optional inputs (onboarding decision). Empty when the feature is off or
     * no types are listed — fail-closed in both directions.
     *
     * @return list<string>
     */
    public function captureInputsFor(?string $groupId): array
    {
        return IntegrationConfig::captureInputsOf($this->configFor($groupId));
    }

    /** Is $type accepted as a capture-form input for this group? */
    public function captureInputAllowed(?string $groupId, string $type): bool
    {
        return $type !== '' && in_array($type, $this->captureInputsFor($groupId), true);
    }

    /**
     * Pre-insert gate for an ASSISTED capture-form submission: the type must be
     * offered for the placement group and the date must validate. Returns null
     * when the submission may proceed, or the failing Result — call BEFORE any
     * row is written so a stale/tampered form never leaves a partial record.
     */
    public function captureInputGate(?string $groupId, string $type, string $date): ?Result
    {
        if (! $this->captureInputAllowed($groupId, $type)) {
            return Result::fail('CAPTURE_INPUT_DISABLED', 'integration.capture_input_disabled', 403);
        }

        return $this->validate($type, $date);
    }

    /**
     * ASSISTED capture-form decision: a member/staff records a type the group
     * offers on its onboarding forms. Born CONFIRMED immediately (staff-
     * assisted rows count at once — prior integration decision) with
     * recorded_by = the acting mentor; does NOT depend on
     * allow_self_declaration (that governs self/landing/event-guest rows).
     */
    public function recordAssistedForContact(string $organizationId, string $contactId, string $actorId, array $data): Result
    {
        $contact = $this->db->table('prospects')
            ->where('id', $contactId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($contact === null) {
            return Result::notFound('integration.contact_not_found', 'CONTACT_NOT_FOUND');
        }

        $cfg = $this->configFor($contact['assigned_group_id'] ?? null);
        $gate = $this->captureInputGate(
            $contact['assigned_group_id'] ?? null,
            (string) ($data['decision_type'] ?? ''),
            (string) ($data['decision_date'] ?? ''),
        );
        if ($gate !== null) {
            return $gate;
        }

        $row = $this->insertRow(
            $organizationId,
            $contactId,
            null,
            (string) $data['decision_type'],
            (string) $data['decision_date'],
            $data['decision_note'] ?? ($data['note'] ?? null),
            self::SOURCE_ASSISTED,
            'confirmed',
            null,
            $actorId !== '' ? $actorId : null,
        );

        return Result::created($row);
    }

    public function declareForContact(string $organizationId, string $contactId, array $data): Result
    {
        $contact = $this->db->table('prospects')
            ->where('id', $contactId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($contact === null) {
            return Result::notFound('integration.contact_not_found', 'CONTACT_NOT_FOUND');
        }

        $cfg = $this->configFor($contact['assigned_group_id'] ?? null);
        if (! $cfg['enabled']) {
            return Result::fail('INTEGRATION_DISABLED', 'integration.disabled', 403);
        }
        if (! $cfg['allow_self_declaration']) {
            return Result::fail('SELF_DECLARATION_DISABLED', 'integration.self_declaration_disabled', 403);
        }

        $valid = $this->validate($data['decision_type'] ?? '', (string) ($data['decision_date'] ?? ''));
        if ($valid !== null) {
            return $valid;
        }

        $source = in_array($data['source'] ?? '', self::SELF_SOURCES, true)
            ? (string) $data['source']
            : self::SOURCE_LANDING;

        return $this->commit(
            $organizationId,
            $contactId,
            null,
            (string) $data['decision_type'],
            (string) $data['decision_date'],
            $data['note'] ?? null,
            $source,
            $cfg,
        );
    }

    /**
     * Member self-service ("my integration"): declare against the USER.
     * Confirmation authority is the member's active sponsor.
     *
     * @param array<string,mixed> $data decision_type, decision_date, note
     */
    public function declareSelf(string $organizationId, string $userId, array $data): Result
    {
        $groupId = $this->scope !== null
            ? $this->scope->primaryMembershipGroup($organizationId, $userId)
            : null;

        $cfg = $this->configFor($groupId);
        if (! $cfg['enabled']) {
            return Result::fail('INTEGRATION_DISABLED', 'integration.disabled', 403);
        }
        if (! $cfg['allow_self_declaration']) {
            return Result::fail('SELF_DECLARATION_DISABLED', 'integration.self_declaration_disabled', 403);
        }

        $valid = $this->validate($data['decision_type'] ?? '', (string) ($data['decision_date'] ?? ''));
        if ($valid !== null) {
            return $valid;
        }

        return $this->commit(
            $organizationId,
            null,
            $userId,
            (string) $data['decision_type'],
            (string) $data['decision_date'],
            $data['note'] ?? null,
            self::SOURCE_SELF,
            $cfg,
        );
    }

    // ---- Maker-checker over self-declarations ----------------------------------

    public function confirm(string $organizationId, string $actorId, string $decisionId): Result
    {
        return $this->decide($organizationId, $actorId, $decisionId, 'confirmed');
    }

    public function reject(string $organizationId, string $actorId, string $decisionId): Result
    {
        return $this->decide($organizationId, $actorId, $decisionId, 'rejected');
    }

    private function decide(string $organizationId, string $actorId, string $decisionId, string $to): Result
    {
        $row = $this->db->table('prospect_decisions')
            ->where('id', $decisionId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('integration.decision_not_found', 'DECISION_NOT_FOUND');
        }
        if ((string) $row['status'] !== 'pending') {
            return Result::fail('NOT_PENDING', 'integration.not_pending', 409, ['status' => $row['status']]);
        }

        if (! $this->mayDecide($row, $actorId)) {
            return Result::denied('integration.forbidden', 'INTEGRATION_FORBIDDEN');
        }

        $this->db->table('prospect_decisions')->where('id', $decisionId)->update([
            'status'     => $to,
            'decided_by' => $actorId,
            'decided_at' => $this->clock->nowUtcString(),
        ]);

        $this->audit?->record($organizationId, $actorId, $to === 'confirmed'
            ? 'referrals.integration.decision_confirmed'
            : 'referrals.integration.decision_rejected', $decisionId);

        return Result::ok(['id' => $decisionId, 'status' => $to]);
    }

    /** Who may confirm a self-declaration: the owning mentor (contact) or the sponsor (user). */
    private function mayDecide(array $row, string $actorId): bool
    {
        $contactId = $row['prospect_id'] ?? null;
        if (is_string($contactId) && $contactId !== '') {
            $contact = $this->db->table('prospects')->where('id', $contactId)->get()->getRowArray();

            return $contact !== null && (string) ($contact['owner_user_id'] ?? '') === $actorId;
        }

        $userId = $row['user_id'] ?? null;
        if (is_string($userId) && $userId !== '' && $this->sponsorships !== null) {
            return $this->sponsorships->activeSponsor($userId) === $actorId;
        }

        return false;
    }

    // ---- Derivation from real platform facts -----------------------------------

    /** @param string $at an ISO datetime (enrolled_at) */
    public function deriveFromEnrolment(
        string $organizationId,
        string $userId,
        string $courseId,
        string $enrollmentId,
        string $at,
        string $category,
        ?string $courseGroupId,
    ): void {
        $this->derive($organizationId, $userId, $courseId, $enrollmentId, $at, $category, $courseGroupId, false);
    }

    public function deriveFromCompletion(
        string $organizationId,
        string $userId,
        string $courseId,
        string $enrollmentId,
        string $at,
        string $category,
        ?string $courseGroupId,
    ): void {
        $this->derive($organizationId, $userId, $courseId, $enrollmentId, $at, $category, $courseGroupId, true);
    }

    /** Best-effort and never fatal: the enrolment/completion must never fail on a decision row. */
    private function derive(
        string $organizationId,
        string $userId,
        string $courseId,
        string $enrollmentId,
        string $at,
        string $category,
        ?string $courseGroupId,
        bool $completion,
    ): void {
        try {
            if ($userId === '' || ! is_string($courseGroupId) || $courseGroupId === '') {
                return; // fail closed: no group means no policy to lean on
            }

            $cfg = $this->configFor($courseGroupId);
            $wanted = $completion ? $cfg['derive_from_completion'] : $cfg['derive_from_enrolment'];
            if (! $cfg['enabled'] || ! $wanted) {
                return;
            }
            if (! IntegrationConfig::isFoundationCategory($cfg, $category)) {
                return;
            }

            $date       = substr($at, 0, 10);
            $source     = $completion ? self::SOURCE_DERIVED_COMPLETION : self::SOURCE_DERIVED_COURSE;
            $sourceRef  = ($completion ? 'cmp:' : 'enr:') . $enrollmentId;

            // Idempotent: one derived row per (subject, type, source_ref) — the
            // UNIQUE key backs this up; the pre-check keeps the read cheap.
            $dup = $this->db->table('prospect_decisions')
                ->where('user_id', $userId)
                ->where('decision_type', 'foundation_course')
                ->where('source_ref', $sourceRef)
                ->countAllResults();
            if ($dup > 0) {
                return;
            }

            $this->insertRow(
                $organizationId,
                null,
                $userId,
                'foundation_course',
                $date,
                'Derived from a ' . ($completion ? 'completed' : '') . ' foundation-course ' . ($completion ? 'completion' : 'enrolment') . ' (' . $courseId . ').',
                $source,
                'confirmed',
                $sourceRef,
            );
        } catch (Throwable) {
            // Non-fatal by design.
        }
    }

    // ---- Reads: checklist + gate -----------------------------------------------

    /** @return array<string,mixed> */
    public function checklistForContact(string $organizationId, string $contactId): array
    {
        $contact = $this->db->table('prospects')->where('id', $contactId)->get()->getRowArray();
        $cfg     = $this->configFor($contact['assigned_group_id'] ?? null);

        return $this->checklist($cfg, $this->confirmedRows(null, $contactId));
    }

    /** @return array<string,mixed> */
    public function checklistForUser(string $organizationId, string $userId, ?string $groupId = null): array
    {
        $cfg = $this->configFor($groupId);

        return $this->checklist($cfg, $this->confirmedRows($userId, null));
    }

    public function isIntegrated(string $organizationId, string $userId, ?string $groupId = null): bool
    {
        $cfg = $this->configFor($groupId);
        if (! $cfg['enabled']) {
            return true; // feature off ⇒ nothing to gate
        }

        return IntegrationDecision::integrationOf(
            $this->confirmedTypes($this->confirmedRows($userId, null)),
            $cfg,
        )['integrated'];
    }

    /**
     * The Journey gate. Returns null when the move is allowed; otherwise the
     * blocked payload the caller surfaces.
     *
     * @return array<string,mixed>|null
     */
    public function gate(string $organizationId, string $userId, string $toStageCode, ?string $groupId = null): ?array
    {
        try {
            $cfg = $this->configFor($groupId);
            if (! $cfg['enabled'] || ! $cfg['gate_journey_advance']) {
                return null;
            }
            if (! IntegrationConfig::isGatedStage($cfg, $toStageCode)) {
                return null;
            }

            $verdict = IntegrationDecision::integrationOf(
                $this->confirmedTypes($this->confirmedRows($userId, null)),
                $cfg,
            );
            if ($verdict['integrated']) {
                return null;
            }

            return [
                'blocked'     => true,
                'integrated'  => false,
                'stage'       => $toStageCode,
                'outstanding' => $verdict['outstanding'],
            ];
        } catch (Throwable) {
            return null; // an optional gate must never brick the journey
        }
    }

    /**
     * A member's own declarations (user-attached), newest first — the "my
     * integration" ledger including pending/rejected, so they can see what is
     * still awaiting their sponsor's confirmation.
     *
     * @return list<array<string,mixed>>
     */
    public function declarationsForUser(string $organizationId, string $userId): array
    {
        return $this->db->table('prospect_decisions')
            ->where('user_id', $userId)
            ->orderBy('recorded_at', 'DESC')
            ->get()->getResultArray();
    }

    /**
     * Pending self-declarations awaiting THIS mentor's confirmation.
     *
     * @return list<array<string,mixed>>
     */
    public function pendingForOwner(string $organizationId, string $ownerId): array
    {
        $contacts = $this->db->table('prospects')
            ->where('organization_id', $organizationId)
            ->where('owner_user_id', $ownerId)
            ->get()->getResultArray();
        $ids      = array_column($contacts, 'id');
        $names    = array_column($contacts, 'full_name', 'id');
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->table('prospect_decisions')
            ->whereIn('prospect_id', $ids)
            ->where('status', 'pending')
            ->get()->getResultArray();

        return array_map(static function (array $r) use ($names) {
            $r['contact_name'] = $names[(string) ($r['prospect_id'] ?? '')] ?? '';

            return $r;
        }, $rows);
    }

    // ---- Internals -------------------------------------------------------------

    private function validate(string $type, string $date): ?Result
    {
        if (! IntegrationDecision::isValidType($type)) {
            return Result::fail(
                'DECISION_TYPE_INVALID',
                'contact.decision_type_invalid',
                422,
                ['allowed' => IntegrationDecision::TYPES],
            );
        }
        if ($date === '' || strtotime($date) === false) {
            return Result::fail('DECISION_DATE_REQUIRED', 'contact.decision_date_required', 422);
        }
        if ($date > $this->clock->now()->format('Y-m-d')) {
            return Result::fail('DECISION_DATE_FUTURE', 'contact.decision_date_future', 422);
        }

        return null;
    }

    private function commit(
        string $organizationId,
        ?string $contactId,
        ?string $userId,
        string $type,
        string $date,
        ?string $note,
        string $source,
        array $cfg,
    ): Result {
        $status = $cfg['self_declaration_requires_confirmation'] ? 'pending' : 'confirmed';

        $row = $this->insertRow($organizationId, $contactId, $userId, $type, $date, $note, $source, $status, null);

        return Result::created($row);
    }

    /** @return array<string,mixed> */
    private function insertRow(
        string $organizationId,
        ?string $contactId,
        ?string $userId,
        string $type,
        string $date,
        ?string $note,
        string $source,
        string $status,
        ?string $sourceRef,
        ?string $recordedBy = null,
    ): array {
        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        $this->db->table('prospect_decisions')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'prospect_id'     => $contactId,
            'user_id'         => $userId,
            'decision_type'   => $type,
            'decision_date'   => $date,
            'target_group_id' => null,
            'note'            => $note,
            'recorded_by'     => $recordedBy,
            'recorded_at'     => $now,
            'status'          => $status,
            'source'          => $source,
            'source_ref'      => $sourceRef,
        ]);

        return [
            'id'            => $id,
            'decision_type' => $type,
            'decision_date' => $date,
            'status'        => $status,
            'source'        => $source,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function checklist(array $cfg, array $rows): array
    {
        $types   = array_values(array_unique(array_column($rows, 'decision_type')));
        $verdict = IntegrationDecision::integrationOf($types, $cfg);

        $byType = [];
        foreach ($rows as $r) {
            $type = (string) ($r['decision_type'] ?? '');
            if ($type !== '' && ! isset($byType[$type])) {
                $byType[$type] = (string) ($r['decision_date'] ?? '');
            }
        }

        return $verdict + [
            'enabled' => $cfg['enabled'],
            'byType'  => $byType,
        ];
    }

    /**
     * Confirmed rows for a CONTACT, or — for a USER — their own rows plus the
     * rows of any contact linked to them (one person, one ledger).
     *
     * @return list<array<string,mixed>>
     */
    private function confirmedRows(?string $userId, ?string $contactId): array
    {
        if ($userId !== null) {
            $rows = $this->db->table('prospect_decisions')
                ->where('user_id', $userId)->where('status', 'confirmed')
                ->get()->getResultArray();

            $linked = $this->db->table('prospects')->where('linked_user_id', $userId)->get()->getResultArray();
            $ids    = array_values(array_filter(array_column($linked, 'id'), 'is_string'));
            if ($ids !== []) {
                $extra = $this->db->table('prospect_decisions')
                    ->whereIn('prospect_id', $ids)->where('status', 'confirmed')
                    ->get()->getResultArray();
                $rows = array_merge($rows, $extra);
            }

            return $rows;
        }

        return $this->db->table('prospect_decisions')
            ->where('prospect_id', $contactId)->where('status', 'confirmed')
            ->get()->getResultArray();
    }

    /** @param list<array<string,mixed>> $rows @return list<string> */
    private function confirmedTypes(array $rows): array
    {
        return array_values(array_filter(array_map(
            static fn ($r) => is_array($r) ? (string) ($r['decision_type'] ?? '') : '',
            $rows,
        )));
    }
}
