<?php

declare(strict_types=1);

namespace WBS\Notifications\Database\Seeds;

use CodeIgniter\Database\Seeder;
use WBS\Shared\Support\Uuid;

/**
 * Seeds the baseline `notification_templates` the platform actually sends, so a
 * fresh deployment renders real subjects/bodies instead of empty strings. Covers
 * every category emitted by NotificationService::send() across the modules:
 *
 *   - account_verify_reminder  (M6 verify-expiry nudge)
 *   - event_reminder           (event reminders sweep)
 *   - event_cancelled          (E-B1 event cancel fan-out)
 *   - event_updated            (event material-change fan-out)
 *   - event_promoted           (G8 waitlist-promotion on capacity raise)
 *   - security_alert           (identity/security events)
 *   - partnership_commitment_due (Contributions recurring-commitment reminder)
 *   - outreach_follow_up_due   (Referrals contact follow-up reminder)
 *   - access_request           (ACL approval requested)
 *   - access_request_outcome   (ACL approve/deny outcome)
 *   - stream_relay_failure     (streaming/integration relay health)
 *   - gamification_review_reminder    (G2 held fraud-review reminder)
 *   - gamification_review_escalation  (G2 held fraud-review escalation)
 *   - journey_proposal_reminder       (J6 pending stage-proposal reminder)
 *   - journey_proposal_escalation     (J6 pending stage-proposal escalation)
 *
 * Seeded `active` in English — the translation-merge layer falls back to English
 * for any locale without an override, so `en` is the correct baseline. Bodies use
 * the platform's `:name` named-placeholder convention (also `{0}` supported).
 * Idempotent: upsert on (org, key_name, channel, locale, version=1). Runs for the
 * default `wbs` org, else for every organization.
 */
class NotificationTemplateSeeder extends Seeder
{
    /** key_name => [channel, category, subject|null, body] */
    private const TEMPLATES = [
        'account_verify_reminder' => ['email', 'security',
            'Please verify your account',
            "Hi :name,\n\nYour account is almost ready — please verify your email to finish setting up. This link is valid for a limited time.\n\n:verify_url\n\nIf you didn't create this account you can ignore this message."],
        'event_reminder' => ['email', 'event',
            'Reminder: :event_title is coming up',
            "Hi :name,\n\nThis is a reminder that :event_title starts on :starts_at at :location.\n\nSee you there!"],
        'event_cancelled' => ['email', 'event',
            ':event_title has been cancelled',
            "Hi :name,\n\nWe're sorry to let you know that :event_title (scheduled for :starts_at) has been cancelled. Any hold or ticket has been released. Reason: :reason."],
        'event_updated' => ['email', 'event',
            'Updated: :title has changed',
            "Hi :name,\n\nDetails for :title (starts :starts_at :timezone) have changed: :changed. Please review the latest information before the event."],
        'event_promoted' => ['email', 'event',
            "Good news — you're off the waitlist for :title",
            "Hi :name,\n\nA seat has opened for :title (starts :starts_at :timezone) and you've been moved off the waitlist — you're now registered. We look forward to seeing you there!"],
        'security_alert' => ['email', 'security',
            'Security alert on your account',
            "Hi :name,\n\nWe detected a security-relevant change on your account: :detail at :occurred_at. If this wasn't you, please secure your account immediately."],
        'access_request' => ['in_app', 'access',
            'Access request awaiting your review',
            ":requester_name requested :capability for :scope. Review and approve or decline in the access console."],
        'access_request_outcome' => ['in_app', 'access',
            'Your access request was :outcome',
            "Your request for :capability was :outcome by :decider_name. :note"],
        'stream_relay_failure' => ['email', 'integration',
            'Stream relay problem detected',
            "The relay for :stream_title stopped reporting healthy heartbeats at :occurred_at. The stream may be interrupted — please check the encoder/connection."],
        // Contributions — recurring giving-commitment due reminder.
        'partnership_commitment_due' => ['email', 'contribution',
            'Your giving commitment is due',
            "Hi :name,\n\nThis is a friendly reminder that your commitment of :amount_minor :currency toward :cause_id is now due. Thank you for your partnership."],
        // Referrals — outreach contact follow-up due reminder (to the owner).
        'outreach_follow_up_due' => ['email', 'outreach',
            'Follow-up due: :display_name',
            "Hi :name,\n\nA follow-up with :display_name (:temperature) is due on :next_follow_up_at. Reach out and record the outcome to keep the relationship warm."],
        // G2 — held/fraud-review aging sweep (in-app nudges to the approver queue).
        'gamification_review_reminder' => ['in_app', 'gamification',
            'A held points review is waiting',
            "A points award is on hold pending your review (review :review_id). Open the review queue to approve or reject it so the points aren't stranded. Reason: :reason."],
        'gamification_review_escalation' => ['in_app', 'gamification',
            'Escalated: a held points review is overdue',
            "A points review has been waiting past its SLA and is now escalated (review :review_id). Please clear it from the review queue as soon as possible. Reason: :reason."],
        // J6 — pending journey stage-proposal aging sweep (in-app nudges to leaders).
        'journey_proposal_reminder' => ['in_app', 'journey',
            'A stage-transition proposal awaits your review',
            "A rule proposed moving a member to :to_stage (proposal :proposal_id). Open the proposals queue to approve or reject it."],
        'journey_proposal_escalation' => ['in_app', 'journey',
            'Escalated: a stage proposal is overdue',
            "A stage-transition proposal to :to_stage has been pending past its SLA and is now escalated (proposal :proposal_id). Please review it soon."],
        'announcement_published' => ['email', 'announcement',
            'Announcement: :title',
            "Hi :name,\n\n:title\n\n:body"],
    ];

    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $wbs   = $this->db->table('organizations')->where('slug', 'wbs')->get()->getRowArray();
        $orgIds = $wbs !== null
            ? [(string) $wbs['id']]
            : array_map(
                static fn ($o) => (string) $o['id'],
                $this->db->table('organizations')->select('id')->get()->getResultArray(),
            );

        $count = 0;
        foreach ($orgIds as $orgId) {
            foreach (self::TEMPLATES as $key => [$channel, $category, $subject, $body]) {
                $existing = $this->db->table('notification_templates')
                    ->where('organization_id', $orgId)
                    ->where('key_name', $key)
                    ->where('channel', $channel)
                    ->where('locale', 'en')
                    ->where('version', 1)
                    ->get()->getRowArray();

                $row = [
                    'organization_id' => $orgId,
                    'group_id'        => null,
                    'key_name'        => $key,
                    'channel'         => $channel,
                    'locale'          => 'en',
                    'category'        => $category,
                    'version'         => 1,
                    'subject'         => $subject,
                    'body'            => $body,
                    'status'          => 'active',
                    'updated_at'      => $now,
                ];

                if ($existing !== null) {
                    $this->db->table('notification_templates')->where('id', $existing['id'])->update($row);
                } else {
                    $this->db->table('notification_templates')->insert($row + [
                        'id'         => Uuid::v7(),
                        'created_at' => $now,
                    ]);
                    $count++;
                }
            }
        }

        if (is_cli()) {
            fwrite(STDOUT, "NotificationTemplateSeeder: {$count} template(s) inserted (" . count(self::TEMPLATES) . " keys).\n");
        }
    }
}
