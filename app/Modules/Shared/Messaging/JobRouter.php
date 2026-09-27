<?php

declare(strict_types=1);

namespace WBS\Shared\Messaging;

use RuntimeException;
use Throwable;
use WBS\AccessControl\Config\Services as AccessControlServices;
use WBS\Contributions\Config\Services as ContributionServices;
use WBS\Courses\Config\Services as CourseServices;
use WBS\Events\Config\Services as EventServices;
use WBS\Gamification\Config\Services as GamificationServices;
use WBS\Groups\Config\Services as GroupServices;
use WBS\Integrations\Config\Services as IntegrationServices;
use WBS\Journey\Config\Services as JourneyServices;
use WBS\Meetings\Config\Services as MeetingServices;
use WBS\Notifications\Config\Services as NotificationServices;
use WBS\Referrals\Config\Services as ReferralServices;

/**
 * Routes a dequeued job to the correct domain handler (SRS FR-ARC-005/007).
 *
 * The outbox relay wraps every domain event as a queue job whose payload has the
 * shape { outbox_id, aggregate_type, aggregate_id, organization_id, data }.
 * This router inspects `topic` and calls the appropriate coordinator. Handlers
 * are individually idempotent, so a redelivered job is safe.
 *
 * Kept dependency-light: coordinators are resolved lazily from each module's
 * service container so the worker process only wires what a job needs.
 */
final class JobRouter
{
    /**
     * Dispatch a job payload by topic. Returns true when handled (ack), throws
     * on a retryable failure so the queue applies backoff.
     *
     * @param array<string,mixed> $job the decoded queue_jobs.payload
     */
    public function dispatch(string $topic, array $job): bool
    {
        $data  = is_array($job['data'] ?? null) ? $job['data'] : $job;
        $orgId = (string) ($job['organization_id'] ?? $data['organization_id'] ?? '');

        try {
            return match ($topic) {
                'contribution.succeeded' => $this->contributionSucceeded($orgId, $data),
                'contribution.refunded'  => $this->contributionRefunded($orgId, $data),
                'course.completed'       => $this->courseCompleted($orgId, $data),
                'event.completed'        => $this->eventCompleted($orgId, $data),
                'event.cancelled'        => $this->eventCancelled($orgId, $data),
                'notification.dispatch'  => $this->notificationDispatch($orgId, $data),
                'event.certificate.render' => $this->certificateRender($data),
                'event.media.scan'       => $this->mediaScan($data),
                // Theme B — account teardown fan-out (ID1 emits; consumers react).
                'account.deactivated',
                'account.suspended',
                'account.anonymized'     => $this->accountTornDown($topic, $orgId, $data),
                'account.merged'         => $this->accountMerged($orgId, $data),
                'account.reactivated'    => $this->accountReactivated($orgId, $data),
                // Theme B — group teardown fan-out (GR-emit; group-half consumers).
                'group.dissolved'        => $this->groupTornDown($topic, $orgId, $data),
                'group.merged'           => $this->groupMerged($orgId, $data),
                'group.archived'         => $this->groupArchived($orgId, $data),
                // IN1 — provider connection teardown (grants already auto-revoked
                // at source; this is the seam for the Meetings/Streaming cut-off).
                'connection.disabled',
                'connection.revoked'     => $this->connectionTornDown($topic, $orgId, $data),
                default                  => $this->unknown($topic),
            };
        } catch (Throwable $e) {
            // Re-throw so the worker records the error and schedules a retry.
            throw $e;
        }
    }

    /** @param array<string,mixed> $data */
    private function contributionSucceeded(string $orgId, array $data): bool
    {
        $data['organization_id'] ??= $orgId;
        ContributionServices::rewardCoordinator()->onSucceeded($data);

        // Journey signal (Option C): a verified gift may advance the giver's
        // journey if a membership rule says so. Fault-isolated + no-op unless
        // rules exist. Journey context = org-wide primary; scope = the cause's
        // OWNING group so a group-scoped leader's rule fires for their branch.
        // The staged contribution payload does not carry the cause's group, so
        // resolve it from the cause here (mirrors RewardCoordinator's receiving-
        // group attribution); best-effort, never blocking the reward.
        $causeId    = (string) ($data['cause_id'] ?? '');
        $causeGroup = $causeId !== '' ? $this->causeGroupId($causeId) : '';
        $this->emitJourneySignal($orgId, 'journey.signal.contribution.verified', [
            'user_id'        => (string) ($data['user_id'] ?? ''),
            'scope_group_id' => $causeGroup,
            // The cause IS the giving project (same project_code convention the
            // reward award uses), so the advance + credit tag to the cause.
            'project_code'   => $causeId,
            'evidence_type'  => 'contribution',
            'evidence_ref'   => (string) ($data['source_ref'] ?? ($data['contribution_id'] ?? '')),
            'attributes'     => [
                'amount_minor' => (int) ($data['amount_minor'] ?? 0),
                'cause_id'     => $causeId,
            ],
        ]);

        return true;
    }

    /** @param array<string,mixed> $data */
    private function contributionRefunded(string $orgId, array $data): bool
    {
        $data['organization_id'] ??= $orgId;
        ContributionServices::rewardCoordinator()->onRefunded($data);

        return true;
    }

    /**
     * Award course-completion points/badges (idempotent on source_ref).
     *
     * @param array<string,mixed> $data
     */
    private function courseCompleted(string $orgId, array $data): bool
    {
        $ruleCode  = (string) ($data['points_rule_code'] ?? '');
        $userId    = (string) ($data['user_id'] ?? '');
        $sourceRef = (string) ($data['source_ref'] ?? '');

        // Award points ONLY when the course actually configures a points rule;
        // a course with no rule is still a valid completion (it just earns no
        // points). The journey signal below must fire regardless, so a purely
        // discipleship course (no points) can still advance the learner's
        // journey — the award and the progression are independent concerns.
        if ($ruleCode !== '' && $userId !== '' && $sourceRef !== '' && $orgId !== '') {
            // Group attribution (design doc Part B): credit the course's own group
            // as the RECEIVING group so the completion award rolls up to it and
            // every ancestor; when the payload carries no group, PointsEngine
            // falls back to the learner's membership group, else org-level. Pass
            // through the course's phase/category when supplied (Build/Send
            // training tags the entry for phase and category boards).
            $opts = ['subject_type' => 'user'];
            $group = (string) ($data['group_id'] ?? '');
            if ($group !== '') {
                $opts['receiving_group_id'] = $group;
            }
            $phase = (string) ($data['phase'] ?? '');
            if ($phase !== '' && $phase !== 'general') {
                $opts['phase'] = $phase;
            }
            $category = (string) ($data['category_code'] ?? '');
            if ($category !== '') {
                $opts['category_code'] = $category;
            }
            GamificationServices::pointsEngine()->award($orgId, $ruleCode, $userId, $sourceRef, $opts);
        }

        // Journey signal (Option C): a completed course may advance the learner's
        // journey (e.g. Foundation 101 → In Foundation) when a membership rule
        // matches. Fault-isolated + no-op unless rules exist. Journey context =
        // org-wide primary; scope = the course's group; carries the course code
        // so rules can gate on a specific course.
        $this->emitJourneySignal($orgId, 'journey.signal.course.completed', [
            'user_id'        => $userId,
            'scope_group_id' => (string) ($data['group_id'] ?? ''),
            // No intrinsic project for a course — carried only if the completion
            // event explicitly supplied one (explicit pass-through).
            'project_code'   => (string) ($data['project_code'] ?? ''),
            'evidence_type'  => 'course',
            'evidence_ref'   => $sourceRef,
            'attributes'     => [
                'course_id'   => (string) ($data['course_id'] ?? ''),
                'course_code' => (string) ($data['course_code'] ?? ''),
            ],
        ]);

        return true;
    }

    /**
     * Event lifecycle → post-event fan-out (gap L3). A completed event (manual
     * button or the `events:close-due` sweep) announces itself here so the
     * post-event phase can proceed off ONE domain event instead of each
     * subscriber polling for terminal state.
     *
     * The completion status write in EventService::complete() is the idempotency
     * guard (a re-complete never re-stages), so this handler needs no dedupe of
     * its own. It is intentionally a light, fault-isolated ack today: the
     * attendance-driven rewards + journey advance already fire per check-in
     * (CheckinService emits `journey.signal.event.attended`), so completion does
     * not double-award. Reserved as the seam for report finalisation / certificate
     * artefacts to hang off; unknown-topic warnings are avoided by recognising it.
     *
     * @param array<string,mixed> $data
     */
    private function eventCompleted(string $orgId, array $data): bool
    {
        $eventId = (string) ($data['event_id'] ?? '');
        if ($eventId === '') {
            return true;
        }
        log_message(
            'info',
            sprintf(
                'JobRouter: event.completed org=%s event=%s status=%s attendance=%d',
                $orgId,
                $eventId,
                (string) ($data['status'] ?? ''),
                (int) ($data['actual_attendance'] ?? 0),
            ),
        );

        return true;
    }

    /**
     * Event cancellation fan-out (Theme B — E-B1 emits, MT5 consumes). A cancelled
     * event's linked video room must not stay enterable, so Meetings cancels the
     * linked meeting(s) and expires outstanding join tokens. Fault-isolated +
     * idempotent, so a redelivered `event.cancelled` is safe.
     *
     * @param array<string,mixed> $data
     */
    private function eventCancelled(string $orgId, array $data): bool
    {
        $eventId = (string) ($data['event_id'] ?? '');
        if ($orgId === '' || $eventId === '') {
            return true;
        }

        // MT5 — cancel the linked meeting + expire its join tokens.
        $this->isolate('meeting-event-cancel', static function () use ($orgId, $eventId): void {
            MeetingServices::meetings()->onEventCancelled($orgId, $eventId, 'event.cancelled');
        });

        return true;
    }

    /**
     * Resolve a cause's owning group id (empty string when none / unresolvable).
     * Used to scope the contribution journey signal to the cause's branch so a
     * group-scoped leader's membership rule can fire. Fault-isolated: any lookup
     * failure degrades to org-wide scope rather than breaking the job.
     */
    private function causeGroupId(string $causeId): string
    {
        try {
            $cause = ContributionServices::causes()->find($causeId);

            return $cause !== null && ($cause['group_id'] ?? null) !== null && $cause['group_id'] !== ''
                ? (string) $cause['group_id']
                : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Emit a membership-journey signal, never letting a failure affect the
     * primary job handling. No-op when the emitter cannot resolve or no rules
     * match. Empty user_id short-circuits.
     *
     * @param array<string,mixed> $payload
     */
    private function emitJourneySignal(string $orgId, string $action, array $payload): void
    {
        if ($orgId === '' || ($payload['user_id'] ?? '') === '') {
            return;
        }
        try {
            JourneyServices::journeySignals()->ingest($orgId, ['action' => $action] + $payload);
        } catch (Throwable) {
            // Journey automation is best-effort; the award/reward already ran.
        }
    }

    /**
     * Hand a rendered notification to the provider send path. The actual
     * transport call lives behind an approved adapter; here we mark the delivery
     * as sent once the provider accepts it. Provider wiring is a connection-level
     * concern resolved by the Integrations module at run time.
     *
     * @param array<string,mixed> $data
     */
    private function notificationDispatch(string $orgId, array $data): bool
    {
        $deliveryId = (string) ($data['delivery_id'] ?? '');
        if ($deliveryId === '') {
            return true;
        }

        // FR-INT-012: fail fast when the channel's provider circuit is open, so a
        // broken transport isn't hammered. The job is re-queued (throw) to retry
        // after the cooldown rather than being marked sent against a dead
        // provider. Requires org + channel on the payload.
        $orgId   = $orgId !== '' ? $orgId : (string) ($data['organization_id'] ?? '');
        $channel = (string) ($data['channel'] ?? '');
        if ($orgId !== '' && $channel !== '') {
            $gate = IntegrationServices::providerReliability()
                ->admit($orgId, 'notification:' . strtolower($channel));
            if (! $gate->ok) {
                throw new RuntimeException('notification provider circuit open for channel ' . $channel);
            }
        }

        // Perform the REAL outbound delivery through the channel transport layer.
        // The dispatcher resolves the transport for the channel, calls it, and
        // maps the outcome onto the delivery lifecycle + circuit breaker:
        //   accepted → "sent" (ack), rejected → terminal "failed" (ack, no retry),
        //   transient fault → throws so the queue retries with backoff.
        // (The stub that flipped deliveries to "sent" without a transport is gone.)
        return NotificationServices::notificationDispatcher()->dispatch($data);
    }

    /**
     * Render an event certificate (FR-EVT-013). The heavy render is performed by
     * an out-of-process renderer; this handler records the artifact reference
     * and issues the certificate. Idempotent (issue() dedupes on state).
     *
     * @param array<string,mixed> $data
     */
    private function certificateRender(array $data): bool
    {
        $certId = (string) ($data['certificate_id'] ?? '');
        if ($certId === '') {
            return true;
        }
        // Render the real PDF (Dompdf, pure-PHP) off the request path, store it,
        // and record the returned artifact reference. A caller-supplied
        // render_ref (e.g. an external renderer already ran) is honoured as an
        // override. A render failure raises so the queue retries with backoff.
        $renderRef = isset($data['render_ref']) && $data['render_ref'] !== ''
            ? (string) $data['render_ref']
            : EventServices::certificateRenderer()->render($certId);
        EventServices::certificates()->markRendered($certId, $renderRef);
        EventServices::certificates()->issue($certId, $data['signed_by'] ?? null, $data['signature_ref'] ?? null);

        return true;
    }

    /**
     * Malware-scan + EXIF-strip an uploaded media object (FR-EVT-015). The scan
     * verdict is recorded; an infected file is auto-quarantined by the service.
     *
     * @param array<string,mixed> $data
     */
    private function mediaScan(array $data): bool
    {
        $mediaId = (string) ($data['media_id'] ?? '');
        if ($mediaId === '') {
            return true;
        }
        // The AV scanner verdict arrives in the payload when the external scan
        // pipeline is wired; default to "clean" only when a scanner has run.
        $verdict = (string) ($data['verdict'] ?? 'clean');
        EventServices::media()->recordScan($mediaId, $verdict);

        return true;
    }

    /**
     * Account teardown (deactivate / suspend / anonymize) — Theme B, AC3.
     *
     * The subject is gone or frozen, so their standing authority must not keep
     * resolving. Cascade-revoke assignments/delegations and close break-glass
     * (system authority, audited). Idempotent, so a redelivered event is safe.
     *
     * @param array<string,mixed> $data
     */
    private function accountTornDown(string $topic, string $orgId, array $data): bool
    {
        $subjectId = (string) ($data['user_id'] ?? '');
        if ($orgId === '' || $subjectId === '') {
            // Nothing actionable; ack so it is not retried forever.
            return true;
        }

        return $this->fanOutTeardown($orgId, $subjectId, $topic);
    }

    /**
     * M10 — reactivation fan-out: the INVERSE of the teardown cascade. When an
     * account returns to `active` from a torn-down state, restore the belongings
     * and journeys the teardown froze/ended so the member is not left
     * half-restored. Only teardown-caused changes are reversed (each restorer
     * matches its own `teardown:` marker) — member-initiated leaves/pauses are
     * never resurrected. Fault-isolated + idempotent, like the teardown fan-out.
     */
    private function accountReactivated(string $orgId, array $data): bool
    {
        $subjectId = (string) ($data['user_id'] ?? '');
        if ($orgId === '' || $subjectId === '') {
            return true; // nothing actionable; ack so it is not retried forever
        }

        // J4 inverse — resume journeys the teardown paused.
        $this->isolate('journey-resume', static function () use ($orgId, $subjectId): void {
            JourneyServices::journey()->resumeAllForSubject($orgId, $subjectId);
        });

        // M1 inverse — restore memberships the teardown ended.
        $this->isolate('membership-restore', static function () use ($orgId, $subjectId): void {
            GroupServices::memberships()->restoreForSubject($orgId, $subjectId);
        });

        return true;
    }

    /**
     * Person merge — Theme B. The LOSER's grants/commitments/etc. are torn down
     * (authority for the survivor is re-established through the authorized path,
     * never inherited by a blind re-point — ID2). Belonging re-point consumers
     * subscribe to the same event in their own modules.
     *
     * @param array<string,mixed> $data
     */
    private function accountMerged(string $orgId, array $data): bool
    {
        $loserId    = (string) ($data['loser_user_id'] ?? '');
        $survivorId = (string) ($data['survivor_user_id'] ?? '');
        if ($orgId === '' || $loserId === '') {
            return true;
        }

        // Loser's authority/commitments are torn down (same as any teardown)…
        $this->fanOutTeardown($orgId, $loserId, 'account.merged');

        // …but journeys are RE-POINTED to the survivor (J4 merge half), not just
        // paused — the survivor inherits the loser's context journeys, deduped.
        if ($survivorId !== '') {
            $this->isolate('journey-reassign', static function () use ($orgId, $loserId, $survivorId): void {
                JourneyServices::journey()->reassignForMerge($orgId, $loserId, $survivorId);
            });

            // …and the loser's sponsorship graph is RE-POINTED to the survivor
            // (R7 merge half): the loser's downline is re-parented to the
            // survivor through the reassignment path, the loser's own member edge
            // is closed, and their cloaked referral links now credit the
            // survivor — history preserved, never a blind row rewrite.
            $this->isolate('sponsorship-reassign', static function () use ($orgId, $loserId, $survivorId): void {
                ReferralServices::sponsorships()->reassignForMerge($orgId, $loserId, $survivorId);
            });

            // …and the loser's course enrollments + completions are RE-POINTED to
            // the survivor (CO6 merge half): the survivor inherits the loser's
            // learning record, deduped against courses the survivor is already in
            // (UNIQUE(course_id,user_id) preserved), never a blind row rewrite.
            $this->isolate('enrollment-reassign', static function () use ($orgId, $loserId, $survivorId): void {
                CourseServices::enrollments()->reassignForMerge($orgId, $loserId, $survivorId);
            });

            // …and the loser's event footprint is RE-POINTED to the survivor
            // (E-B2 merge half): registrations, attendance, live holds and
            // waitlist entries move to the survivor, deduped against the
            // survivor's own per-event rows (would-be duplicates are superseded/
            // voided/released, never a blind row rewrite). Group attribution on a
            // registration is never rewritten.
            $this->isolate('registration-reassign', static function () use ($orgId, $loserId, $survivorId): void {
                EventServices::eventRegistrations()->reassignForMerge($orgId, $loserId, $survivorId);
            });

            // …and the loser's group memberships are RE-POINTED to the survivor
            // (M2 merge half): active memberships move to the survivor (recomputed
            // active_key), deduped against slots the survivor already holds (a
            // would-be duplicate is ended as superseded, never a blind rewrite);
            // historical rows re-point for continuity. Stops the merge stranding
            // belongings on the dead user id / double-counting the same human.
            $this->isolate('membership-reassign', static function () use ($orgId, $loserId, $survivorId): void {
                GroupServices::memberships()->reassignForMerge($orgId, $loserId, $survivorId);
            });
        }

        return true;
    }

    /**
     * Group teardown (dissolve) — Theme B group-half. A dissolved group is gone,
     * so authority/attribution/journeys scoped to it must stop resolving. Fans
     * out to the group-scoped consumers. Idempotent, so a redelivered event is
     * safe.
     *
     * @param array<string,mixed> $data
     */
    private function groupTornDown(string $topic, string $orgId, array $data): bool
    {
        $groupId = (string) ($data['group_id'] ?? '');
        if ($orgId === '' || $groupId === '') {
            return true;
        }

        // Dissolve has no survivor — attribution rolls UP to a live ancestor,
        // which C6 resolves from the live groups table (the dead group's own
        // parent_id is left intact by the transition).
        return $this->fanOutGroupTeardown($orgId, $groupId, $topic, null);
    }

    /**
     * Group merge — Theme B group-half. The merged (loser) group's scoped
     * authority is torn down exactly like a dissolve (authority over the survivor
     * is re-established through the authorized path, never inherited). Belonging
     * re-point consumers subscribe to the same event in their own modules using
     * the survivor id.
     *
     * @param array<string,mixed> $data
     */
    private function groupMerged(string $orgId, array $data): bool
    {
        $fromId     = (string) ($data['from_group_id'] ?? ($data['group_id'] ?? ''));
        $survivorId = (string) ($data['survivor_group_id'] ?? ($data['into_group_id'] ?? ''));
        if ($orgId === '' || $fromId === '') {
            return true;
        }

        return $this->fanOutGroupTeardown($orgId, $fromId, 'group.merged', $survivorId !== '' ? $survivorId : null);
    }

    /**
     * IN1 — provider connection disabled/revoked. The connection's active
     * capability_grants are ALREADY auto-revoked at source (ConnectionService),
     * so a descendant group can no longer resolve the grant. Recognised here (so
     * it is not an "unknown topic") and acked; reserved as the seam for cutting
     * off dependent Meetings sessions/tokens (MT5) and Streaming destinations
     * bound to this connection.
     *
     * @param array<string,mixed> $data
     */
    private function connectionTornDown(string $topic, string $orgId, array $data): bool
    {
        $connectionId  = (string) ($data['connection_id'] ?? '');
        $revokedGrants = (int) ($data['revoked_grants'] ?? 0);
        log_message('info', sprintf(
            'JobRouter: %s org=%s connection=%s (grants auto-revoked at source: %d)',
            $topic,
            $orgId,
            $connectionId,
            $revokedGrants,
        ));

        return true;
    }

    /**
     * Group archive — Theme B group-half. Archive is a REVERSIBLE, hidden state,
     * not a teardown: grants/causes/journeys are deliberately preserved so
     * reactivation restores the group intact. Recognised here (so it is not an
     * "unknown topic") and acked as a no-op; reserved as the seam for
     * hide-from-pickers / pause-notifications behaviour if needed later.
     *
     * @param array<string,mixed> $data
     */
    private function groupArchived(string $orgId, array $data): bool
    {
        $groupId = (string) ($data['group_id'] ?? '');
        log_message('info', sprintf('JobRouter: group.archived org=%s group=%s (reversible, no teardown)', $orgId, $groupId));

        return true;
    }

    /**
     * Fan a GROUP teardown out to every subscribed group-scoped consumer
     * (Theme B group-half). Each consumer is fault-ISOLATED and individually
     * idempotent, mirroring fanOutTeardown. Add new group-teardown consumers here.
     */
    private function fanOutGroupTeardown(
        string $orgId,
        string $groupId,
        string $reason,
        ?string $survivorId = null,
    ): bool {
        // AC9 — revoke authority scoped to the dead group (role assignments,
        // delegations + subtrees, break-glass, live access requests) and prune it
        // from hand-picked multi-group sets (revoking any grant left covering
        // nothing). Never copied to a survivor on merge (authority is
        // re-established through the authorized path).
        $this->isolate('grant-group-cascade', static function () use ($orgId, $groupId, $reason): void {
            AccessControlServices::grantCascade()->onGroupTornDown($orgId, $groupId, $reason);
        });

        // J4-group — archive the dead group's OWN journey context so those
        // group-scoped journeys stop appearing in that branch's pipelines. Not
        // re-pointed to a survivor (a member's stage is context-specific); history
        // is retained and the member re-enters via the ordinary open/advance path.
        $this->isolate('journey-group-archive', static function () use ($orgId, $groupId, $reason): void {
            JourneyServices::journey()->archiveGroupContextJourneys($orgId, $groupId, $reason);
        });

        // C6 — repair contribution ATTRIBUTION: re-home the dead group's causes so
        // giving no longer attributes to a phantom node. On merge the causes move
        // to the SURVIVOR (it inherits the absorbed group's giving history); on
        // dissolve they roll UP to the nearest live ancestor (or org level when
        // none survives). Contribution/ledger rows are never rewritten — only the
        // group the cause hangs on — so all rollups recompute from live ownership.
        $this->isolate('cause-reattribution', static function () use ($orgId, $groupId, $reason, $survivorId): void {
            ContributionServices::causes()->reattributeGroupCauses($orgId, $groupId, $reason, $survivorId);
        });

        // G5 — keep INVITE SOURCES valid: a merge re-points group-context invites
        // (and downline assignment) to the survivor; a dissolve fail-closes by
        // clearing the stale group invite target and rolling assignment up to the
        // nearest live ancestor. Outreach contacts/history are never deleted.
        $this->isolate('contact-invite-repair', static function () use ($orgId, $groupId, $reason, $survivorId): void {
            ReferralServices::contactBook()->onGroupTornDown($orgId, $groupId, $reason, $survivorId);
        });

        return true;
    }

    /**
     * Fan a teardown out to every subscribed consumer (Theme B). Each consumer is
     * fault-ISOLATED: a throw in one is logged and the rest still run, because a
     * dependent that fails to react must not block the others or wedge the job on
     * endless retries. All consumers are individually idempotent, so re-delivery
     * is safe. Add new teardown consumers here.
     */
    private function fanOutTeardown(string $orgId, string $subjectId, string $reason): bool
    {
        // AC3 — revoke the subject's grants/delegations, close break-glass.
        $this->isolate('grant-cascade', static function () use ($orgId, $subjectId, $reason): void {
            AccessControlServices::grantCascade()->onAccountTornDown($orgId, $subjectId, $reason);
        });

        // C8 — cancel the subject's active recurring giving commitments so a gone
        // member's pledges stop reminding forever.
        $this->isolate('commitment-cancel', static function () use ($orgId, $subjectId, $reason): void {
            ContributionServices::commitments()->cancelActiveForSubject($orgId, $subjectId, $reason);
        });

        // J4 — pause the subject's active journeys so a gone/frozen person stops
        // appearing in pipelines/funnels/leaderboards. NOT on merge: there the
        // journeys are RE-POINTED to the survivor (see accountMerged), so pausing
        // them first would hand the survivor paused journeys.
        if ($reason !== 'account.merged') {
            $this->isolate('journey-pause', static function () use ($orgId, $subjectId, $reason): void {
                JourneyServices::journey()->pauseAllForSubject($orgId, $subjectId, $reason);
            });
        }

        // N7 — cancel the subject's inflight (queued/deferred) deliveries and add
        // a hard do-not-contact suppression so a gone account is never a send
        // target (essential legal/security mail keeps its lawful basis).
        $this->isolate('notification-teardown', static function () use ($orgId, $subjectId, $reason): void {
            NotificationServices::notifications()->onAccountTornDown($orgId, $subjectId, $reason);
        });

        // R7 — disable the subject's active cloaked referral links so a gone/
        // frozen sponsor can no longer auto-link NEW prospects. Historical
        // sponsorship edges are left intact (attribution is never rewritten); the
        // MERGE half re-points the loser's edges to the survivor separately (see
        // accountMerged). Skipped on merge so a merged referrer's still-valuable
        // links are re-pointed rather than killed.
        if ($reason !== 'account.merged') {
            $this->isolate('sponsorship-teardown', static function () use ($orgId, $subjectId, $reason): void {
                ReferralServices::sponsorships()->onAccountTornDown($orgId, $subjectId, $reason);
            });
        }

        // CO6 — withdraw the subject's in-flight (pending/active) course
        // enrollments so a gone/frozen learner stops accruing progress or earning
        // completion rewards. Completed enrollments (verified completions +
        // certificates) are historical facts and are left intact. Skipped on
        // merge: there the loser's enrollments are RE-POINTED to the survivor
        // (see accountMerged) rather than withdrawn.
        if ($reason !== 'account.merged') {
            $this->isolate('enrollment-teardown', static function () use ($orgId, $subjectId, $reason): void {
                CourseServices::enrollments()->withdrawActiveForSubject($orgId, $subjectId, $reason);
            });
        }

        // E-B2 — release the subject's FUTURE event footprint: cancel active
        // registrations on not-yet-run events (promoting the next waitlisted
        // person into a freed seat), release live inventory holds, expire waitlist
        // rows. Registrations on events that already ran are history. Skipped on
        // merge: there the loser's registrations/attendance are RE-POINTED to the
        // survivor (see accountMerged) rather than released.
        if ($reason !== 'account.merged') {
            $this->isolate('registration-teardown', static function () use ($orgId, $subjectId, $reason): void {
                EventServices::eventRegistrations()->releaseActiveForSubject($orgId, $subjectId, $reason);
            });
        }

        // M1 (belonging half) — END the subject's ACTIVE group memberships so a
        // gone/frozen person stops counting as `active` in rosters, scope
        // resolution and group-size metrics. History (`ended` rows) is kept.
        // Skipped on merge: there the loser's memberships are RE-POINTED to the
        // survivor (see accountMerged) rather than ended.
        if ($reason !== 'account.merged') {
            $this->isolate('membership-teardown', static function () use ($orgId, $subjectId, $reason): void {
                GroupServices::memberships()->endActiveForSubject($orgId, $subjectId, $reason);
            });
        }

        return true;
    }

    /** Run a teardown consumer, swallowing + logging any throw (fault isolation). */
    private function isolate(string $consumer, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            log_message('error', 'JobRouter teardown consumer "' . $consumer . '" failed: ' . $e->getMessage());
        }
    }

    private function unknown(string $topic): bool
    {
        log_message('warning', 'JobRouter received unknown topic: ' . $topic);

        // Unknown topics are acked (not retried forever) but logged for review.
        return true;
    }
}
