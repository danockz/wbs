<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Admin\Services\EffectiveConfigResolver;
use WBS\Audit\Services\AuditLogger;
use WBS\Groups\Services\GroupMembershipService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Transfers a prospect's group membership to the mentor who actually follows up.
 *
 * The onboarding rule: a prospect belongs to their mentor's group, and if they
 * go quiet for a configurable number of WEEKS and a DIFFERENT mentor/sponsor
 * follows them up (typically to attend that mentor's event), their belonging —
 * and their sponsor — move to that mentor's group. The lead that is being worked
 * stays with the person working it; the idle pointer does not block them.
 *
 * The threshold is HIERARCHICAL GROUP CONFIG, not a global/env setting, resolved
 * through {@see EffectiveConfigResolver} on capability
 * `referrals.prospect_transfer`:
 *
 *     {"enabled": true, "inactive_weeks": 8, "requires_review": false}
 *     8                                            // a bare int is also accepted
 *
 * Absent / disabled / 0 weeks ⇒ the feature is OFF for that subtree and nothing
 * transfers (default-OFF, per the platform's feature-gating rule). A group may
 * set its own value or inherit its parent's, and `inactive_weeks` is clamped to a
 * sane 1..104 so a typo cannot transfer everybody or nobody.
 *
 * `requires_review` (default FALSE) routes a due transfer through the
 * maker-checker queue ({@see ProspectTransferReviewService}) instead of applying
 * it immediately — for a body that wants a second leader to sign off before a
 * person is moved between groups. It only means anything while transfers are on.
 *
 * A transfer is APPEND-ONLY provenance: it writes `prospect_group_transfers`,
 * ends the old `group_members` row (history kept, one-active slot freed), opens
 * the new one, re-parents the sponsorship, and re-points
 * `prospects.owner_user_id` / `assigned_group_id`. Issued attributions, points
 * and certificates are untouched — the person's past stays where it happened.
 */
final class ProspectTransferService
{
    /** group_configurations capability holding the inactivity policy. */
    public const CAPABILITY = 'referrals.prospect_transfer';

    /** Guard rails for a configured week count. */
    public const MIN_WEEKS = 1;
    public const MAX_WEEKS = 104;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ProspectGroupResolver $resolver,
        private readonly ?EffectiveConfigResolver $config = null,
        private readonly ?GroupMembershipService $memberships = null,
        private readonly ?SponsorshipService $sponsorships = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * The effective inactivity threshold in WEEKS for a group subtree.
     * 0 ⇒ transfers are OFF there (the default).
     */
    public function thresholdWeeks(string $organizationId, ?string $groupId): int
    {
        return $this->policyFor($organizationId, $groupId)['inactive_weeks'];
    }

    /**
     * The effective policy for a group subtree. Off/absent ⇒ everything zeroed
     * (and review moot, since there is nothing to review).
     *
     * @return array{enabled:bool,inactive_weeks:int,requires_review:bool}
     */
    public function policyFor(string $organizationId, ?string $groupId): array
    {
        if ($this->config === null || ! is_string($groupId) || $groupId === '') {
            return self::normalizePolicy(null);
        }

        $res = $this->config->resolve($groupId, self::CAPABILITY);
        if (! $res->ok || ! is_array($res->data)) {
            return self::normalizePolicy(null);
        }

        return self::normalizePolicy($res->data['value'] ?? null);
    }

    /** Must a due transfer wait for a checker in this subtree? */
    public function requiresReview(string $organizationId, ?string $groupId): bool
    {
        return $this->policyFor($organizationId, $groupId)['requires_review'];
    }

    /**
     * Read a policy value in any accepted shape (int, array, JSON string).
     *
     * @return array{enabled:bool,inactive_weeks:int,requires_review:bool}
     */
    public static function normalizePolicy(mixed $value): array
    {
        // A JSON-encoded object (how `value_json` reaches us when a caller passes
        // the raw column) is decoded first; a bare numeric string stays a string.
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        $review = false;
        if (is_int($value) || is_float($value)) {
            $weeks   = (int) $value;
            $enabled = $weeks > 0;
        } elseif (is_array($value)) {
            $weeks   = isset($value['inactive_weeks']) ? (int) $value['inactive_weeks'] : 0;
            $enabled = array_key_exists('enabled', $value) ? (bool) $value['enabled'] : $weeks > 0;
            $review  = ! empty($value['requires_review']);
        } elseif (is_string($value) && trim($value) !== '') {
            // A bare numeric string ("8") is a week count; anything else is OFF.
            $weeks   = is_numeric($value) ? (int) $value : 0;
            $enabled = $weeks > 0;
        } else {
            return ['enabled' => false, 'inactive_weeks' => 0, 'requires_review' => false];
        }

        if (! $enabled || $weeks <= 0) {
            return ['enabled' => false, 'inactive_weeks' => 0, 'requires_review' => false];
        }

        return [
            'enabled'         => true,
            'inactive_weeks'  => max(self::MIN_WEEKS, min(self::MAX_WEEKS, $weeks)),
            // Review only means something while transfers themselves are on.
            'requires_review' => $review,
        ];
    }

    /**
     * Decide whether $newMentorId takes over $contact right now. PURE-ish: it
     * reads state but writes nothing, so a caller can ask before touching
     * anything (and a test can assert the whole decision table).
     *
     * @param array<string,mixed> $contact the prospects row
     *
     * @return array{due:bool,reason:string,threshold_weeks:int,days_inactive:?int,
     *               from_owner_user_id:?string,from_group_id:?string,to_group_id:?string,
     *               linked_user_id:?string}
     */
    public function evaluate(string $organizationId, array $contact, string $newMentorId): array
    {
        $out = [
            'due'                => false,
            'reason'             => '',
            'threshold_weeks'    => 0,
            'days_inactive'      => null,
            'from_owner_user_id' => isset($contact['owner_user_id']) ? (string) $contact['owner_user_id'] : null,
            'from_group_id'      => isset($contact['assigned_group_id']) ? (string) $contact['assigned_group_id'] : null,
            'to_group_id'        => null,
            'linked_user_id'     => isset($contact['linked_user_id']) ? (string) $contact['linked_user_id'] : null,
        ];

        // NOTE: decisions are built with array_merge, never `$out + [...]` — the
        // union operator keeps the LEFT operand's value for an existing key, so a
        // pre-seeded 'reason' => '' would swallow every verdict below.
        $verdict = static fn (array $state, string $reason, bool $due = false): array => array_merge($state, [
            'reason' => $reason,
            'due'    => $due,
        ]);

        $newMentorId = trim($newMentorId);
        if ($newMentorId === '') {
            return $verdict($out, 'no_new_mentor');
        }
        if ($out['from_owner_user_id'] === $newMentorId) {
            return $verdict($out, 'same_mentor');
        }

        // Where the new mentor would take them. No home group ⇒ nowhere to go.
        $target = $this->resolver->homeGroupOf($organizationId, $newMentorId);
        if ($target === null) {
            return $verdict($out, 'new_mentor_has_no_group');
        }
        $out['to_group_id'] = $target;

        // Policy is read off the group that currently holds the contact, so a
        // body that wants a longer grace period sets it for its own subtree.
        $weeks = $this->thresholdWeeks($organizationId, $out['from_group_id'] ?? $target);
        $out['threshold_weeks'] = $weeks;
        if ($weeks <= 0) {
            return $verdict($out, 'transfer_disabled');
        }

        $days = ProspectGroupResolver::daysSinceContact($contact, $this->now());
        $out['days_inactive'] = $days;
        if ($days === null) {
            return $verdict($out, 'no_activity_baseline');
        }
        if ($days < $weeks * 7) {
            return $verdict($out, 'still_active');
        }
        if ($out['from_group_id'] === $target) {
            // Same body, different mentor: a sponsor re-parent, not a transfer.
            return $verdict($out, 'same_group');
        }

        return $verdict($out, 'inactive_threshold_met', true);
    }

    /**
     * Perform a transfer that {@see evaluate()} said was due. Idempotent-ish: a
     * second call for the same mentor finds `same_mentor` and no-ops.
     *
     * @param array<string,mixed> $evaluation the evaluate() result (due=true)
     * @param array<string,mixed> $trigger    {type?:string,id?:string,note?:string,actor_id?:string}
     */
    public function apply(string $organizationId, string $contactId, string $newMentorId, array $evaluation, array $trigger = []): Result
    {
        if (empty($evaluation['due'])) {
            return Result::fail('NOT_DUE', 'contact.transfer_not_due', 409, ['reason' => $evaluation['reason'] ?? '']);
        }
        $toGroupId = (string) ($evaluation['to_group_id'] ?? '');
        if ($toGroupId === '') {
            return Result::fail('NO_TARGET_GROUP', 'contact.transfer_no_group', 422);
        }

        $contact = $this->db->table('prospects')
            ->where('organization_id', $organizationId)->where('id', $contactId)->get()->getRowArray();
        if ($contact === null) {
            return Result::notFound('contact.not_found', 'CONTACT_NOT_FOUND');
        }

        $fromGroupId = isset($contact['assigned_group_id']) && $contact['assigned_group_id'] !== ''
            ? (string) $contact['assigned_group_id'] : null;
        $fromOwnerId = isset($contact['owner_user_id']) && $contact['owner_user_id'] !== ''
            ? (string) $contact['owner_user_id'] : null;
        $linkedUserId = isset($contact['linked_user_id']) && $contact['linked_user_id'] !== ''
            ? (string) $contact['linked_user_id'] : null;
        $now    = $this->clock->nowUtcString();
        $actor  = (string) ($trigger['actor_id'] ?? $newMentorId);
        $reason = (string) ($trigger['type'] ?? 'event_invite');

        $this->db->transStart();

        $previousMembershipId = null;
        $membershipId         = null;
        $sponsorshipId        = null;

        // 1) Move the platform belonging, if the prospect has an account yet.
        //    The old row is ENDED, not deleted: the history stays where it happened.
        if ($linkedUserId !== null) {
            if ($this->memberships !== null) {
                $old = $this->activeMembership($organizationId, $linkedUserId, $fromGroupId);
                if ($old !== null) {
                    $previousMembershipId = (string) $old['id'];
                    $this->memberships->leave($organizationId, $previousMembershipId, $actor, 'inactivity_transfer');
                }
                // A mentor following up is an assisted join (a valid invite source),
                // so the target group admits it rather than queueing an approval.
                $add = $this->memberships->add($organizationId, $toGroupId, [
                    'user_id'           => $linkedUserId,
                    'membership_type'   => 'member',
                    'role'              => 'member',
                    'source'            => 'system',
                    'requires_approval' => false,
                    'added_by'          => $newMentorId,
                    'actor_id'          => $actor,
                ]);
                if ($add->ok && is_array($add->data)) {
                    $membershipId = isset($add->data['membership_id']) ? (string) $add->data['membership_id'] : null;
                }
            }

            // 2) Re-parent the sponsor so the upline matches the new belonging.
            if ($this->sponsorships !== null) {
                $sp = $this->sponsorships->assign($organizationId, $linkedUserId, $newMentorId, 'inactivity_transfer');
                if ($sp->ok && is_array($sp->data) && isset($sp->data['id'])) {
                    $sponsorshipId = (string) $sp->data['id'];
                }
            }
        }

        // 3) Re-point the contact at the mentor who is actually working it.
        $this->db->table('prospects')->where('id', $contactId)->update([
            'owner_user_id'     => $newMentorId,
            'assigned_group_id' => $toGroupId,
            'last_contacted_at' => $now,
            'follow_up_count'   => (int) ($contact['follow_up_count'] ?? 0) + 1,
            'updated_at'        => $now,
        ]);

        // 4) Append the provenance row.
        $transferId = Uuid::v7();
        $this->db->table('prospect_group_transfers')->insert([
            'id'                     => $transferId,
            'organization_id'        => $organizationId,
            'prospect_id'            => $contactId,
            'linked_user_id'         => $linkedUserId,
            'from_group_id'          => $fromGroupId,
            'to_group_id'            => $toGroupId,
            'from_owner_user_id'     => $fromOwnerId,
            'to_owner_user_id'       => $newMentorId,
            'reason'                 => 'inactivity_transfer',
            'trigger_type'           => $reason,
            'trigger_id'             => isset($trigger['id']) ? (string) $trigger['id'] : null,
            'threshold_weeks'        => (int) ($evaluation['threshold_weeks'] ?? 0),
            'days_inactive'          => isset($evaluation['days_inactive']) ? (int) $evaluation['days_inactive'] : null,
            'previous_membership_id' => $previousMembershipId,
            'membership_id'          => $membershipId,
            'sponsorship_id'         => $sponsorshipId,
            // Set when a maker-checker request authorized this transfer; NULL for
            // an automatic one (column added by migration 000087).
            'request_id'             => isset($trigger['request_id']) ? (string) $trigger['request_id'] : null,
            'note'                   => isset($trigger['note']) ? mb_substr((string) $trigger['note'], 0, 255) : null,
            'created_by'             => $actor,
            'created_at'             => $now,
        ]);

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return Result::fail('TRANSFER_FAILED', 'contact.transfer_failed', 500);
        }

        $this->audit?->record($organizationId, [
            'action'      => 'prospect.group.transferred',
            'actor_id'    => $actor,
            'actor_type'  => 'user',
            'object_type' => 'prospect',
            'object_id'   => $contactId,
            'outcome'     => 'success',
            'metadata'    => [
                'from_group_id'      => $fromGroupId,
                'to_group_id'        => $toGroupId,
                'from_owner_user_id' => $fromOwnerId,
                'to_owner_user_id'   => $newMentorId,
                'threshold_weeks'    => (int) ($evaluation['threshold_weeks'] ?? 0),
                'days_inactive'      => $evaluation['days_inactive'] ?? null,
                'trigger_type'       => $reason,
                'transfer_id'        => $transferId,
            ],
        ]);

        return Result::ok([
            'transfer_id'    => $transferId,
            'request_id'     => isset($trigger['request_id']) ? (string) $trigger['request_id'] : null,
            'prospect_id'    => $contactId,
            'from_group_id'  => $fromGroupId,
            'to_group_id'    => $toGroupId,
            'to_owner'       => $newMentorId,
            'membership_id'  => $membershipId,
            'sponsorship_id' => $sponsorshipId,
            'reason'         => 'inactivity_transfer',
        ]);
    }

    /**
     * Transfer history for one contact, newest first (the "why are they here?"
     * trail shown on the contact record).
     *
     * @return list<array<string,mixed>>
     */
    public function historyFor(string $organizationId, string $contactId, int $limit = 50): array
    {
        if (! $this->db->tableExists('prospect_group_transfers')) {
            return [];
        }

        return $this->db->table('prospect_group_transfers')
            ->where('organization_id', $organizationId)
            ->where('prospect_id', $contactId)
            ->orderBy('created_at', 'DESC')
            ->limit(max(1, min($limit, 200)))
            ->get()->getResultArray();
    }

    // ---------------------------------------------------------------- helpers

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->clock->nowUtcString(), new \DateTimeZone('UTC'));
    }

    /**
     * The user's ACTIVE membership in $groupId, if any. $groupId null means "any
     * active membership" — a contact placed before this model existed may have no
     * recorded group but still hold one.
     *
     * @return array<string,mixed>|null
     */
    private function activeMembership(string $organizationId, string $userId, ?string $groupId): ?array
    {
        $q = $this->db->table('group_members')
            ->select('id, group_id')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('status', 'active');
        if ($groupId !== null && $groupId !== '') {
            $q->where('group_id', $groupId);
        }

        $row = $q->orderBy('joined_at', 'DESC')->get()->getRowArray();

        return $row === null ? null : $row;
    }
}
