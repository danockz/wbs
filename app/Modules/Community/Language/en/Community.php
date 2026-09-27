<?php

declare(strict_types=1);

/**
 * Community module UI strings (SRS FR-COM-*). English is the guaranteed fallback:
 * every key here MUST exist so a missing translation elsewhere degrades to
 * English rather than exposing a raw key.
 *
 * Number interpolation and singular/plural selection are done in PHP in the view
 * (not via ICU {} placeholders), so translations render correctly whether or not
 * ext-intl is installed. Free-form service data — post author id, timestamps,
 * post title, and the already-sanitized body_html — stays VERBATIM (it is data,
 * not UI copy). Post visibility is an enum-ish label that localizes but falls
 * back to the raw stored value so unknown values never break a page.
 */

return [
    'title'          => 'Community feed',
    'post'           => 'post',
    'posts'          => 'posts',
    'visibleToYou'   => 'visible to you',
    'empty'          => 'No posts to show yet.',
    'memberFallback' => 'Member',
    'pinned'         => '📌 pinned',

    // Visibility labels (fall back to the raw value in-view when a key is absent).
    'visibility' => [
        'group'   => 'group',
        'public'  => 'public',
        'private' => 'private',
        'org'     => 'org',
    ],
    // Added: write-UI (reason)
    'reason' => [
        'spam' => 'Spam',
        'harassment' => 'Harassment',
        'inappropriate' => 'Inappropriate',
        'misinformation' => 'Misinformation',
        'other' => 'Other',
    ],

    // Added: write-UI (action)
    'action' => [
        'hide' => 'Hide',
        'lock' => 'Lock',
        'unlock' => 'Unlock',
        'restore' => 'Restore',
        'delete' => 'Delete',
        'escalate' => 'Escalate',
    ],

    // Added: write-UI (admin)
    'admin' => [
        'composeHeading' => 'Write a post',
        'titleLabel' => 'Title (optional)',
        'titlePh' => 'A short headline',
        'bodyLabel' => 'Message',
        'bodyPh' => 'Share something with your community…',
        'visibilityLabel' => 'Visibility',
        'postBtn' => 'Post',
        'reactBtn' => 'Like',
        'commentPh' => 'Write a comment…',
        'commentBtn' => 'Comment',
        'reasonLabel' => 'Reason',
        'reportBtn' => 'Report',
        'reportConfirm' => 'Report this post to moderators?',
        'actionLabel' => 'Action',
        'moderateReasonPh' => 'Reason (required)',
        'moderateBtn' => 'Apply',
        'moderateConfirm' => 'Apply this moderation action?',
        'postedFlash' => 'Post published.',
        'commentedFlash' => 'Comment added.',
        'reactedFlash' => 'Reaction updated.',
        'reportedFlash' => 'Report submitted. Thank you.',
        'moderatedFlash' => 'Moderation action applied.',
    ],

];
  'dashboard' => [
    'groupFeed' => 'Group Feed',
    'recentPosts' => 'Recent Posts',
    'noGroups' => 'No groups in scope',
    'noPosts' => 'No posts',
    'untitled' => 'Untitled post',
    'anonymous' => 'Anonymous',
  ],
