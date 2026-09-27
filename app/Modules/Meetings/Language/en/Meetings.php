<?php

declare(strict_types=1);

/**
 * Meetings module UI strings. English is the guaranteed fallback: every key here
 * MUST exist so a missing translation degrades to English rather than a raw key.
 * The schedule page is SELF-CONTAINED (own <html>) so it includes _locale.php for
 * a locale-aware <html lang dir> (RTL for Arabic). Counts are chosen in PHP; {0}
 * placeholders are interpolated via $li().
 *
 * `status`, `provider` and `mode` are FIXED vocabularies, localized with a
 * raw-value fallback. Title, times and join links are server data shown verbatim.
 */

return [
    'metaTitle'  => 'Meetings — Schedule',
    'heading'    => 'Meeting schedule',
    'sub'        => 'Scheduled and live meetings and webinars across the organization.',
    'count'      => '{0} meetings',
    'countOne'   => '{0} meeting',
    'empty'      => 'No meetings scheduled yet.',
    'colTitle'   => 'Meeting',
    'colWhen'    => 'When',
    'colProvider'=> 'Provider',
    'colStatus'  => 'Status',
    'colAccess'  => 'Access',
    'join'       => 'Join link',
    'noJoin'     => 'No link',
    'noTime'     => 'Time not set',

    // Meeting status — fixed vocabulary, localized with raw-value fallback.
    'status' => [
        'scheduled' => 'Scheduled',
        'live'      => 'Live',
        'ended'     => 'Ended',
        'canceled'  => 'Canceled',
    ],

    // Provider — fixed vocabulary, localized with raw-value fallback.
    'provider' => [
        'zoom'  => 'Zoom',
        'meet'  => 'Google Meet',
        'teams' => 'Microsoft Teams',
        'jitsi' => 'Jitsi',
        'link'  => 'Hosted link',
    ],

    // Mode + access policy — fixed vocabularies.
    'mode' => [
        'meeting' => 'Meeting',
        'webinar' => 'Webinar',
    ],
    'access' => [
        'public'     => 'Public',
        'restricted' => 'Restricted',
    ],
    // Added: write-UI (role)
    'role' => [
        'host' => 'Host',
        'cohost' => 'Co-host',
        'attendee' => 'Attendee',
    ],

    // Added: write-UI (admin)
    'admin' => [
        'colActions' => 'Actions',
        'scheduleHeading' => 'Schedule a meeting',
        'titleLabel' => 'Title',
        'titlePh' => 'e.g. Sunday Leaders Call',
        'providerLabel' => 'Provider',
        'modeLabel' => 'Mode',
        'accessLabel' => 'Access',
        'startsLabel' => 'Starts at',
        'endsLabel' => 'Ends at',
        'joinUrlLabel' => 'Hosted join link (optional)',
        'joinUrlHint' => 'Must start with https://. Leave blank to let a connected provider create the meeting.',
        'scheduleBtn' => 'Schedule meeting',
        'statusLabel' => 'Status',
        'setStatusBtn' => 'Update',
        'userIdLabel' => 'User',
        'userIdPh' => 'User ID',
        'userIdNone' => '— Select a person —',
        'roleLabel' => 'Role',
        'grantBtn' => 'Grant access',
        'createdFlash' => 'Meeting scheduled.',
        'transitionedFlash' => 'Meeting status updated.',
        'grantedFlash' => 'Join access granted.',
    ],

];
  'dashboard' => [
    'myMeetings' => 'My Meetings',
    'groupSchedule' => 'Group Schedule',
    'noMeetings' => 'No meetings',
    'noSchedule' => 'No schedule',
    'noGroups' => 'No groups in scope',
    'untitled' => 'Untitled meeting',
  ],
