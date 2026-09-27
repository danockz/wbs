<?php

declare(strict_types=1);

namespace WBS\Shared\Navigation;

/**
 * Central menu catalog covering the platform's page-like module surfaces.
 *
 * Every item points at a real GET route in Config/Routes.php, and any declared
 * permission code (and its 'any' scope modifier) MUST match that route's
 * authorize: filter — the anti-drift test (menu_antidrift_test.php) enforces this.
 * Items whose feature PAGE is not built yet are still listed here but recorded in
 * that test's KNOWN_GAPS so CI stays green while reporting them.
 *
 * Scope of visibility is DISPLAY only: route authorize: filters remain the
 * security boundary. Items with no permission are visible to any authenticated
 * user (the route itself is auth-only). In production each module can ship its own
 * provider; this is the consolidated set.
 */
final class CoreMenuProvider
{
    /** @return list<MenuItem> */
    public static function items(): array
    {
        $C = MenuCategory::class;

        return [
            // ---- Overview (personal; auth-only routes) ----
            new MenuItem('overview.dashboard', $C::OVERVIEW, 'Dashboard', 'me/dashboard', icon: 'gauge', order: 10),
            new MenuItem('overview.contacts', $C::OVERVIEW, 'My contacts & follow-ups', 'me/contacts', icon: 'address-book', order: 20),
            // Personal workspace (auth-only): the standalone dated decisions of
            // the integration lifecycle. Unmasked by design — every member reaches
            // their own checklist, and the IntegrationService gates what each can
            // actually do (events.mine precedent). The mentor's confirmation
            // queue lives at /me/integration-decisions (MenuCoverage::EXCLUSIONS).
            new MenuItem('overview.integration', $C::OVERVIEW, 'My integration', 'my/integration', icon: 'hand-heart', order: 22),
            new MenuItem('overview.followups', $C::OVERVIEW, 'My follow-ups due', 'gamification/follow-ups/due', icon: 'inbox', order: 25),
            // Personal approver queue (GET is auth-only; empty for non-approvers).
            new MenuItem('overview.approvals', $C::OVERVIEW, 'Approvals awaiting me', 'access-requests/pending', icon: 'inbox', order: 28),
            new MenuItem('overview.sessions', $C::OVERVIEW, 'My sessions', 'me/sessions', icon: 'shield', order: 30),
            new MenuItem('overview.profile', $C::OVERVIEW, 'Edit my profile', 'me/profile', icon: 'user', order: 35),
            new MenuItem('overview.birthdays', $C::OVERVIEW, 'Birthdays', 'me/birthdays', icon: 'calendar', order: 36),

            // ---- People ----
            new MenuItem('people.members', $C::PEOPLE, 'Members', 'members', permissions: ['identity.manage'], scopeCheck: 'any', icon: 'users', order: 10),
            new MenuItem('people.bulk', $C::PEOPLE, 'Bulk sign-up', 'me/contacts', icon: 'user-plus', order: 20),
            new MenuItem('people.pipeline', $C::PEOPLE, 'Disciple-making pipeline', 'journey/pipeline', icon: 'route', order: 30),
            new MenuItem('people.funnel', $C::PEOPLE, 'Discipleship funnel', 'journey/funnel', icon: 'filter', order: 35),
            new MenuItem('people.stages', $C::PEOPLE, 'Journey stages', 'journey/stages', icon: 'git-branch', order: 40),
            new MenuItem('people.proposals', $C::PEOPLE, 'Journey proposals', 'journey/proposals', icon: 'list', order: 50),
            new MenuItem('people.disciplers', $C::PEOPLE, 'Discipler leaderboard', 'journey/leaderboard/disciplers', icon: 'award', order: 60),
            new MenuItem('people.conflicts', $C::PEOPLE, 'Membership conflicts', 'memberships/conflicts', icon: 'shuffle', order: 70),
            new MenuItem('people.merges', $C::PEOPLE, 'Pending account merges', 'identity/merges/pending', permissions: ['identity.manage'], icon: 'users', order: 80),
            new MenuItem('people.policies', $C::PEOPLE, 'Identity policies', 'identity/policies', permissions: ['identity.manage'], icon: 'key', order: 90),
            new MenuItem('people.sponsors', $C::PEOPLE, 'Sponsor reassignments', 'referrals/sponsor-reassignments/pending', permissions: ['sponsor.reassign.approve'], icon: 'shuffle', order: 100),
            // Inactivity-transfer queue: the same maker–checker duty (a second
            // leader confirming a re-parenting), so it reuses the frozen
            // sponsor.reassign.approve capability. Empty — and effectively
            // invisible in practice — unless a subtree turns on
            // referrals.prospect_transfer.requires_review.
            new MenuItem('people.transfers', $C::PEOPLE, 'Prospect transfers', 'referrals/prospect-transfers/pending', permissions: ['sponsor.reassign.approve'], icon: 'move', order: 105),

            // ---- Groups ----
            new MenuItem('groups.hierarchy', $C::GROUPS, 'Group hierarchy', 'groups', icon: 'sitemap', order: 5),
            new MenuItem('groups.directory', $C::GROUPS, 'Groups by location', 'g', icon: 'map-pin', order: 10),
            new MenuItem('groups.kinds', $C::GROUPS, 'Group kinds', 'group-kinds', icon: 'layers', order: 20),
            new MenuItem('groups.create', $C::GROUPS, 'Create group', 'groups/create', permissions: ['group.create'], scopeCheck: 'any', icon: 'folder-plus', order: 30),
            new MenuItem('groups.move', $C::GROUPS, 'Move / restructure', 'groups/move', permissions: ['group.move'], scopeCheck: 'any', icon: 'move', order: 40),
            new MenuItem('groups.venues', $C::GROUPS, 'Venues', 'venues', icon: 'map-pin', order: 50),
            new MenuItem('groups.venue_stats', $C::GROUPS, 'Venue stats', 'venues/stats', icon: 'chart-bar', order: 60),

            // ---- Events ----
            new MenuItem('events.browse', $C::EVENTS, 'Events', 'events', icon: 'calendar', order: 10),
            new MenuItem('events.calendar', $C::EVENTS, 'Events calendar', 'events/calendar', icon: 'calendar-days', order: 12),
            new MenuItem('events.calendar_settings', $C::EVENTS, 'Calendar settings', 'events/calendar/settings', permissions: ['event.create'], scopeCheck: 'any', icon: 'sliders', order: 13),
            new MenuItem('events.mine', $C::EVENTS, 'My events', 'events/mine', icon: 'calendar-check', order: 14, badge: 'events.mine_upcoming'),
            new MenuItem('events.analytics', $C::EVENTS, 'Events analytics', 'events/analytics', permissions: ['report.view'], scopeCheck: 'any', icon: 'chart-bar', order: 16),
            new MenuItem('events.create', $C::EVENTS, 'Create event', 'events/create', permissions: ['event.create'], scopeCheck: 'any', icon: 'calendar-plus', order: 20),
            new MenuItem('events.checkin', $C::EVENTS, 'Check-in', 'events/checkin', permissions: ['attendance.check_in'], scopeCheck: 'any', icon: 'qr-code', order: 30),
            new MenuItem('events.expenses', $C::EVENTS, 'Expense approvals', 'events/expenses', permissions: ['event.expense.approve'], scopeCheck: 'any', icon: 'receipt', order: 40),
            // The event's committee: the chairperson and members running the event as a
            // project, plus the oversight queue their decisions land in. This is a
            // PERSONAL workspace (my committees / awaiting my decision / my open tasks)
            // bounded server-side to the actor's own seats and scope, so — like
            // 'events.mine' — it carries no permission mask: a finance-lane member holds
            // event.expense.submit, not event.logistics.manage, and must still see their
            // own committee. Every act inside is authorized by the PDP in the services.
            // The group capability `event_committee` (default OFF) decides whether there
            // is anything to do at all.
            new MenuItem('events.committees', $C::EVENTS, 'Event committees', 'event-committees', icon: 'list', order: 42),
            new MenuItem('events.refunds', $C::EVENTS, 'Ticket refunds', 'event-refunds/pending', permissions: ['contribution.refund.approve'], icon: 'rotate-ccw', order: 45),
            new MenuItem('events.meetings', $C::EVENTS, 'Meeting schedule', 'meetings', permissions: ['meeting.manage'], scopeCheck: 'any', icon: 'video', order: 50),
            new MenuItem('events.certificate_templates', $C::EVENTS, 'Certificate templates', 'certificates/templates', permissions: ['event.certificate.manage'], icon: 'award', order: 60),

            // ---- Learning ----
            new MenuItem('learning.courses', $C::LEARNING, 'Courses', 'courses', icon: 'graduation-cap', order: 10),
            new MenuItem('learning.create', $C::LEARNING, 'Create course', 'courses/create', permissions: ['course.create'], scopeCheck: 'any', icon: 'book', order: 20),

            // ---- Giving ----
            new MenuItem('giving.causes', $C::GIVING, 'Causes', 'causes', icon: 'hand-heart', order: 5),
            new MenuItem('giving.partnership', $C::GIVING, 'Partnership tiers', 'vbcs/partnership/tiers', icon: 'hand-heart', order: 10),
            new MenuItem('giving.partnership_admin', $C::GIVING, 'Manage partnership tiers', 'vbcs/partnership/tiers/manage', permissions: ['contribution.manage'], icon: 'sliders', order: 12),
            new MenuItem('giving.manual', $C::GIVING, 'Manual contributions review', 'vbcs/manual/pending', permissions: ['contribution.manage'], icon: 'receipt', order: 20),
            new MenuItem('giving.refunds', $C::GIVING, 'Refund approvals', 'contributions/refunds/pending', permissions: ['contribution.refund.approve'], icon: 'rotate-ccw', order: 30),

            // ---- Communications ----
            new MenuItem('comms.feed', $C::COMMUNICATIONS, 'Community feed', 'community/feed', icon: 'message-square', order: 10),
            new MenuItem('comms.campaigns', $C::COMMUNICATIONS, 'Broadcast campaigns', 'notifications/campaigns', icon: 'megaphone', order: 20),
            new MenuItem('comms.announcements', $C::COMMUNICATIONS, 'Announcements', 'announcements', permissions: ['notification.send'], scopeCheck: 'any', icon: 'megaphone', order: 22),
            new MenuItem('comms.announcements_inbox', $C::COMMUNICATIONS, 'Announcement inbox', 'announcements/inbox', icon: 'inbox', order: 24),
            // Whose provider account a body's messages send on, and how far down
            // its subtree that account is shared (FR-INT-007). Gated by the same
            // capability as the connection lifecycle it feeds.
            new MenuItem('comms.credentials', $C::COMMUNICATIONS, 'Group credentials', 'notifications/credentials', permissions: ['provider.configure'], icon: 'key', order: 30),
            new MenuItem('comms.templates', $C::COMMUNICATIONS, 'Notification templates', 'notifications/templates', permissions: ['provider.configure'], icon: 'file-text', order: 32),

            // ---- Streaming ----
            new MenuItem('streaming.console', $C::STREAMING, 'Live streams', 'streams', permissions: ['stream.moderate'], icon: 'video', order: 10),

            // ---- Reports & leaderboards ----
            new MenuItem('reports.leaderboard', $C::REPORTS, 'Leaderboards', 'gamification/leaderboard', icon: 'trophy', order: 10),
            new MenuItem('reports.individuals', $C::REPORTS, 'Top individuals', 'gamification/leaderboards/individuals', icon: 'award', order: 20),
            new MenuItem('reports.groups', $C::REPORTS, 'Top groups', 'gamification/leaderboards/groups', icon: 'users', order: 30),
            new MenuItem('reports.ranks', $C::REPORTS, 'Ranks', 'gamification/ranks', icon: 'award', order: 40),
            new MenuItem('reports.achievements', $C::REPORTS, 'Achievements', 'gamification/achievements', icon: 'trophy', order: 50),
            new MenuItem('reports.funnel', $C::REPORTS, 'Funnel dashboard', 'reports/funnel', permissions: ['report.view'], icon: 'chart-bar', order: 60),
            new MenuItem('reports.export', $C::REPORTS, 'Exports', 'reports/export', permissions: ['report.export'], scopeCheck: 'any', icon: 'download', order: 70),

            // ---- Access & Security (real routes) ----
            // NOTE: every item here MUST carry a permission, otherwise the whole
            // "Access & Security" category header would show to plain members. The
            // GET landing pages below are all permission-gated in Routes.php.
            new MenuItem('access.roles', $C::ACCESS, 'Roles', 'roles', permissions: ['access.role.manage'], icon: 'key', order: 20),
            new MenuItem('access.rules', $C::ACCESS, 'Rules', 'rules', permissions: ['access.rule.manage'], scopeCheck: 'any', icon: 'sliders', order: 30),
            new MenuItem('access.policies', $C::ACCESS, 'ABAC policies', 'abac-policies', permissions: ['access.policy.manage'], icon: 'sliders', order: 40),
            new MenuItem('access.breakglass', $C::ACCESS, 'Break-glass reviews', 'break-glass/pending-reviews', permissions: ['access.break_glass.review'], scopeCheck: 'any', icon: 'alert-triangle', order: 50),

            // ---- Administration ----
            new MenuItem('admin.settings', $C::ADMIN, 'Organization settings', 'admin', permissions: ['admin.manage'], icon: 'cog', order: 10),
            new MenuItem('admin.providers', $C::ADMIN, 'Providers', 'admin/providers', permissions: ['provider.configure'], icon: 'plug', order: 20),
            new MenuItem('admin.gam_config', $C::ADMIN, 'Points & rewards settings', 'gamification/config', permissions: ['gamification.manage'], icon: 'sliders', order: 30),
            new MenuItem('admin.gam_activities', $C::ADMIN, 'Activity catalog', 'gamification/activity-catalog', permissions: ['gamification.manage'], icon: 'list', order: 40),
            new MenuItem('admin.gam_badges', $C::ADMIN, 'Badges', 'gamification/badges', permissions: ['gamification.manage'], icon: 'award', order: 50),
            new MenuItem('admin.gam_rules', $C::ADMIN, 'Gamification rules', 'gamification/rules', permissions: ['gamification.manage'], icon: 'flag', order: 60),
            new MenuItem('admin.gam_activity_cats', $C::ADMIN, 'Activity categories', 'gamification/activity-categories', permissions: ['gamification.manage'], icon: 'layers', order: 62),
            new MenuItem('admin.gam_fu_types', $C::ADMIN, 'Follow-up types', 'gamification/follow-up-types', permissions: ['gamification.manage'], icon: 'list', order: 63),
            new MenuItem('admin.gam_fu_methods', $C::ADMIN, 'Follow-up methods', 'gamification/follow-up-methods', permissions: ['gamification.manage'], icon: 'list', order: 64),
            new MenuItem('admin.gam_ranks', $C::ADMIN, 'Rank definitions', 'gamification/rank-definitions', permissions: ['gamification.manage'], icon: 'award', order: 65),
            new MenuItem('admin.gam_streaks', $C::ADMIN, 'Streak definitions', 'gamification/streak-definitions', permissions: ['gamification.manage'], icon: 'flag', order: 66),
            new MenuItem('admin.gam_achievements', $C::ADMIN, 'Achievements', 'gamification/achievement-definitions', permissions: ['gamification.manage'], icon: 'award', order: 67),
            new MenuItem('admin.gam_pending', $C::ADMIN, 'Pending point awards', 'gamification/pending', permissions: ['gamification.manage'], scopeCheck: 'any', icon: 'inbox', order: 68),
            // integrations/catalog is now gated with provider.configure in Routes.php,
            // so this item is a projection of a real right (no category leak).
            new MenuItem('admin.integrations', $C::ADMIN, 'Integrations', 'integrations/catalog', permissions: ['provider.configure'], icon: 'plug', order: 70),
        ];
    }
}
