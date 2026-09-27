<?php

declare(strict_types=1);

namespace WBS\Events\Controllers;

use WBS\Events\Config\Services as EventServices;
use WBS\Events\Services\CommitteeDecisionService;
use WBS\Events\Services\CommitteeService;
use WBS\Events\Services\EventWorkService;
use WBS\Events\Support\CommitteeOversight;
use WBS\Events\Support\CommitteeResponsibility;
use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Event committees — the browser face of the optional pre-event project-management
 * body: who sits on it, what each member is responsible for, the authority they were
 * delegated, and the decisions waiting on the group leader.
 *
 * AUTHORIZATION. These routes carry `auth` (+ `webcsrf` on writes) and nothing else,
 * which looks loose next to the `authorize:event.*` filters around them, and is
 * deliberate: no single frozen capability bit can express "this event's committee".
 * Every action here is decided in the service, against the event's own group, by the
 * platform's decision point —
 *
 *   form / dissolve / appoint chair   `event.create` over the oversight group
 *   appoint a member                  that, or the chair exercising what they hold
 *   work the plan                     `event.logistics.manage`, or an active seat
 *   decide a request                  the capability the decision itself names
 *
 * — so an active delegation counts (that is how a chair holds authority at all),
 * MAC/SoD/RuBAC applies unchanged, and being merely scoped to a group is never
 * mistaken for the right to govern it. The permission catalogue is untouched: a
 * committee is run entirely with capabilities that already existed.
 *
 * Every page is a self-contained, no-JS PRG console (CSP-friendly), localized in all
 * six locales, and API clients asking for JSON get the same data as data.
 */
final class CommitteeController extends BaseController
{
    /**
     * GET /event-committees — the committee hub: the committees this user sits on,
     * the decisions awaiting them as overseer, what has recently been decided, and
     * their own open tasks across every event plan.
     */
    public function hub()
    {
        $org    = $this->orgId();
        $actor  = $this->actor();
        $groups = $this->committees()->scopeGroupsForUser($org, $actor);

        $data = [
            'committees' => $this->committees()->committeesForUser($org, $actor),
            'pending'    => $this->decisions()->pendingForOversight($org, $actor, 25),
            'history'    => $this->decisions()->queue($org, $groups === null
                ? ['status' => 'all', 'limit' => 25]
                : ['status' => 'all', 'group_ids' => $groups, 'limit' => 25]),
            'myTasks'    => $this->work()->myTasks($org, $actor, false, 25),
            'names'      => [],
        ];
        $data['history'] = array_values(array_filter(
            $data['history'],
            static fn (array $d): bool => (string) ($d['status'] ?? 'pending') !== 'pending',
        ));

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok($data));
        }

        return $this->renderForm('WBS\Events\Views\committee_hub', $data);
    }

    /**
     * GET /event-committees/decisions — the oversight queue, bounded to the groups
     * this actor's own authority reaches (a leader sees their own subtree's
     * committees and nobody else's).
     */
    public function queue()
    {
        $org    = $this->orgId();
        $actor  = $this->actor();
        $groups = $this->committees()->scopeGroupsForUser($org, $actor);
        $status = (string) $this->field('status', 'pending');
        $kind   = (string) $this->field('kind', '');

        $filters = ['status' => $status, 'limit' => 100];
        if ($groups !== null) {
            $filters['group_ids'] = $groups;
        }
        if ($kind !== '') {
            $filters['kind'] = $kind;
        }
        $rows = $this->decisions()->queue($org, $filters);

        $data = [
            'rows'       => $rows,
            'pending'    => array_values(array_filter($rows, static fn (array $r): bool => (string) $r['status'] === 'pending')),
            'status'     => $status,
            'kind'       => $kind,
            'kinds'      => CommitteeOversight::KINDS,
            'statuses'   => CommitteeDecisionService::STATUSES,
            'committees' => $this->committees()->committeesForUser($org, $actor),
        ];

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok($data));
        }

        return $this->renderForm('WBS\Events\Views\committee_queue', $data);
    }

    /**
     * GET /events/{id}/committee — the committee console for one event: the mandate,
     * the members and the authority each was delegated, the lanes nobody holds, the
     * plan's progress, the decisions waiting on the leader, and a no-JS form for
     * every governance act. When the group has not enabled committees the page says
     * so and offers nothing to fill in; when the event has no committee it offers the
     * formation form (to whoever holds `event.create` over the group).
     */
    public function console(string $eventId = '')
    {
        $org       = $this->orgId();
        $actor     = $this->actor();
        $committee = $this->committees()->find($org, $eventId);
        $event     = $this->eventRow($eventId);
        $groupId   = isset($event['group_id']) && $event['group_id'] !== '' ? (string) $event['group_id'] : null;
        $anchor    = $committee !== null && ! empty($committee['oversight_group_id'])
            ? (string) $committee['oversight_group_id'] : $groupId;

        $data = [
            'eventId'     => $eventId,
            'event'       => $event,
            'committee'   => $committee,
            'enabled'     => $this->committees()->enabledFor($anchor),
            'config'      => $this->committees()->configFor($anchor)->toArray(),
            'roster'      => IdentityServices::accounts()->listMembers($org, 'active'),
            'oversight'   => $this->committees()->oversightChoices($org, $groupId),
            'responsibilities' => $this->responsibilityOptions(),
            'decisions'   => $committee !== null
                ? $this->decisions()->queue($org, ['committee_id' => (string) $committee['id'], 'status' => 'all', 'limit' => 50])
                : [],
            'progress'    => $this->work()->progress($org, $eventId),
            'attention'   => $this->work()->atRisk($org, $eventId),
            'myTasks'     => $this->work()->myTasks($org, $actor, false, 20),
            'kinds'       => CommitteeOversight::KINDS,
            'effects'     => array_merge(['none'], CommitteeDecisionService::EFFECTS),
            'actorId'     => $actor,
        ];

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok($data));
        }

        return $this->renderForm('WBS\Events\Views\committee_console', $data);
    }

    // ------------------------------------------------------------------ writes

    /** POST /events/{id}/committee — form the committee (leader's act). */
    public function form(string $eventId = '')
    {
        return $this->respondCommittee(
            $this->committees()->form($this->orgId(), $eventId, $this->actor(), [
                'mandate'            => (string) $this->field('mandate', ''),
                'chair_user_id'      => (string) $this->field('chair_user_id', ''),
                'oversight_group_id' => $this->field('oversight_group_id', null),
            ]),
            $eventId,
            'formedFlash',
        );
    }

    /** POST /events/{id}/committee/dissolve — stand the committee down. */
    public function dissolve(string $eventId = '')
    {
        $committee = $this->committees()->find($this->orgId(), $eventId);
        if ($committee === null) {
            return $this->respondCommittee(Result::notFound('Events.committee.errNotFound', 'COMMITTEE_NOT_FOUND'), $eventId, 'dissolvedFlash');
        }

        return $this->respondCommittee(
            $this->committees()->dissolve(
                $this->orgId(),
                (string) $committee['id'],
                $this->actor(),
                (string) $this->field('reason', ''),
            ),
            $eventId,
            'dissolvedFlash',
        );
    }

    /** POST /events/{id}/committee/members — appoint a member with a responsibility. */
    public function addMember(string $eventId = '')
    {
        $committee = $this->committees()->find($this->orgId(), $eventId);
        if ($committee === null) {
            return $this->respondCommittee(Result::notFound('Events.committee.errNotFound', 'COMMITTEE_NOT_FOUND'), $eventId, 'memberAddedFlash');
        }

        return $this->respondCommittee(
            $this->committees()->addMember($this->orgId(), (string) $committee['id'], $this->actor(), [
                'user_id'        => (string) $this->field('user_id', ''),
                'responsibility' => (string) $this->field('responsibility', CommitteeResponsibility::GENERAL),
                'label'          => $this->field('label', null),
            ]),
            $eventId,
            'memberAddedFlash',
        );
    }

    /** POST /events/{id}/committee/chair — appoint or replace the chair. */
    public function appointChair(string $eventId = '')
    {
        $committee = $this->committees()->find($this->orgId(), $eventId);
        if ($committee === null) {
            return $this->respondCommittee(Result::notFound('Events.committee.errNotFound', 'COMMITTEE_NOT_FOUND'), $eventId, 'chairAppointedFlash');
        }
        $res = $this->committees()->appointChair(
            $this->orgId(),
            (string) $committee['id'],
            $this->actor(),
            (string) $this->field('user_id', ''),
            ['reason' => (string) $this->field('reason', '')],
        );

        // A chair proposal under `chair_requires_approval` is queued, not applied.
        $okKey = ($res->meta['pending_approval'] ?? false) ? 'chairPendingFlash' : 'chairAppointedFlash';

        return $this->respondCommittee($res, $eventId, $okKey);
    }

    /** POST /events/{id}/committee/decisions — record a decision (maker step). */
    public function requestDecision(string $eventId = '')
    {
        $res = $this->decisions()->request($this->orgId(), $eventId, $this->actor(), [
            'kind'   => (string) $this->field('kind', CommitteeOversight::KIND_OTHER),
            'title'  => (string) $this->field('title', ''),
            'detail' => $this->field('detail', null),
            'amount' => $this->field('amount', null),
            'effect' => $this->effectFromInput(),
        ]);
        $okKey = (string) ($res->data['status'] ?? '') === 'noted' ? 'flashNoted' : 'flashRequested';

        return $this->respondCommittee($res, $eventId, $okKey, 'decision');
    }

    /** POST /event-committees/member/{id}/remove — remove a member (or resign). */
    public function removeMember(string $memberId = '')
    {
        $eventId = $this->eventForMember($memberId);
        $res     = $this->committees()->removeMember(
            $this->orgId(),
            $memberId,
            $this->actor(),
            $this->field('reason', null) === null ? null : (string) $this->field('reason', ''),
        );

        return $this->respondCommittee($res, $eventId, 'memberRemovedFlash');
    }

    /** POST /event-committees/member/{id}/responsibility — change a member's lane. */
    public function updateMember(string $memberId = '')
    {
        $eventId = $this->eventForMember($memberId);

        return $this->respondCommittee(
            $this->committees()->updateMember($this->orgId(), $memberId, $this->actor(), [
                'responsibility' => (string) $this->field('responsibility', ''),
                'label'          => $this->field('label', null),
            ]),
            $eventId,
            'memberUpdatedFlash',
        );
    }

    /** POST /event-committees/decisions/{id}/approve — the checker's yes. */
    public function approveDecision(string $decisionId = '')
    {
        return $this->respondDecision(
            $this->decisions()->approve($this->orgId(), $decisionId, $this->actor(), $this->noteFromInput()),
            $decisionId,
            'flashApproved',
        );
    }

    /** POST /event-committees/decisions/{id}/reject — the checker's no, with a reason. */
    public function rejectDecision(string $decisionId = '')
    {
        return $this->respondDecision(
            $this->decisions()->reject($this->orgId(), $decisionId, $this->actor(), $this->noteFromInput()),
            $decisionId,
            'flashRejected',
        );
    }

    /** POST /event-committees/decisions/{id}/cancel — withdraw a request. */
    public function cancelDecision(string $decisionId = '')
    {
        return $this->respondDecision(
            $this->decisions()->cancel($this->orgId(), $decisionId, $this->actor(), $this->noteFromInput()),
            $decisionId,
            'flashCancelled',
        );
    }

    // --------------------------------------------------------------- internals

    private function actor(): string
    {
        return (string) ($this->actorId() ?? '');
    }

    /** @return array<string,mixed>|null */
    private function eventRow(string $eventId): ?array
    {
        // EventService::find() is the read path every other event surface uses.
        $event = EventServices::events()->find($eventId);

        return is_array($event) ? $event : null;
    }

    /** The event a membership row belongs to, for the redirect back. */
    private function eventForMember(string $memberId): string
    {
        return (string) ($this->committees()->eventIdForMember($this->orgId(), $memberId) ?? '');
    }

    /** @return list<array{value:string,label:string,permission:?string}> */
    private function responsibilityOptions(): array
    {
        $out = [];
        foreach (CommitteeResponsibility::ALL as $r) {
            $out[] = [
                'value'      => $r,
                'label'      => lang(CommitteeResponsibility::labelKey($r)),
                'permission' => CommitteeResponsibility::permissionFor($r),
            ];
        }

        return $out;
    }

    /**
     * The optional "action on approval" carried by a decision, assembled from the
     * form's `effect_*` fields. Unknown actions are dropped by the service, which
     * refuses to record a decision promising something it cannot do.
     *
     * @return array<string,mixed>|null
     */
    private function effectFromInput(): ?array
    {
        $action = strtolower(trim((string) $this->field('effect_action', '')));
        if ($action === '' || $action === 'none') {
            return null;
        }
        $effect = ['action' => $action];
        foreach (['user_id', 'responsibility', 'member_id', 'task_id', 'status', 'milestone_id', 'workstream_id', 'evidence', 'due_at', 'blocked_reason'] as $key) {
            $value = $this->field('effect_' . $key, null);
            if ($value !== null && trim((string) $value) !== '') {
                $effect[$key] = (string) $value;
            }
        }
        if ((string) $this->field('effect_force', '') !== '') {
            $effect['force'] = true;
        }

        return $effect;
    }

    private function noteFromInput(): ?string
    {
        $note = $this->field('note', null);

        return $note === null || trim((string) $note) === '' ? null : (string) $note;
    }

    /** PRG back to the event's committee console. */
    private function respondCommittee(Result $result, string $eventId, string $okKey, string $group = 'committee')
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }
        $to = $eventId !== '' ? '/events/' . rawurlencode($eventId) . '/committee' : '/event-committees';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Events.' . $group . '.' . $okKey));
    }

    /** PRG back to wherever the decider came from (queue by default). */
    private function respondDecision(Result $result, string $decisionId, string $okKey)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }
        $returnTo = (string) $this->field('return_to', 'queue');
        $eventId  = (string) $this->field('event_id', '');
        $to       = $returnTo === 'committee' && $eventId !== ''
            ? '/events/' . rawurlencode($eventId) . '/committee'
            : '/event-committees/decisions';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Events.decision.' . $okKey));
    }

    /** Never trust CI4 getSharedInstance — it returns null on GET /event-committees. */
    private function committees(): CommitteeService
    {
        $svc = EventServices::eventCommittees(false);

        return $svc instanceof CommitteeService ? $svc : CommitteeService::boot();
    }

    private function decisions(): CommitteeDecisionService
    {
        $svc = EventServices::committeeDecisions(false);

        return $svc instanceof CommitteeDecisionService ? $svc : CommitteeDecisionService::boot();
    }

    private function work(): EventWorkService
    {
        $svc = EventServices::eventWork(false);

        return $svc instanceof EventWorkService ? $svc : EventWorkService::boot();
    }
}

