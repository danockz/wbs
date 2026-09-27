# Hierarchical Group Dashboard - Module Widgets

## Overview

This document lists all the widget providers and renderers created for the hierarchical group dashboard across all modules.

## Module: Groups (Core)

### Widget Provider
- `WBS\Groups\Services\GroupsDashboardWidgetProvider`

### Widgets
| Key | Label | Permission | Capability | Scope | Section | Renderer |
|-----|-------|-----------|------------|-------|---------|----------|
| membership_summary | My Groups | null | null | membership | people | GroupMembershipWidgetRenderer |
| hierarchy_nav | Group Hierarchy | null | null | membership | people | GroupHierarchyWidgetRenderer |
| birthdays | Birthdays | null | groups.birthdays | ancestor_only | people | GroupBirthdaysWidgetRenderer |

### Renderers
- `WBS\Groups\Services\GroupMembershipWidgetRenderer`
- `WBS\Groups\Services\GroupHierarchyWidgetRenderer`
- `WBS\Groups\Services\GroupBirthdaysWidgetRenderer`

---

## Module: Gamification

### Widget Provider
- `WBS\Gamification\Services\GamificationDashboardWidgetProvider`

### Widgets
| Key | Label | Permission | Capability | Scope | Section | Renderer |
|-----|-------|-----------|------------|-------|---------|----------|
| my_standing | My Standing | null | null | self | gamification | MyStandingWidgetRenderer |
| my_points | My Points | null | null | self | gamification | MyPointsWidgetRenderer |
| my_badges | My Badges | null | null | self | gamification | MyBadgesWidgetRenderer |
| group_leaderboard | Group Leaderboard | report.view | null | membership_desc | gamification | GroupLeaderboardWidgetRenderer |
| my_streaks | My Streaks | null | null | self | gamification | MyStreaksWidgetRenderer |
| my_achievements | My Achievements | null | null | self | gamification | MyAchievementsWidgetRenderer |

### Renderers
- `WBS\Gamification\Services\MyStandingWidgetRenderer` - Shows rank, points, progress to next
- `WBS\Gamification\Services\MyPointsWidgetRenderer` - Recent point transactions
- `WBS\Gamification\Services\MyBadgesWidgetRenderer` - Earned badges
- `WBS\Gamification\Services\GroupLeaderboardWidgetRenderer` - Top users by points
- `WBS\Gamification\Services\MyStreaksWidgetRenderer` - Active streaks
- `WBS\Gamification\Services\MyAchievementsWidgetRenderer` - Unlocked achievements

---

## Module: Identity

### Widget Provider
- `WBS\Identity\Services\IdentityDashboardWidgetProvider`

### Widgets
| Key | Label | Permission | Capability | Scope | Section | Renderer |
|-----|-------|-----------|------------|-------|---------|----------|
| my_profile | My Profile | null | null | self | people | MyProfileWidgetRenderer |
| my_memberships | My Memberships | null | null | membership | people | MyMembershipsWidgetRenderer |
| recent_members | Recent Members | identity.manage | null | membership_desc | people | RecentMembersWidgetRenderer |
| membership_conflicts | Membership Conflicts | identity.manage | null | membership_desc | people | MembershipConflictsWidgetRenderer |

### Renderers
- `WBS\Identity\Services\MyProfileWidgetRenderer` - User profile summary
- `WBS\Identity\Services\MyMembershipsWidgetRenderer` - Group memberships list
- `WBS\Identity\Services\RecentMembersWidgetRenderer` - New members in scope
- `WBS\Identity\Services\MembershipConflictsWidgetRenderer` - Users with multiple memberships

---

## Module: Journey

### Widget Provider
- `WBS\Journey\Services\JourneyDashboardWidgetProvider`

### Widgets
| Key | Label | Permission | Capability | Scope | Section | Renderer |
|-----|-------|-----------|------------|-------|---------|----------|
| my_stage | My Journey Stage | null | null | self | journey | MyStageWidgetRenderer |
| my_progress | My Journey Progress | null | null | self | journey | MyProgressWidgetRenderer |
| group_pipeline | Group Pipeline | null | null | membership_desc | journey | GroupPipelineWidgetRenderer |
| my_disciples | My Disciples | null | null | self | journey | MyDisciplesWidgetRenderer |
| discipling_leaderboard | Discipling Leaderboard | report.view | null | membership_desc | journey | DisciplingLeaderboardWidgetRenderer |

### Renderers
- `WBS\Journey\Services\MyStageWidgetRenderer` - Current discipleship stage
- `WBS\Journey\Services\MyProgressWidgetRenderer` - Journey milestones completed
- `WBS\Journey\Services\GroupPipelineWidgetRenderer` - Pipeline counts by stage
- `WBS\Journey\Services\MyDisciplesWidgetRenderer` - People being discipled
- `WBS\Journey\Services\DisciplingLeaderboardWidgetRenderer` - Top disciplers

---

## Module: Events

### Widget Provider
- `WBS\Events\Services\EventsDashboardWidgetProvider`

### Widgets
| Key | Label | Permission | Capability | Scope | Section | Renderer |
|-----|-------|-----------|------------|-------|---------|----------|
| my_upcoming | My Upcoming Events | null | null | self | events | MyUpcomingEventsWidgetRenderer |
| group_upcoming | Group Upcoming Events | null | null | membership_desc | events | GroupUpcomingEventsWidgetRenderer |
| my_registrations | My Event Registrations | null | null | self | events | MyRegistrationsWidgetRenderer |
| my_events | Events I'm Organizing | event.create | null | self | events | MyEventsWidgetRenderer |
| committee_tasks | Committee Tasks | null | event_committee | membership | events | CommitteeTasksWidgetRenderer |
| event_analytics | Event Analytics | report.view | null | membership_desc | events | EventAnalyticsWidgetRenderer |

### Renderers
- `WBS\Events\Services\MyUpcomingEventsWidgetRenderer` - Events user is registered for
- `WBS\Events\Services\GroupUpcomingEventsWidgetRenderer` - Upcoming events in scope groups
- `WBS\Events\Services\MyRegistrationsWidgetRenderer` - All registrations
- `WBS\Events\Services\MyEventsWidgetRenderer` - Events user is organizing
- `WBS\Events\Services\CommitteeTasksWidgetRenderer` - Committee tasks (if feature enabled)
- `WBS\Events\Services\EventAnalyticsWidgetRenderer` - Event statistics

---

## Module: Contributions

### Widget Provider
- `WBS\Contributions\Services\ContributionsDashboardWidgetProvider`

### Widgets
| Key | Label | Permission | Capability | Scope | Section | Renderer |
|-----|-------|-----------|------------|-------|---------|----------|
| my_giving | My Giving | null | null | self | giving | MyGivingWidgetRenderer |
| my_commitments | My Commitments | null | null | self | giving | MyCommitmentsWidgetRenderer |
| group_giving | Group Giving | contribution.manage | null | membership_desc | giving | GroupGivingWidgetRenderer |
| giving_leaderboard | Giving Leaderboard | report.view | null | membership_desc | giving | GivingLeaderboardWidgetRenderer |
| my_causes | Causes I Support | null | null | self | giving | MyCausesWidgetRenderer |

### Renderers
- `WBS\Contributions\Services\MyGivingWidgetRenderer` - Giving summary
- `WBS\Contributions\Services\MyCommitmentsWidgetRenderer` - Recurring commitments
- `WBS\Contributions\Services\GroupGivingWidgetRenderer` - Group giving statistics
- `WBS\Contributions\Services\GivingLeaderboardWidgetRenderer` - Top givers
- `WBS\Contributions\Services\MyCausesWidgetRenderer` - Supported causes

---

## Module: Courses

### Widget Provider
- `WBS\Courses\Services\CoursesDashboardWidgetProvider`

### Widgets
| Key | Label | Permission | Capability | Scope | Section | Renderer |
|-----|-------|-----------|------------|-------|---------|----------|
| my_courses | My Courses | null | null | self | learning | MyCoursesWidgetRenderer |
| my_progress | My Course Progress | null | null | self | learning | MyCourseProgressWidgetRenderer |
| group_courses | Group Courses | course.create | null | membership_desc | learning | GroupCoursesWidgetRenderer |
| completion_rates | Completion Rates | report.view | null | membership_desc | learning | CompletionRatesWidgetRenderer |

### Renderers
- `WBS\Courses\Services\MyCoursesWidgetRenderer` - User's course enrollments
- `WBS\Courses\Services\MyCourseProgressWidgetRenderer` - Course progress percentages
- `WBS\Courses\Services\GroupCoursesWidgetRenderer` - Courses in scope groups
- `WBS\Courses\Services\CompletionRatesWidgetRenderer` - Course completion statistics

---

## Module: Community

### Widget Provider
- `WBS\Community\Services\CommunityDashboardWidgetProvider`

### Widgets
| Key | Label | Permission | Capability | Scope | Section | Renderer |
|-----|-------|-----------|------------|-------|---------|----------|
| group_feed | Group Feed | null | null | membership | community | GroupFeedWidgetRenderer |
| recent_posts | Recent Posts | null | null | membership_desc | community | RecentPostsWidgetRenderer |

### Renderers
- `WBS\Community\Services\GroupFeedWidgetRenderer` - Feed posts from user's groups
- `WBS\Community\Services\RecentPostsWidgetRenderer` - Recent posts in scope

---

## Module: Announcements

### Widget Provider
- `WBS\Announcements\Services\AnnouncementsDashboardWidgetProvider`

### Widgets
| Key | Label | Permission | Capability | Scope | Section | Renderer |
|-----|-------|-----------|------------|-------|---------|----------|
| my_announcements | My Announcements | null | null | self | comms | MyAnnouncementsWidgetRenderer |
| group_announcements | Group Announcements | notification.send | null | membership_desc | comms | GroupAnnouncementsWidgetRenderer |

### Renderers
- `WBS\Announcements\Services\MyAnnouncementsWidgetRenderer` - Announcements for user
- `WBS\Announcements\Services\GroupAnnouncementsWidgetRenderer` - Announcements in scope groups

---

## Module: Notifications

### Widget Provider
- `WBS\Notifications\Services\NotificationsDashboardWidgetProvider`

### Widgets
| Key | Label | Permission | Capability | Scope | Section | Renderer |
|-----|-------|-----------|------------|-------|---------|----------|
| my_notifications | My Notifications | null | null | self | comms | MyNotificationsWidgetRenderer |
| campaign_status | Campaign Status | notification.send | null | membership_desc | comms | CampaignStatusWidgetRenderer |

### Renderers
- `WBS\Notifications\Services\MyNotificationsWidgetRenderer` - User's notifications
- `WBS\Notifications\Services\CampaignStatusWidgetRenderer` - Campaign status in scope

---

## Module: AccessControl

### Widget Provider
- `WBS\AccessControl\Services\AccessControlDashboardWidgetProvider`

### Widgets
| Key | Label | Permission | Capability | Scope | Section | Renderer |
|-----|-------|-----------|------------|-------|---------|----------|
| my_requests | My Access Requests | null | null | self | access | MyRequestsWidgetRenderer |
| pending_approvals | Pending Approvals | access.request.approve | null | membership_desc | access | PendingApprovalsWidgetRenderer |

### Renderers
- `WBS\AccessControl\Services\MyRequestsWidgetRenderer` - User's access requests
- `WBS\AccessControl\Services\PendingApprovalsWidgetRenderer` - Requests awaiting approval

---

## Module: Reporting

### Widget Provider
- `WBS\Reporting\Services\ReportingDashboardWidgetProvider`

### Widgets
| Key | Label | Permission | Capability | Scope | Section | Renderer |
|-----|-------|-----------|------------|-------|---------|----------|
| group_funnel | Group Funnel | report.view | null | membership_desc | reports | GroupFunnelWidgetRenderer |

### Renderers
- `WBS\Reporting\Services\GroupFunnelWidgetRenderer` - Funnel metrics for scope

---

## Module: Referrals

### Widget Provider
- `WBS\Referrals\Services\ReferralsDashboardWidgetProvider`

### Widgets
| Key | Label | Permission | Capability | Scope | Section | Renderer |
|-----|-------|-----------|------------|-------|---------|----------|
| my_referrals | My Referrals | null | null | self | people | MyReferralsWidgetRenderer |
| group_outreach | Group Outreach | null | null | membership_desc | people | GroupOutreachWidgetRenderer |

### Renderers
- `WBS\Referrals\Services\MyReferralsWidgetRenderer` - User's referrals
- `WBS\Referrals\Services\GroupOutreachWidgetRenderer` - Outreach stats for scope

---

## Module: Meetings

### Widget Provider
- `WBS\Meetings\Services\MeetingsDashboardWidgetProvider`

### Widgets
| Key | Label | Permission | Capability | Scope | Section | Renderer |
|-----|-------|-----------|------------|-------|---------|----------|
| my_meetings | My Meetings | null | null | self | events | MyMeetingsWidgetRenderer |
| group_schedule | Group Schedule | meeting.manage | null | membership_desc | events | GroupScheduleWidgetRenderer |

### Renderers
- `WBS\Meetings\Services\MyMeetingsWidgetRenderer` - Meetings user is attending
- `WBS\Meetings\Services\GroupScheduleWidgetRenderer` - Schedule for scope groups

---

## Module: Streaming

### Widget Provider
- `WBS\Streaming\Services\StreamingDashboardWidgetProvider`

### Widgets
| Key | Label | Permission | Capability | Scope | Section | Renderer |
|-----|-------|-----------|------------|-------|---------|----------|
| live_streams | Live Streams | stream.moderate | null | membership_desc | streaming | LiveStreamsWidgetRenderer |

### Renderers
- `WBS\Streaming\Services\LiveStreamsWidgetRenderer` - Active live streams

---

## Registration

All widget providers are registered in `WBS\Groups\Config\Services::groupDashboard()`:

```php
$service->registerProvider(new GroupsDashboardWidgetProvider());
$service->registerProvider(new GamificationDashboardWidgetProvider());
$service->registerProvider(new IdentityDashboardWidgetProvider());
$service->registerProvider(new JourneyDashboardWidgetProvider());
$service->registerProvider(new EventsDashboardWidgetProvider());
$service->registerProvider(new ContributionsDashboardWidgetProvider());
$service->registerProvider(new CoursesDashboardWidgetProvider());
$service->registerProvider(new CommunityDashboardWidgetProvider());
$service->registerProvider(new AnnouncementsDashboardWidgetProvider());
$service->registerProvider(new NotificationsDashboardWidgetProvider());
$service->registerProvider(new AccessControlDashboardWidgetProvider());
$service->registerProvider(new ReportingDashboardWidgetProvider());
$service->registerProvider(new ReferralsDashboardWidgetProvider());
$service->registerProvider(new MeetingsDashboardWidgetProvider());
$service->registerProvider(new StreamingDashboardWidgetProvider());
```

## Widget Count Summary

- **Groups**: 3 widgets
- **Gamification**: 6 widgets
- **Identity**: 4 widgets
- **Journey**: 5 widgets
- **Events**: 6 widgets
- **Contributions**: 5 widgets
- **Courses**: 4 widgets
- **Community**: 2 widgets
- **Announcements**: 2 widgets
- **Notifications**: 2 widgets
- **AccessControl**: 2 widgets
- **Reporting**: 1 widget
- **Referrals**: 2 widgets
- **Meetings**: 2 widgets
- **Streaming**: 1 widget

**Total: 42 widgets across 14 modules**

## Section Organization

Widgets are organized into the following sections:

| Section | Modules | Total Widgets |
|---------|---------|---------------|
| people | Groups, Identity, Journey, Referrals | 11 |
| gamification | Gamification | 6 |
| journey | Journey | 5 |
| events | Events, Meetings | 8 |
| giving | Contributions | 5 |
| learning | Courses | 4 |
| community | Community | 2 |
| comms | Announcements, Notifications | 4 |
| access | AccessControl | 2 |
| reports | Reporting | 1 |
| streaming | Streaming | 1 |

**Total: 49 widgets organized into 10 sections**

## Scope Usage

| Scope | Count | Description |
|-------|-------|-------------|
| self | 19 | User's own data |
| membership | 6 | Groups user is member of |
| membership_desc | 17 | Membership groups + descendants |
| descendants | 0 | Only descendants |
| ancestors | 0 | Only ancestors |
| ancestor_only | 1 | Only ancestor groups (birthdays) |

## Permission Gating

Widgets requiring permissions:
- `report.view`: 7 widgets (leaderboards, analytics, funnels)
- `identity.manage`: 2 widgets (recent members, conflicts)
- `contribution.manage`: 1 widget (group giving)
- `event.create`: 1 widget (my events)
- `course.create`: 1 widget (group courses)
- `notification.send`: 2 widgets (group announcements, campaign status)
- `meeting.manage`: 1 widget (group schedule)
- `stream.moderate`: 1 widget (live streams)
- `access.request.approve`: 1 widget (pending approvals)

**Total: 17 widgets with permission gates**

## Config Capability Gating

Widgets requiring config capabilities:
- `groups.birthdays`: 1 widget (birthdays)
- `event_committee`: 1 widget (committee tasks)

**Total: 2 widgets with config capability gates**

## Fail-Closed Behavior

All widgets are **fail-closed**:
- If user lacks required permission → widget hidden
- If required capability config is OFF/absent → widget hidden
- If scope resolves to no groups → widget shows empty state
- No partial rendering or error messages for unauthorized access
