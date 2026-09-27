<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WBS\Audit\Services\AuditLogger;
use WBS\Events\Support\CommitteeConfig;
use WBS\Events\Support\CommitteeOversight;
use WBS\Events\Support\CommitteeResponsibility;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\ScopeMode;
use WBS\Shared\Support\Uuid;

/**
 * Event committees — formation, membership and the authority each member holds.
 *
 * An optional body that runs an event as a project: a chairperson plus members,
 * each with a specific responsibility, under the oversight of the organizing
 * group's leader. It is additive: an event with no committee behaves exactly as
 * before, and this service never touches the day-of roster, the budget or the
 * readiness gate — the committee works through those surfaces with delegated
 * authority.
 *
 * THREE rules hold the design together:
 *
 *  1. GATED BY HIERARCHICAL GROUP CONFIG, DEFAULT OFF. Capability
 *     `event_committee` (resolved through the module's existing config port, so it
 *     inherits down the tree like every other group capability). With no row
 *     anywhere on the chain the feature is off and `form()` refuses — no env, no
 *     global, no code flag.
 *
 *  2. SCOPE IS THE EVENT'S. The committee's anchor is `events.group_id`,
 *     snapshotted as `scope_group_id`, and oversight belongs to
 *     `oversight_group_id` (normally the same group; a regional event run by a
 *     local assembly may be overseen one level up). Everything derived — who may
 *     form it, who may approve its decisions, how far a member's delegation
 *     reaches — resolves through the one hierarchy tree. An org-wide event
 *     (group_id NULL) must name an oversight group at formation, because
 *     "overseen by the group leader" needs a group.
 *
 *  3. AUTHORITY IS DELEGATION, BOUNDED AND REVOCABLE. No new permission bits: a
 *     responsibility maps to ONE existing `event.*` capability
 *     ({@see CommitteeResponsibility}) which is delegated to the member through
 *     {@see CommitteeAuthorityPort} → the ACL's DelegationService, so containment,
 *     depth, duration and "you cannot delegate what you do not hold" are enforced
 *     there. The window is the event itself plus a configured hand-over grace, and
 *     removal, dissolution or close-out revokes it — cascading to anything the
 *     member sub-delegated, because a derived authority cannot outlive its source.
 *
 * Approval-side segregation of duties survives the committee: responsibilities
 * delegate SUBMIT-side bits only (`event.expense.submit`, never `.approve`), so a
 * committee that spends still meets the leader's approval on the other side.
 */
final class CommitteeService
{
    /** Event statuses in which a committee may still be formed (pre-event phase). */
    private const FORMABLE_STATUSES = ['draft', 'published'];

    /** DelegationService caps a delegation at a year. */
    private const MAX_DELEGATION_DAYS = 365;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly EventConfigPort $config,
        private readonly ?GroupScopeResolver $scope = null,
        private readonly ?CommitteeAuthorityPort $authority = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * Construct without CI4 getSharedInstance (returns null when Events
     * Services::eventCommittees is missing from the host discovery cache).
     */
    public static function boot(): self
    {
        return new self(
            \Config\Database::connect(),
            \WBS\Shared\Config\Services::clock(),
            new EffectiveConfigAdapter(\WBS\Admin\Config\Services::effectiveConfig()),
            \WBS\Shared\Config\Services::groupScope(),
            new DelegationAuthorityAdapter(
                \WBS\AccessControl\Config\Services::delegations(),
                \WBS\AccessControl\Config\Services::authorization(),
            ),
            \WBS\Audit\Config\Services::auditLogger(),
        );
    }

    // ---------------------------------------------------------------- config

    /**
     * The committee capability as it resolves for a group (or OFF when the group
     * is unknown / nothing is configured). Fail-closed on any error.
     */
    public function configFor(?string $groupId): CommitteeConfig
    {
        if ($groupId === null || $groupId === '') {
            return CommitteeConfig::off();
        }

        try {
            return CommitteeConfig::fromResolved($this->config->value($groupId, CommitteeConfig::CAPABILITY));
        } catch (Throwable) {
            return CommitteeConfig::off();
        }
    }

    public function enabledFor(?string $groupId): bool
    {
        return $this->configFor($groupId)->enabled;
    }

    // ------------------------------------------------------------ formation

    /**
     * Form a committee for an event (the leader's act).
     *
     * @param array<string,mixed> $data mandate (required), chair_user_id,
     *                                  oversight_group_id, members (list of
     *                                  {user_id, responsibility, label})
     */
    public function form(string $organizationId, string $eventId, string $actorId, array $data): Result
    {
        $event = $this->event($organizationId, $eventId);
        if ($event === null) {
            return Result::notFound('Events.committee.errEventNotFound', 'EVENT_NOT_FOUND');
        }

        $mandate = trim((string) ($data['mandate'] ?? ''));
        if ($mandate === '') {
            // A committee exists FOR something: the mandate is its purpose, and
            // purpose is mandatory on every delegation derived from it.
            return Result::fail('MANDATE_REQUIRED', 'Events.committee.errMandateRequired', 422);
        }

        $eventGroup   = isset($event['group_id']) && $event['group_id'] !== '' ? (string) $event['group_id'] : null;
        $oversight    = $this->pickOversightGroup($organizationId, $data['oversight_group_id'] ?? null, $eventGroup);
        if ($oversight === null) {
            return Result::fail('OVERSIGHT_GROUP_REQUIRED', 'Events.committee.errOversightGroupRequired', 422);
        }

        $cfg = $this->configFor($oversight);
        if (! $cfg->enabled) {
            return Result::fail('COMMITTEES_DISABLED', 'Events.committee.errDisabled', 403, [
                'capability' => CommitteeConfig::CAPABILITY,
                'group_id'   => $oversight,
            ]);
        }

        // One committee per event (the UNIQUE key says so; this says it nicely).
        $existing = $this->db->table('event_committees')
            ->where('organization_id', $organizationId)->where('event_id', $eventId)
            ->get()->getRowArray();
        if ($existing !== null) {
            return Result::ok([
                'committee_id' => (string) $existing['id'],
                'status'       => (string) $existing['status'],
            ], 200, ['deduplicated' => true]);
        }

        if (! in_array((string) $event['status'], self::FORMABLE_STATUSES, true)) {
            return Result::fail('BAD_EVENT_STATE', 'Events.committee.errBadEventState', 409, ['status' => (string) $event['status']]);
        }

        // Forming a committee is an organizing act over the oversight group: the
        // actor must HOLD `event.create` there, decided by the PDP (so an active
        // delegation counts, and being merely scoped to the group does not).
        if (! $this->holdsGovernance($organizationId, $actorId, $oversight)) {
            return Result::denied('Events.committee.errNotAuthorized', 'NOT_AUTHORIZED');
        }

        $window = $this->authorityWindow($event, $cfg->graceDays);
        if ($window === null) {
            return Result::fail('EVENT_WINDOW_CLOSED', 'Events.committee.errWindowClosed', 409);
        }

        $now = $this->clock->nowUtcMicro();
        $id  = Uuid::v7();
        $chairId = trim((string) ($data['chair_user_id'] ?? ''));

        $this->db->table('event_committees')->insert([
            'id'                  => $id,
            'organization_id'     => $organizationId,
            'event_id'            => $eventId,
            'scope_group_id'      => $eventGroup,
            'oversight_group_id'  => $oversight,
            'chair_user_id'       => $chairId !== '' ? $chairId : null,
            'formed_by'           => $actorId,
            'mandate'             => mb_substr($mandate, 0, 500),
            'oversight_mode'      => $cfg->oversight,
            'max_members'         => $cfg->maxMembers,
            'allow_subdelegation' => $cfg->allowSubdelegation ? 1 : 0,
            'grace_days'          => $cfg->graceDays,
            'status'              => 'active',
            'created_at'          => $now,
            'created_by'          => $actorId,
        ]);

        $members = [];
        $rows    = is_array($data['members'] ?? null) ? (array) $data['members'] : [];
        if ($chairId !== '') {
            // The chair is a MEMBER whose lane is chairing (or their own lane);
            // listed first so the delegation chain reads leader → chair → members.
            array_unshift($rows, ['user_id' => $chairId, 'responsibility' => CommitteeResponsibility::CHAIR]);
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $res = $this->seatMember($organizationId, [
                'id'                 => $id,
                'event_id'           => $eventId,
                'oversight_group_id' => $oversight,
                'chair_user_id'      => $chairId !== '' ? $chairId : null,
            ], $actorId, [
                'user_id'        => (string) ($row['user_id'] ?? ''),
                'responsibility' => (string) ($row['responsibility'] ?? CommitteeResponsibility::GENERAL),
                'label'          => isset($row['label']) ? (string) $row['label'] : null,
                'is_chair'       => (string) ($row['user_id'] ?? '') === $chairId,
            ], $window, $cfg, (string) $event['title']);
            if ($res->ok) {
                $members[] = $res->data;
            }
        }

        $this->audit?->record($organizationId, [
            'action'      => 'event.committee.formed',
            'actor_id'    => $actorId,
            'actor_type'  => 'user',
            'object_type' => 'event_committee',
            'object_id'   => $id,
            'outcome'     => 'success',
            'metadata'    => [
                'event_id'           => $eventId,
                'scope_group_id'     => $eventGroup,
                'oversight_group_id' => $oversight,
                'chair_user_id'      => $chairId !== '' ? $chairId : null,
                'oversight_mode'     => $cfg->oversight,
                'members'            => count($members),
                'effective_to'       => $window['to'],
            ],
        ]);

        return Result::created([
            'committee_id'       => $id,
            'event_id'           => $eventId,
            'chair_user_id'      => $chairId !== '' ? $chairId : null,
            'oversight_group_id' => $oversight,
            'oversight_mode'     => $cfg->oversight,
            'effective_to'       => $window['to'],
            'members'            => $members,
        ]);
    }

    /**
     * Dissolve a committee: every member's delegated authority is revoked (which
     * cascades to their sub-delegations) and open decisions are cancelled. The rows
     * stay — a dissolved committee is history, not a deletion.
     */
    public function dissolve(string $organizationId, string $committeeId, string $actorId, string $reason): Result
    {
        $committee = $this->findCommittee($organizationId, $committeeId);
        if ($committee === null) {
            return Result::notFound('Events.committee.errNotFound', 'COMMITTEE_NOT_FOUND');
        }
        if ((string) $committee['status'] !== 'active') {
            return Result::ok(['committee_id' => $committeeId, 'status' => (string) $committee['status']], 200, ['deduplicated' => true]);
        }
        $reason = trim($reason);
        if ($reason === '') {
            return Result::fail('REASON_REQUIRED', 'Events.committee.errReasonRequired', 422);
        }
        if (! $this->mayGovern($organizationId, $committee, $actorId)) {
            return Result::denied('Events.committee.errNotAuthorized', 'NOT_AUTHORIZED');
        }

        $revoked = $this->revokeAllAuthority($organizationId, $committeeId, $actorId, $reason);

        $now = $this->clock->nowUtcMicro();
        $this->db->table('event_committee_decisions')
            ->where('committee_id', $committeeId)->where('status', 'pending')
            ->update([
                'status'       => 'cancelled',
                'decided_by'   => $actorId,
                'decided_at'   => $now,
                'decision_note' => mb_substr($reason, 0, 500),
                'updated_at'   => $now,
            ]);
        $this->db->table('event_committees')->where('id', $committeeId)->update([
            'status'             => 'dissolved',
            'dissolved_at'       => $now,
            'dissolved_by'       => $actorId,
            'dissolution_reason' => mb_substr($reason, 0, 500),
            'updated_at'         => $now,
            'updated_by'         => $actorId,
        ]);

        $this->audit?->record($organizationId, [
            'action'      => 'event.committee.dissolved',
            'actor_id'    => $actorId,
            'actor_type'  => 'user',
            'object_type' => 'event_committee',
            'object_id'   => $committeeId,
            'outcome'     => 'success',
            'metadata'    => ['reason' => mb_substr($reason, 0, 500), 'delegations_revoked' => $revoked],
        ]);

        return Result::ok(['committee_id' => $committeeId, 'status' => 'dissolved', 'delegations_revoked' => $revoked]);
    }

    // ----------------------------------------------------------- membership

    /**
     * Appoint a member (chair or otherwise) and hand them the bounded authority
     * their responsibility carries.
     *
     * @param array<string,mixed> $data user_id (required), responsibility, label
     */
    public function addMember(string $organizationId, string $committeeId, string $actorId, array $data): Result
    {
        $committee = $this->findCommittee($organizationId, $committeeId);
        if ($committee === null) {
            return Result::notFound('Events.committee.errNotFound', 'COMMITTEE_NOT_FOUND');
        }
        if ((string) $committee['status'] !== 'active') {
            return Result::fail('COMMITTEE_DISSOLVED', 'Events.committee.errDissolved', 409);
        }

        $userId = trim((string) ($data['user_id'] ?? ''));
        if ($userId === '') {
            return Result::fail('USER_REQUIRED', 'Events.committee.errUserRequired', 422);
        }
        if (! $this->userExists($organizationId, $userId)) {
            return Result::notFound('Events.committee.errUserNotFound', 'USER_NOT_FOUND');
        }

        $oversight = isset($committee['oversight_group_id']) && $committee['oversight_group_id'] !== ''
            ? (string) $committee['oversight_group_id'] : null;
        $cfg       = $this->configFor($oversight);
        if (! $cfg->enabled) {
            return Result::fail('COMMITTEES_DISABLED', 'Events.committee.errDisabled', 403);
        }

        $leader  = $this->holdsGovernance($organizationId, $actorId, $oversight);
        // A chair appointing into their own committee exercises the authority they
        // were delegated, so they must still actually hold it.
        $isChair = $this->isChairOf($committee, $actorId)
            && $this->holds($organizationId, $actorId, 'event.logistics.manage', $oversight);
        if (! $leader && ! $isChair) {
            return Result::denied('Events.committee.errNotAuthorized', 'NOT_AUTHORIZED');
        }

        // A chair appointing on the leader's behalf is sub-delegation of the
        // governance act itself, and the group can switch it off.
        if (! $leader && ! $cfg->allowSubdelegation) {
            return Result::fail('SUBDELEGATION_DISABLED', 'Events.committee.errSubdelegationDisabled', 403);
        }

        $event = $this->event($organizationId, (string) $committee['event_id']);
        if ($event === null) {
            return Result::notFound('Events.committee.errEventNotFound', 'EVENT_NOT_FOUND');
        }
        $window = $this->authorityWindow($event, (int) ($committee['grace_days'] ?? $cfg->graceDays));
        if ($window === null) {
            return Result::fail('EVENT_WINDOW_CLOSED', 'Events.committee.errWindowClosed', 409);
        }

        $responsibility = CommitteeResponsibility::normalize((string) ($data['responsibility'] ?? CommitteeResponsibility::GENERAL));
        $isChairRow     = ! empty($data['is_chair']) || $userId === (string) ($committee['chair_user_id'] ?? '');

        return $this->seatMember($organizationId, $committee, $actorId, [
            'user_id'        => $userId,
            'responsibility' => $responsibility,
            'label'          => isset($data['label']) ? (string) $data['label'] : null,
            'is_chair'       => $isChairRow,
        ], $window, $cfg, (string) $event['title']);
    }

    /**
     * Remove a member (or accept their resignation) and revoke the authority they
     * held — cascading to anything they sub-delegated.
     */
    public function removeMember(string $organizationId, string $memberId, string $actorId, ?string $reason = null): Result
    {
        $member = $this->db->table('event_committee_members')
            ->where('organization_id', $organizationId)->where('id', $memberId)
            ->get()->getRowArray();
        if ($member === null) {
            return Result::notFound('Events.committee.errMemberNotFound', 'MEMBER_NOT_FOUND');
        }
        if ((string) $member['status'] !== 'active') {
            return Result::ok(['member_id' => $memberId, 'status' => (string) $member['status']], 200, ['deduplicated' => true]);
        }

        $committee = $this->findCommittee($organizationId, (string) $member['committee_id']);
        $resigning = (string) $member['user_id'] === $actorId;
        if (! $resigning && ($committee === null || ! $this->mayGovern($organizationId, $committee, $actorId))) {
            return Result::denied('Events.committee.errNotAuthorized', 'NOT_AUTHORIZED');
        }
        // The chair cannot be removed without a successor decision: a committee
        // without a chair has nobody accountable for its plan.
        if ((int) ($member['is_chair'] ?? 0) === 1 && ! $resigning) {
            $others = $this->db->table('event_committee_members')
                ->where('committee_id', (string) $member['committee_id'])
                ->where('status', 'active')
                ->countAllResults();
            if ($others > 1) {
                return Result::fail('CHAIR_MUST_BE_REPLACED', 'Events.committee.errChairMustBeReplaced', 409);
            }
            // Last member standing: removing the chair dissolves the committee.
            return $this->dissolve($organizationId, (string) $member['committee_id'], $actorId, $reason !== null && trim($reason) !== '' ? $reason : 'chair_removed');
        }

        $now    = $this->clock->nowUtcMicro();
        $reason = trim((string) ($reason ?? ($resigning ? 'resigned' : 'removed')));

        $revoked = $this->revokeAuthority($organizationId, $member, $actorId, $reason);
        $this->db->table('event_committee_members')->where('id', $memberId)->update([
            'status'         => 'removed',
            'removed_by'     => $actorId,
            'removed_at'     => $now,
            'removal_reason' => mb_substr($reason, 0, 500),
            'updated_at'     => $now,
        ]);

        $this->audit?->record($organizationId, [
            'action'      => $resigning ? 'event.committee.member.resigned' : 'event.committee.member.removed',
            'actor_id'    => $actorId,
            'actor_type'  => 'user',
            'object_type' => 'event_committee_member',
            'object_id'   => $memberId,
            'outcome'     => 'success',
            'metadata'    => [
                'committee_id'  => (string) $member['committee_id'],
                'user_id'       => (string) $member['user_id'],
                'responsibility' => (string) $member['responsibility'],
                'delegation_id' => $member['delegation_id'] ?? null,
                'revoked'       => $revoked,
            ],
        ]);

        return Result::ok(['member_id' => $memberId, 'status' => 'removed', 'delegation_revoked' => $revoked]);
    }

    /**
     * Change a member's responsibility: the old delegation is revoked and a new one
     * created for the capability the new responsibility carries, so authority always
     * matches the lane somebody actually holds.
     *
     * @param array<string,mixed> $data responsibility, label
     */
    public function updateMember(string $organizationId, string $memberId, string $actorId, array $data): Result
    {
        $member = $this->db->table('event_committee_members')
            ->where('organization_id', $organizationId)->where('id', $memberId)
            ->get()->getRowArray();
        if ($member === null) {
            return Result::notFound('Events.committee.errMemberNotFound', 'MEMBER_NOT_FOUND');
        }
        if ((string) $member['status'] !== 'active') {
            return Result::fail('MEMBER_INACTIVE', 'Events.committee.errMemberInactive', 409, ['status' => (string) $member['status']]);
        }
        $committee = $this->findCommittee($organizationId, (string) $member['committee_id']);
        if ($committee === null || ! $this->mayGovern($organizationId, $committee, $actorId)) {
            return Result::denied('Events.committee.errNotAuthorized', 'NOT_AUTHORIZED');
        }

        $next = CommitteeResponsibility::normalize((string) ($data['responsibility'] ?? $member['responsibility']));
        if ($next === (string) $member['responsibility'] && ! array_key_exists('label', $data)) {
            return Result::ok(['member_id' => $memberId, 'responsibility' => $next], 200, ['unchanged' => true]);
        }
        if ($next === CommitteeResponsibility::CHAIR && (int) ($member['is_chair'] ?? 0) !== 1) {
            // Chairing is an appointment, not a lane you can move somebody into.
            return Result::fail('USE_APPOINT_CHAIR', 'Events.committee.errUseAppointChair', 409);
        }

        $now = $this->clock->nowUtcMicro();
        $this->revokeAuthority($organizationId, $member, $actorId, 'responsibility_changed');
        $this->db->table('event_committee_members')->where('id', $memberId)->update([
            'responsibility'       => $next,
            'responsibility_label' => isset($data['label']) ? mb_substr(trim((string) $data['label']), 0, 120) : ($member['responsibility_label'] ?? null),
            'delegation_id'        => null,
            'delegated_permission' => null,
            'updated_at'           => $now,
        ]);

        // Re-delegate for the new lane, inside the same window.
        $oversight = isset($committee['oversight_group_id']) && $committee['oversight_group_id'] !== '' ? (string) $committee['oversight_group_id'] : null;
        $cfg       = $this->configFor($oversight);
        $event     = $this->event($organizationId, (string) $committee['event_id']);
        if ($event !== null && $cfg->enabled) {
            $window = $this->authorityWindow($event, (int) ($committee['grace_days'] ?? $cfg->graceDays));
            if ($window !== null) {
                $this->delegateForMember($organizationId, $committee, $memberId, (string) $member['user_id'], $next, $window, $cfg, $oversight, (string) $event['title'], $actorId);
            }
        }

        $this->audit?->record($organizationId, [
            'action'      => 'event.committee.member.updated',
            'actor_id'    => $actorId,
            'actor_type'  => 'user',
            'object_type' => 'event_committee_member',
            'object_id'   => $memberId,
            'outcome'     => 'success',
            'metadata'    => ['from' => (string) $member['responsibility'], 'to' => $next],
        ]);

        return Result::ok(['member_id' => $memberId, 'responsibility' => $next]);
    }

    /**
     * Appoint (or replace) the chair.
     *
     * The leader whose scope covers the oversight group appoints directly. A chair
     * proposing a successor is a governance decision: when the group's config says
     * the chair needs approval, the proposal is queued for the leader instead of
     * taking effect, and `CommitteeDecisionService` applies it on approval.
     *
     * @param array<string,mixed> $opts authorized_by_decision (set by the decision
     *                                  service once the leader has approved)
     */
    public function appointChair(string $organizationId, string $committeeId, string $actorId, string $userId, array $opts = []): Result
    {
        $committee = $this->findCommittee($organizationId, $committeeId);
        if ($committee === null) {
            return Result::notFound('Events.committee.errNotFound', 'COMMITTEE_NOT_FOUND');
        }
        if ((string) $committee['status'] !== 'active') {
            return Result::fail('COMMITTEE_DISSOLVED', 'Events.committee.errDissolved', 409);
        }
        if (trim($userId) === '') {
            return Result::fail('USER_REQUIRED', 'Events.committee.errUserRequired', 422);
        }
        if (! $this->userExists($organizationId, $userId)) {
            return Result::notFound('Events.committee.errUserNotFound', 'USER_NOT_FOUND');
        }

        $oversight = isset($committee['oversight_group_id']) && $committee['oversight_group_id'] !== '' ? (string) $committee['oversight_group_id'] : null;
        $cfg       = $this->configFor($oversight);
        $leader    = $this->holdsGovernance($organizationId, $actorId, $oversight);
        $preAuth   = isset($opts['authorized_by_decision']) && $opts['authorized_by_decision'] !== '';

        if (! $leader && ! $preAuth) {
            if (! $this->isChairOf($committee, $actorId)) {
                return Result::denied('Events.committee.errNotAuthorized', 'NOT_AUTHORIZED');
            }
            if ($cfg->chairRequiresApproval) {
                // Queue it: the leader decides, the chair does not self-succeed.
                $decisionId = $this->openDecision($organizationId, $committee, $actorId, [
                    'kind'        => CommitteeOversight::KIND_GOVERNANCE,
                    'title'       => 'appoint_chair',
                    'detail'      => trim((string) ($opts['reason'] ?? '')) !== '' ? trim((string) $opts['reason']) : null,
                    'effect_json' => ['action' => 'chair.appoint', 'user_id' => $userId],
                ]);

                return Result::ok([
                    'committee_id' => $committeeId,
                    'status'       => 'pending_oversight',
                    'decision_id'  => $decisionId,
                    'proposed_chair' => $userId,
                ], 202, ['pending_approval' => true]);
            }
            if (! $cfg->allowSubdelegation) {
                return Result::fail('SUBDELEGATION_DISABLED', 'Events.committee.errSubdelegationDisabled', 403);
            }
        }

        $event  = $this->event($organizationId, (string) $committee['event_id']);
        $window = $event !== null ? $this->authorityWindow($event, (int) ($committee['grace_days'] ?? $cfg->graceDays)) : null;
        if ($window === null) {
            return Result::fail('EVENT_WINDOW_CLOSED', 'Events.committee.errWindowClosed', 409);
        }

        $now     = $this->clock->nowUtcMicro();
        $previous = (string) ($committee['chair_user_id'] ?? '');

        // The outgoing chair keeps their membership but loses the chair flag (and
        // with it the chair lane's delegation, which is re-cut for the successor).
        if ($previous !== '' && $previous !== $userId) {
            $old = $this->db->table('event_committee_members')
                ->where('committee_id', $committeeId)->where('user_id', $previous)->where('status', 'active')
                ->get()->getRowArray();
            if ($old !== null) {
                $this->revokeAuthority($organizationId, $old, $actorId, 'chair_replaced');
                $this->db->table('event_committee_members')->where('id', (string) $old['id'])->update([
                    'is_chair'             => 0,
                    'responsibility'       => CommitteeResponsibility::GENERAL,
                    'delegation_id'        => null,
                    'delegated_permission' => null,
                    'updated_at'           => $now,
                ]);
            }
        }

        // The successor becomes a member (or is promoted) with the chair lane.
        $row = $this->db->table('event_committee_members')
            ->where('committee_id', $committeeId)->where('user_id', $userId)
            ->get()->getRowArray();
        if ($row === null) {
            $added = $this->addMember($organizationId, $committeeId, $actorId, [
                'user_id'        => $userId,
                'responsibility' => CommitteeResponsibility::CHAIR,
                'is_chair'       => true,
            ]);
            if (! $added->ok) {
                return $added;
            }
        } else {
            $this->revokeAuthority($organizationId, $row, $actorId, 'chair_appointed');
            $this->db->table('event_committee_members')->where('id', (string) $row['id'])->update([
                'is_chair'       => 1,
                'responsibility' => CommitteeResponsibility::CHAIR,
                'status'         => 'active',
                'updated_at'     => $now,
            ]);
            $this->delegateForMember(
                $organizationId,
                $committee,
                (string) $row['id'],
                $userId,
                CommitteeResponsibility::CHAIR,
                $window,
                $cfg,
                $oversight,
                (string) ($event['title'] ?? ''),
                $actorId,
            );
        }

        $this->db->table('event_committees')->where('id', $committeeId)->update([
            'chair_user_id' => $userId,
            'updated_at'    => $now,
            'updated_by'    => $actorId,
        ]);

        $this->audit?->record($organizationId, [
            'action'      => 'event.committee.chair.appointed',
            'actor_id'    => $actorId,
            'actor_type'  => 'user',
            'object_type' => 'event_committee',
            'object_id'   => $committeeId,
            'outcome'     => 'success',
            'metadata'    => [
                'chair_user_id'          => $userId,
                'previous_chair_user_id' => $previous !== '' ? $previous : null,
                'authorized_by_decision' => $preAuth ? (string) $opts['authorized_by_decision'] : null,
                'effective_to'           => $window['to'],
            ],
        ]);

        return Result::ok([
            'committee_id'  => $committeeId,
            'chair_user_id' => $userId,
            'previous_chair_user_id' => $previous !== '' ? $previous : null,
            'effective_to'  => $window['to'],
        ]);
    }

    // ---------------------------------------------------------------- reads

    /** The committee for an event with its active members and plan counts. */
    public function find(string $organizationId, string $eventId): ?array
    {
        $row = $this->db->table('event_committees')
            ->where('organization_id', $organizationId)->where('event_id', $eventId)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        return $this->decorate($organizationId, $row);
    }

    /** @return array<string,mixed>|null */
    public function findCommittee(string $organizationId, string $committeeId): ?array
    {
        $row = $this->db->table('event_committees')
            ->where('organization_id', $organizationId)->where('id', $committeeId)
            ->get()->getRowArray();

        return $row;
    }

    /**
     * Committee + members + oversight counts, shaped for the console/API.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public function decorate(string $organizationId, array $row): array
    {
        $id      = (string) $row['id'];
        $members = $this->db->table('event_committee_members')
            ->where('committee_id', $id)
            ->orderBy('is_chair', 'DESC')->orderBy('created_at', 'ASC')
            ->get()->getResultArray();

        $active = array_values(array_filter($members, static fn ($m): bool => (string) ($m['status'] ?? '') === 'active'));
        $pending = (int) $this->db->table('event_committee_decisions')
            ->where('committee_id', $id)->where('status', 'pending')->countAllResults();

        // Names for the console: one lookup, no N+1.
        $names = $this->displayNames($organizationId, array_values(array_filter(array_map(
            static fn ($m): ?string => isset($m['user_id']) ? (string) $m['user_id'] : null,
            $members,
        ))));
        foreach ($members as $i => $m) {
            $members[$i]['user_name'] = $names[(string) ($m['user_id'] ?? '')] ?? null;
        }
        foreach ($active as $i => $m) {
            $active[$i]['user_name'] = $names[(string) ($m['user_id'] ?? '')] ?? null;
        }

        $event = $this->event($organizationId, (string) ($row['event_id'] ?? ''));
        $row['event_title']         = (string) ($event['title'] ?? '');
        $row['event_status']        = (string) ($event['status'] ?? '');
        $row['chair_name']          = $names[(string) ($row['chair_user_id'] ?? '')] ?? null;
        $row['members']             = $members;
        $row['active_members']      = $active;
        $row['member_count']        = count($active);
        $row['pending_decisions']   = $pending;
        $row['responsibilities']    = $this->responsibilityCoverage($active);
        $row['config']              = $this->configFor(isset($row['oversight_group_id']) ? (string) $row['oversight_group_id'] : null)->toArray();

        return $row;
    }

    public function isMember(string $organizationId, string $eventId, string $userId): bool
    {
        return $this->memberRow($organizationId, $eventId, $userId) !== null;
    }

    public function isChair(string $organizationId, string $eventId, string $userId): bool
    {
        $row = $this->memberRow($organizationId, $eventId, $userId);

        return $row !== null && (int) ($row['is_chair'] ?? 0) === 1;
    }

    /**
     * The event a membership row belongs to (so a controller can redirect back after
     * acting on a member id alone).
     */
    public function eventIdForMember(string $organizationId, string $memberId): ?string
    {
        $row = $this->db->table('event_committee_members')
            ->select('event_id')
            ->where('organization_id', $organizationId)->where('id', $memberId)
            ->get()->getRowArray();

        return $row !== null ? (string) $row['event_id'] : null;
    }

    /** @return array<string,mixed>|null the user's active membership for an event */
    public function memberRow(string $organizationId, string $eventId, string $userId): ?array
    {
        $now = $this->clock->nowUtcString();

        return $this->db->table('event_committee_members')
            ->where('organization_id', $organizationId)
            ->where('event_id', $eventId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where('effective_to >=', $now)
            ->orderBy('is_chair', 'DESC')
            ->limit(1)
            ->get()->getRowArray();
    }

    /**
     * Committees this user sits on (active memberships), newest first — the "my
     * committees" list a member lands on.
     *
     * @return list<array<string,mixed>>
     */
    public function committeesForUser(string $organizationId, string $userId, int $limit = 50): array
    {
        $memberships = $this->db->table('event_committee_members')
            ->select('committee_id, event_id, responsibility, is_chair, effective_to')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->orderBy('created_at', 'DESC')
            ->limit(max(1, min($limit, 200)))
            ->get()->getResultArray();
        if ($memberships === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map(static fn ($m): string => (string) $m['committee_id'], $memberships)));
        $rows = $this->db->table('event_committees')
            ->whereIn('id', $ids)
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();
        $byId = [];
        foreach ($rows as $r) {
            $byId[(string) $r['id']] = $r;
        }

        // Event titles in one pass, so the hub can render a useful list.
        $eventIds = array_values(array_unique(array_map(static fn ($m): string => (string) $m['event_id'], $memberships)));
        $titles   = [];
        if ($eventIds !== []) {
            foreach ($this->db->table('events')->select('id, title, status, starts_at')->whereIn('id', $eventIds)->get()->getResultArray() as $e) {
                $titles[(string) $e['id']] = $e;
            }
        }

        $out = [];
        foreach ($memberships as $m) {
            $c = $byId[(string) $m['committee_id']] ?? null;
            if ($c === null || (string) ($c['status'] ?? '') !== 'active') {
                continue;
            }
            $e = $titles[(string) $m['event_id']] ?? null;
            $out[] = [
                'committee_id'     => (string) $m['committee_id'],
                'event_id'         => (string) $m['event_id'],
                'event_title'      => (string) ($e['title'] ?? ''),
                'event_status'     => (string) ($e['status'] ?? ''),
                'event_starts_at'  => $e['starts_at'] ?? null,
                'responsibility'   => (string) $m['responsibility'],
                'is_chair'         => (int) ($m['is_chair'] ?? 0) === 1,
                'effective_to'     => $m['effective_to'] ?? null,
                'mandate'          => (string) ($c['mandate'] ?? ''),
                'oversight_group_id' => $c['oversight_group_id'] ?? null,
            ];
        }

        return $out;
    }

    // --------------------------------------------------------------- scope

    /**
     * The groups this user's authority reaches: the union of their active role
     * assignments AND active delegations, each resolved through the one scope
     * model. NULL means org-wide (unbounded).
     *
     * Delegations count because that is exactly how a chair or member holds
     * authority here — ignoring them would make every committee act look
     * unauthorized to the very people the leader empowered.
     *
     * @return list<string>|null
     */
    public function scopeGroupsForUser(string $organizationId, string $userId): ?array
    {
        if ($this->scope === null) {
            return null; // no resolver wired: unbounded (legacy behaviour)
        }

        $now  = $this->clock->nowUtcString();
        $all  = [];

        foreach ([['role_assignments', 'subject_id', 'role_assignment'], ['delegations', 'delegate_id', 'delegation']] as [$table, $subjectCol, $grantType]) {
            $rows = $this->db->table($table)
                ->select('id, scope_group_id, scope_mode, include_descendants, include_crosscut')
                ->where('organization_id', $organizationId)
                ->where($subjectCol, $userId)
                ->where('status', 'active')
                ->groupStart()->where('effective_from <=', $now)->orWhere('effective_from', null)->groupEnd()
                ->groupStart()->where('effective_to >', $now)->orWhere('effective_to', null)->groupEnd()
                ->get()->getResultArray();

            foreach ($rows as $r) {
                $scopeId = isset($r['scope_group_id']) && $r['scope_group_id'] !== '' ? (string) $r['scope_group_id'] : null;
                $mode    = ScopeMode::normalize($r['scope_mode'] ?? null, $r['include_descendants'] ?? null);
                $set     = $mode === ScopeMode::GROUPS ? $this->grantGroupSet($grantType, (string) $r['id']) : [];

                $resolved = $this->scope->resolveScopeGroups($scopeId, $mode, $set, ! empty($r['include_crosscut']));
                if ($resolved === null) {
                    return null; // an org-wide grant ⇒ unbounded
                }
                foreach ($resolved as $g) {
                    $all[$g] = true;
                }
            }
        }

        return array_keys($all);
    }

    /**
     * True when the user's grants/delegations REACH $groupId.
     *
     * This is a READ bound — used to decide which rows a queue may list — never an
     * authorization decision on its own: reaching a group says nothing about which
     * capabilities a user holds there. Writes ask the PDP through holds().
     */
    public function scopeCovers(string $organizationId, string $userId, ?string $groupId): bool
    {
        if ($groupId === null || $groupId === '') {
            // Nothing to cover: only an org-wide authority may act here.
            return $this->scopeGroupsForUser($organizationId, $userId) === null;
        }
        $groups = $this->scopeGroupsForUser($organizationId, $userId);
        if ($groups === null) {
            return true;
        }

        return in_array($groupId, $groups, true);
    }

    // ----------------------------------------------------------- lifecycle

    /**
     * Expire memberships whose window has passed (sweep hook). Authority is
     * revoked with the row, so a committee member cannot keep acting after the
     * event they were appointed for has gone.
     *
     * @return array{scanned:int,expired:int,revoked:int}
     */
    public function expireDue(?string $organizationId = null, int $limit = 500): array
    {
        $now  = $this->clock->nowUtcString();
        $q    = $this->db->table('event_committee_members')
            ->where('status', 'active')
            ->where('effective_to <', $now);
        if ($organizationId !== null && $organizationId !== '') {
            $q->where('organization_id', $organizationId);
        }
        $rows = $q->limit(max(1, min($limit, 2000)))->get()->getResultArray();

        $expired = 0;
        $revoked = 0;
        foreach ($rows as $row) {
            $org = (string) ($row['organization_id'] ?? $organizationId ?? '');
            if ($this->revokeAuthority($org, $row, (string) ($row['user_id'] ?? ''), 'window_expired')) {
                $revoked++;
            }
            $this->db->table('event_committee_members')->where('id', (string) $row['id'])->update([
                'status'         => 'expired',
                'removed_at'     => $this->clock->nowUtcMicro(),
                'removal_reason' => 'window_expired',
                'updated_at'     => $this->clock->nowUtcMicro(),
            ]);
            $expired++;
        }

        return ['scanned' => count($rows), 'expired' => $expired, 'revoked' => $revoked];
    }

    /**
     * Close-out hook (called by EventCloser): dissolve the committee and revoke
     * every member's authority, so nothing an event committee was empowered to do
     * outlives the event. Idempotent.
     *
     * @return array{dissolved:bool,delegations_revoked:int}
     */
    public function onEventClosed(string $organizationId, string $eventId, ?string $actorId = null): array
    {
        $row = $this->db->table('event_committees')
            ->where('organization_id', $organizationId)->where('event_id', $eventId)
            ->get()->getRowArray();
        if ($row === null || (string) $row['status'] !== 'active') {
            return ['dissolved' => false, 'delegations_revoked' => 0];
        }

        $actor  = $actorId ?? (string) ($row['formed_by'] ?? '');
        $revoked = $this->revokeAllAuthority($organizationId, (string) $row['id'], $actor, 'event_closed');
        $now    = $this->clock->nowUtcMicro();

        $this->db->table('event_committee_decisions')
            ->where('committee_id', (string) $row['id'])->where('status', 'pending')
            ->update(['status' => 'cancelled', 'decided_at' => $now, 'decision_note' => 'event_closed', 'updated_at' => $now]);
        $this->db->table('event_committees')->where('id', (string) $row['id'])->update([
            'status'             => 'dissolved',
            'dissolved_at'       => $now,
            'dissolved_by'       => $actor !== '' ? $actor : null,
            'dissolution_reason' => 'event_closed',
            'updated_at'         => $now,
        ]);

        $this->audit?->record($organizationId, [
            'action'      => 'event.committee.dissolved',
            'actor_id'    => $actor !== '' ? $actor : null,
            'actor_type'  => 'user',
            'object_type' => 'event_committee',
            'object_id'   => (string) $row['id'],
            'outcome'     => 'success',
            'metadata'    => ['reason' => 'event_closed', 'event_id' => $eventId, 'delegations_revoked' => $revoked],
        ]);

        return ['dissolved' => true, 'delegations_revoked' => $revoked];
    }

    // ------------------------------------------------------------- internals

    /**
     * Insert a member row and cut the delegation that carries their authority.
     * Shared by form(), addMember() and the chair paths so the window, purpose and
     * scope of every member's authority are computed one way.
     *
     * @param array<string,mixed> $window   {to, days}
     * @param array<string,mixed> $committee
     *
     * @return Result data: {member_id, user_id, responsibility, is_chair, delegation_id, effective_to}
     */
    private function seatMember(
        string $organizationId,
        array $committee,
        string $actorId,
        array $data,
        array $window,
        CommitteeConfig $cfg,
        string $eventTitle,
    ): Result {
        $committeeId = (string) ($committee['id'] ?? '');
        $oversight   = isset($committee['oversight_group_id']) && $committee['oversight_group_id'] !== ''
            ? (string) $committee['oversight_group_id'] : null;
        $addedBy     = $actorId;

        $userId         = trim((string) ($data['user_id'] ?? ''));
        $responsibility = CommitteeResponsibility::normalize((string) ($data['responsibility'] ?? CommitteeResponsibility::GENERAL));
        $isChair        = ! empty($data['is_chair']);

        // Head-count limit from config, counted over ACTIVE members only.
        $active = (int) $this->db->table('event_committee_members')
            ->where('committee_id', $committeeId)->where('status', 'active')->countAllResults();
        if ($active >= max(2, $cfg->maxMembers)) {
            return Result::fail('COMMITTEE_FULL', 'Events.committee.errFull', 409, ['max_members' => $cfg->maxMembers]);
        }

        $now  = $this->clock->nowUtcMicro();
        $id   = Uuid::v7();
        $label = isset($data['label']) && trim((string) $data['label']) !== '' ? mb_substr(trim((string) $data['label']), 0, 120) : null;

        // A returned member (removed/expired earlier) is reactivated rather than
        // duplicated: the UNIQUE key is (committee, user), one seat per person.
        $existing = $this->db->table('event_committee_members')
            ->where('committee_id', $committeeId)->where('user_id', $userId)
            ->get()->getRowArray();

        $delegation = $this->delegateForMember(
            $organizationId,
            $committee,
            $existing !== null ? (string) $existing['id'] : $id,
            $userId,
            $responsibility,
            $window,
            $cfg,
            $oversight,
            $eventTitle,
            $actorId,
        );

        $payload = [
            'responsibility'       => $responsibility,
            'responsibility_label' => $label,
            'is_chair'             => $isChair ? 1 : 0,
            'delegation_id'        => $delegation['delegation_id'],
            'delegated_permission' => $delegation['permission'],
            'scope_mode'           => ScopeMode::SELF,
            'scope_group_id'       => $oversight,
            'effective_from'       => $now,
            'effective_to'         => $window['to'],
            'status'               => 'active',
            'updated_at'           => $now,
        ];

        if ($existing !== null) {
            $this->db->table('event_committee_members')->where('id', (string) $existing['id'])->update($payload + [
                'added_by'       => $addedBy,
                'removed_by'     => null,
                'removed_at'     => null,
                'removal_reason' => null,
            ]);
            $memberId = (string) $existing['id'];
        } else {
            $this->db->table('event_committee_members')->insert($payload + [
                'id'              => $id,
                'organization_id' => $organizationId,
                'committee_id'    => $committeeId,
                'event_id'        => (string) ($committee['event_id'] ?? ''),
                'user_id'         => $userId,
                'added_by'        => $addedBy,
                'created_at'      => $now,
            ]);
            $memberId = $id;
        }

        if ($isChair) {
            $this->db->table('event_committees')->where('id', $committeeId)->update([
                'chair_user_id' => $userId,
                'updated_at'    => $now,
                'updated_by'    => $actorId,
            ]);
        }

        $this->audit?->record($organizationId, [
            'action'      => 'event.committee.member.added',
            'actor_id'    => $actorId,
            'actor_type'  => 'user',
            'object_type' => 'event_committee_member',
            'object_id'   => $memberId,
            'outcome'     => $delegation['delegation_id'] !== null || $delegation['permission'] === null ? 'success' : 'partial',
            'metadata'    => [
                'committee_id'         => $committeeId,
                'user_id'              => $userId,
                'responsibility'       => $responsibility,
                'is_chair'             => $isChair,
                'delegation_id'        => $delegation['delegation_id'],
                'delegated_permission' => $delegation['permission'],
                'authority_error'      => $delegation['error'],
                'effective_to'         => $window['to'],
            ],
        ]);

        return Result::created([
            'member_id'            => $memberId,
            'user_id'              => $userId,
            'responsibility'       => $responsibility,
            'is_chair'             => $isChair,
            'delegation_id'        => $delegation['delegation_id'],
            'delegated_permission' => $delegation['permission'],
            'authority_error'      => $delegation['error'],
            'effective_to'         => $window['to'],
        ]);
    }

    /**
     * Create the member's bounded delegation. A failure here never blocks the
     * appointment (the seat and the plan still exist) but it IS recorded, both on
     * the row's audit trail and in the returned error, because a member without
     * authority can plan but cannot act — and the leader must be able to see why.
     *
     * @param array<string,mixed> $committee
     * @param array<string,mixed> $window
     *
     * @return array{delegation_id:?string,permission:?string,effective_to:?string,error:?string}
     */
    private function delegateForMember(
        string $organizationId,
        array $committee,
        string $memberId,
        string $userId,
        string $responsibility,
        array $window,
        CommitteeConfig $cfg,
        ?string $oversight,
        string $eventTitle,
        string $actorId,
    ): array {
        $permission = CommitteeResponsibility::permissionFor($responsibility);
        if ($permission === null || $this->authority === null) {
            return ['delegation_id' => null, 'permission' => $permission, 'effective_to' => $window['to'] ?? null, 'error' => null];
        }

        // The delegator is whoever is appointing: the leader from their own grant,
        // or the chair sub-delegating authority they were themselves given.
        $delegator = $actorId;
        $res = $this->authority->delegate($organizationId, $delegator, $userId, $permission, [
            'purpose'          => mb_substr(sprintf('Event committee (%s): %s', $eventTitle !== '' ? $eventTitle : (string) ($committee['event_id'] ?? ''), CommitteeResponsibility::normalize($responsibility)), 0, 500),
            'duration_days'    => (int) ($window['days'] ?? 1),
            'scope_group_id'   => $oversight,
            'scope_mode'       => ScopeMode::SELF,
            'include_crosscut' => $cfg->allowCrosscut,
        ]);

        if (! $res->ok) {
            return [
                'delegation_id' => null,
                'permission'    => $permission,
                'effective_to'  => $window['to'] ?? null,
                'error'         => (string) ($res->code ?? 'DELEGATION_FAILED'),
            ];
        }

        $delegationId = isset($res->data['delegation_id']) ? (string) $res->data['delegation_id'] : null;
        $this->db->table('event_committee_members')->where('id', $memberId)->update([
            'delegation_id'        => $delegationId,
            'delegated_permission' => $permission,
            'effective_to'         => isset($res->data['effective_to']) ? (string) $res->data['effective_to'] : ($window['to'] ?? null),
        ]);

        return [
            'delegation_id' => $delegationId,
            'permission'    => $permission,
            'effective_to'  => isset($res->data['effective_to']) ? (string) $res->data['effective_to'] : ($window['to'] ?? null),
            'error'         => null,
        ];
    }

    /** Revoke one member's delegation. Returns true when a revocation happened. */
    private function revokeAuthority(string $organizationId, array $member, string $actorId, string $reason): bool
    {
        $delegationId = isset($member['delegation_id']) && $member['delegation_id'] !== '' ? (string) $member['delegation_id'] : null;
        if ($delegationId === null || $this->authority === null) {
            return false;
        }
        $res = $this->authority->revoke($organizationId, $actorId !== '' ? $actorId : (string) ($member['user_id'] ?? ''), $delegationId, $reason);
        $this->db->table('event_committee_members')->where('id', (string) $member['id'])->update(['delegation_id' => null]);

        return $res->ok;
    }

    /** Revoke every active member's authority for a committee. */
    private function revokeAllAuthority(string $organizationId, string $committeeId, string $actorId, string $reason): int
    {
        $rows = $this->db->table('event_committee_members')
            ->where('committee_id', $committeeId)->where('status', 'active')
            ->get()->getResultArray();

        $revoked = 0;
        $now     = $this->clock->nowUtcMicro();
        foreach ($rows as $row) {
            if ($this->revokeAuthority($organizationId, $row, $actorId, $reason)) {
                $revoked++;
            }
            $this->db->table('event_committee_members')->where('id', (string) $row['id'])->update([
                'status'         => 'removed',
                'removed_by'     => $actorId !== '' ? $actorId : null,
                'removed_at'     => $now,
                'removal_reason' => mb_substr($reason, 0, 500),
                'updated_at'     => $now,
            ]);
        }

        return $revoked;
    }

    /**
     * Queue a governance decision for the leader (the request side of the
     * oversight queue; deciding it lives in CommitteeDecisionService).
     *
     * @param array<string,mixed> $committee
     * @param array<string,mixed> $data kind, title, detail, effect_json
     */
    private function openDecision(string $organizationId, array $committee, string $actorId, array $data): ?string
    {
        $kind = CommitteeOversight::normalizeKind((string) ($data['kind'] ?? CommitteeOversight::KIND_GOVERNANCE));
        $id   = Uuid::v7();
        $now  = $this->clock->nowUtcMicro();

        $this->db->table('event_committee_decisions')->insert([
            'id'                  => $id,
            'organization_id'     => $organizationId,
            'event_id'            => (string) ($committee['event_id'] ?? ''),
            'committee_id'        => (string) ($committee['id'] ?? ''),
            'kind'                => $kind,
            'title'               => mb_substr((string) ($data['title'] ?? $kind), 0, 200),
            'detail'              => isset($data['detail']) && $data['detail'] !== '' ? (string) $data['detail'] : null,
            'amount'              => isset($data['amount']) && is_numeric($data['amount']) ? (float) $data['amount'] : null,
            'required_permission' => CommitteeOversight::permissionForKind($kind),
            'oversight_group_id'  => $committee['oversight_group_id'] ?? null,
            'requested_by'        => $actorId,
            'status'              => 'pending',
            'effect_json'         => isset($data['effect_json']) ? json_encode($data['effect_json']) : null,
            'created_at'          => $now,
        ]);

        return $id;
    }

    /**
     * The delegation window: now → the event's end (or start, when open-ended)
     * plus the configured hand-over grace, clamped to what the ACL allows.
     * NULL when the window has already closed.
     *
     * @param array<string,mixed> $event
     *
     * @return array{to:string,days:int}|null
     */
    private function authorityWindow(array $event, int $graceDays): ?array
    {
        // ends_at when the event has one, otherwise its start: the window is the
        // event plus the hand-over grace, never open-ended.
        $end = trim((string) ($event['ends_at'] ?? '')) !== '' ? (string) $event['ends_at'] : trim((string) ($event['starts_at'] ?? ''));
        if ($end === '') {
            return null;
        }

        try {
            $now  = $this->clock->now();
            $to   = new DateTimeImmutable($end, new DateTimeZone('UTC'));
            $to   = $to->modify('+' . max(0, min(90, $graceDays)) . ' days');
        } catch (Throwable) {
            return null;
        }

        $days = (int) ceil(($to->getTimestamp() - $now->getTimestamp()) / 86400);
        if ($days < 1) {
            return null; // the event (plus grace) is already behind us
        }

        return ['to' => $to->format('Y-m-d H:i:s'), 'days' => min(self::MAX_DELEGATION_DAYS, $days)];
    }

    /**
     * May this actor govern the committee? Either they hold `event.create` over the
     * oversight group (the leader, or someone the leader delegated it to), or they
     * are the chair and still hold the authority chairing carries.
     *
     * @param array<string,mixed> $committee
     */
    private function mayGovern(string $organizationId, array $committee, string $actorId): bool
    {
        $oversight = isset($committee['oversight_group_id']) && $committee['oversight_group_id'] !== '' ? (string) $committee['oversight_group_id'] : null;
        if ($this->holdsGovernance($organizationId, $actorId, $oversight)) {
            return true;
        }

        return $this->isChairOf($committee, $actorId)
            && $this->holds($organizationId, $actorId, 'event.logistics.manage', $oversight);
    }

    /**
     * Governance authority: `event.create` over a group, decided by the platform's
     * PDP. Scope coverage alone is NOT authority — a user scoped to a group may hold
     * only check-in there — so every governance act asks the decision point, which
     * applies MAC, SoD, RuBAC denies, the scope model and active delegations.
     */
    private function holdsGovernance(string $organizationId, string $actorId, ?string $groupId): bool
    {
        return $this->holds($organizationId, $actorId, 'event.create', $groupId);
    }

    private function holds(string $organizationId, string $actorId, string $permission, ?string $groupId): bool
    {
        return $this->authority !== null && $this->authority->holds($organizationId, $actorId, $permission, $groupId);
    }

    /** @param array<string,mixed> $committee */
    private function isChairOf(array $committee, string $userId): bool
    {
        return $userId !== '' && (string) ($committee['chair_user_id'] ?? '') === $userId;
    }

    /**
     * The oversight group: an explicit choice (validated to exist), else the
     * event's own group. NULL when neither is available — an org-wide event with
     * no named oversight group cannot have a committee, because there would be no
     * leader accountable for it.
     */
    private function pickOversightGroup(string $organizationId, mixed $requested, ?string $eventGroup): ?string
    {
        $want = trim((string) ($requested ?? ''));
        if ($want !== '') {
            $exists = $this->db->table('groups')
                ->where('organization_id', $organizationId)->where('id', $want)
                ->countAllResults();

            return $exists > 0 ? $want : null;
        }

        return $eventGroup;
    }

    /**
     * Which responsibilities are covered by active members — the chair's view of
     * "who is doing what", and where a lane is still empty.
     *
     * @param list<array<string,mixed>> $members
     *
     * @return array{filled:list<string>,vacant:list<string>,by_responsibility:array<string,list<string>>}
     */
    private function responsibilityCoverage(array $members): array
    {
        $byResp = [];
        foreach ($members as $m) {
            $r = CommitteeResponsibility::normalize((string) ($m['responsibility'] ?? ''));
            $byResp[$r][] = (string) ($m['user_id'] ?? '');
        }

        return [
            'filled'            => array_keys($byResp),
            'vacant'            => array_values(array_diff(CommitteeResponsibility::ALL, array_keys($byResp))),
            'by_responsibility' => $byResp,
        ];
    }

    /** @return array<string,mixed>|null */
    private function event(string $organizationId, string $eventId): ?array
    {
        return $this->db->table('events')
            ->where('organization_id', $organizationId)->where('id', $eventId)
            ->get()->getRowArray();
    }

    /**
     * The groups a committee may be overseen by: the event's own group and its
     * ANCESTORS only (never a sibling or a descendant — oversight comes from above),
     * each with its name and how far up it sits. An org-wide event has no group, so
     * the leader picks one explicitly at formation.
     *
     * @return list<array{id:?string,name:string,distance:int}>
     */
    public function oversightChoices(string $organizationId, ?string $groupId): array
    {
        if ($groupId === null || $groupId === '') {
            // No anchor: offer the groups this org actually has, nearest the top
            // first, so an org-wide event can still name an overseer.
            $rows = $this->db->table('groups')->select('id, name, parent_id')
                ->where('organization_id', $organizationId)
                ->orderBy('name', 'ASC')->limit(200)->get()->getResultArray();
            $out = [];
            foreach ($rows as $r) {
                $out[] = ['id' => (string) $r['id'], 'name' => (string) ($r['name'] ?? ''), 'distance' => 0];
            }

            return $out;
        }

        $ids = array_merge([$groupId], $this->scope !== null ? $this->scope->ancestors($groupId) : []);
        $rows = $this->db->table('groups')->select('id, name')->whereIn('id', $ids)->get()->getResultArray();
        $names = [];
        foreach ($rows as $r) {
            $names[(string) $r['id']] = (string) ($r['name'] ?? '');
        }

        $out = [];
        foreach ($ids as $distance => $id) {
            if ($id === null || ! isset($names[$id])) {
                continue; // dissolved/archived groups are dropped by the resolver
            }
            $out[] = ['id' => $id, 'name' => $names[$id], 'distance' => $distance];
        }

        return $out;
    }

    /**
     * Resolve display names in one query (the console and hub need people, not ids).
     *
     * @param list<string> $userIds
     *
     * @return array<string,string>
     */
    public function displayNames(string $organizationId, array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $userIds), static fn ($v): bool => $v !== '')));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->table('users')->select('id, display_name')->whereIn('id', $ids)->get()->getResultArray() as $u) {
            $out[(string) $u['id']] = (string) ($u['display_name'] ?? '');
        }

        return $out;
    }

    private function userExists(string $organizationId, string $userId): bool
    {
        return $this->db->table('users')
            ->where('organization_id', $organizationId)->where('id', $userId)
            ->countAllResults() > 0;
    }

    /** @return list<string> hand-picked group set for a GROUPS-mode grant */
    private function grantGroupSet(string $grantType, string $grantId): array
    {
        $rows = $this->db->table('grant_scope_groups')
            ->select('group_id')
            ->where('grant_type', $grantType)
            ->where('grant_id', $grantId)
            ->get()->getResultArray();

        return array_values(array_map(static fn ($r): string => (string) $r['group_id'], $rows));
    }
}
