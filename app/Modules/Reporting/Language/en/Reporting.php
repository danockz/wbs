<?php

declare(strict_types=1);

/**
 * Reporting module UI strings (SRS FR-RPT-*). English is the guaranteed fallback:
 * every key here MUST exist so a missing translation elsewhere degrades to
 * English rather than exposing a raw key.
 *
 * Number interpolation uses {0}/{1} placeholders resolved in PHP in the view
 * (not via ICU {} runtime), so translations render correctly whether or not
 * ext-intl is installed. Free-form service data — event mode/RSVP state, group
 * role/type, course category, certificate status, the delayed-job warning text —
 * stays VERBATIM (it is data, not UI copy) exactly as it arrives from the
 * services, so unknown values never break a page.
 */

return [
    // ── Funnel dashboard (funnel.php) ──────────────────────────────────────
    'funnel' => [
        'title'   => 'Win · Build · Send funnel',
        'sub'     => 'Role/scope dashboard — counts distinguish unique people from actions.',

        'winHeading'   => 'Win — acquisition & conversion',
        'prospects'    => 'Prospects',
        'membersUnique' => 'Members (unique)',
        'referralConversions' => 'Referral conversions',

        'buildHeading'      => 'Build — training, attendance, giving',
        'courseEnrollments' => 'Course enrollments',
        'courseCompletions' => 'Course completions',
        'eventsHeld'        => 'Events held',
        'uniqueAttendees'   => 'Unique attendees',
        'verifiedGiving'    => 'Verified giving (minor)',

        'sendHeading'   => 'Send — leadership & gamification',
        'pointsAwarded' => 'Points awarded',

        'asOf'          => 'As of: {0}',
        'source'        => 'Source: {0}',
        'completeness'  => 'Completeness: {0}',
        'suppression'   => 'Suppression threshold: {0}',
        'na'            => 'n/a',
    ],

    // ── Member self-service dashboard (member_dashboard.php) ────────────────
    'member' => [
        'welcome'        => 'Welcome, {0}',
        'memberFallback' => 'Member',
        'subtitle'       => 'Your personal dashboard',
        'memberSince'    => 'member since {0}',

        // Standing.
        'standing'        => 'Standing',
        'pointsSeason'    => 'Points (this season)',
        'seasonLabel'     => 'Season {0}',
        'rank'            => 'Rank',
        'unranked'        => 'Unranked',
        'ptsToRank'       => '{0} pts to {1}',
        'topRank'         => 'Top rank',
        'badges'          => 'Badges',
        'achievements'    => 'Achievements',
        'ptsToGo'         => '{0} pts to go',
        'pctToNext'       => '{0}% to next rank',

        // Milestones.
        'milestones'    => 'My milestones',
        'reached'       => 'Reached',
        'nextUp'        => 'Next up',
        'toGo'          => '{0} to go',
        // Milestone track labels ({0} = tier count). Singular/plural variants are
        // chosen in the view from the milestone's tier; the tenure track reads
        // naturally at every tier so it needs no plural form.
        'ms' => [
            'tenure'             => '{0}-year member',
            'events_one'         => 'Attended {0} event',
            'events_other'       => 'Attended {0} events',
            'courses_one'        => 'Completed {0} course',
            'courses_other'      => 'Completed {0} courses',
            'certificates_one'   => 'Earned {0} certificate',
            'certificates_other' => 'Earned {0} certificates',
            'giving_one'         => '{0} verified gift',
            'giving_other'       => '{0} verified gifts',
        ],

        // Badges & achievements.
        'badgesAch'     => 'Badges & achievements',
        'badgesEarned'  => 'Badges earned',
        'awardedTitle'  => 'Awarded {0}',
        'xp'            => '{0} XP',
        'almostThere'   => 'Almost there',
        'unlockedOn'    => 'Unlocked {0}',

        // Streaks.
        'streaks'       => 'Streaks',
        'best'          => 'Best: {0}',

        // Events.
        'upcomingEvents' => 'Upcoming events',
        'noEvents'       => 'No upcoming events.',
        'browseEvents'   => 'Browse events →',

        // Certificates.
        'certificates'      => 'My certificates',
        'noCerts'           => 'No certificates yet — attend an event to earn one.',
        'certFallback'      => 'Certificate',
        'issued'            => 'Issued {0}',
        'verifyId'          => 'Verify ID:',
        'downloadPdf'       => 'Download PDF',

        // Learning.
        'learning'      => 'My learning',
        'activeCourses' => 'Active courses',
        'completed'     => 'Completed',
        'courseFallback' => 'Course',
        'completedOn'   => 'Completed {0}',
        'enrolledOn'    => 'Enrolled {0}',

        // Groups.
        'groups'        => 'My groups',
        'noGroups'      => 'You are not a member of any group yet.',
        'joined'        => 'Joined {0}',

        'metaSelfScoped' => 'As of {0} · self-scoped view',
    ],

    // Export history page (GET /reports/export).
    'exports' => [
        'metaTitle'   => 'Export history',
        'heading'     => 'Export history',
        'sub'         => 'Requested report exports and their status.',
        'count'       => '{0} exports',
        'countOne'    => '{0} export',
        'empty'       => 'No exports requested yet.',
        'colReport'   => 'Report',
        'colFormat'   => 'Format',
        'colStatus'   => 'Status',
        'colRows'     => 'Rows',
        'colRequested'=> 'Requested',
        'colExpires'  => 'Expires',
        'noRows'      => '—',
        'noExpiry'    => '—',
        'download'    => 'Download',
        'requestHeading' => 'Request an export',
        'reportLabel' => 'Report',
        'formatLabel' => 'Format',
        'requestBtn' => 'Request export',
        'requestedFlash' => 'Export requested. It will appear below when ready.',
        'report' => [
            'wbs_funnel' => 'Win–Build–Send funnel',
        ],
        'status' => [
            'queued'  => 'Queued',
            'running' => 'Running',
            'ready'   => 'Ready',
            'failed'  => 'Failed',
            'expired' => 'Expired',
        ],
    ],
];
