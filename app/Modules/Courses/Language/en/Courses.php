<?php

declare(strict_types=1);

/**
 * Courses module UI strings (SRS FR-CRS-*). English is the guaranteed fallback:
 * every key here MUST exist so a missing translation elsewhere degrades to
 * English rather than exposing a raw key.
 *
 * Number interpolation and singular/plural selection are done in PHP in the view
 * (not via ICU {} placeholders), so translations render correctly whether or not
 * ext-intl is installed. Enum-ish maps (status, drip) fall back to the raw stored
 * value when a key is missing, so unknown values never break a page.
 */

return [
    // Index / catalogue (index.php).
    'title'          => 'Courses',
    'course'         => 'course',
    'courses'        => 'courses',
    'empty'          => 'No courses yet.',
    'courseFallback' => 'Course',
    'lesson'         => 'lesson',
    'lessons'        => 'lessons',

    // Status labels (fall back to the raw value in-view when a key is absent).
    'status' => [
        'draft'     => 'draft',
        'published' => 'published',
        'archived'  => 'archived',
    ],

    // Overview / authoring (overview.php).
    'lessonsLbl'    => 'Lessons',
    'required'      => 'Required',
    'statusLbl'     => 'Status',
    'description'   => 'Description',
    'lessonsHeading' => 'Lessons',
    'noLessons'     => 'No lessons added yet.',
    'lessonFallback' => 'Lesson',
    'requiredTag'   => 'required',
    'contentAttached' => '✓ content attached',
    'noContent'     => '⚠ no content attached',
    'courseMeta'    => 'Course {0}',
    'viewSyllabus'  => 'view learner syllabus →',

    // Drip labels (fall back to raw value).
    'drip' => [
        'immediate' => 'immediate',
    ],

    // Learner syllabus (syllabus.php).
    'syllabusTitle' => 'Course syllabus',
    'syllabusSub'   => 'locked lessons show only their unlock time',
    'nonePublished' => 'No lessons published yet.',
    'unlocks'       => '🔒 unlocks {0}',
    'unlockLater'   => 'later',
    'available'     => '✓ available',
    'contentLabel'  => 'Content: {0}',

    // Create-course FORM page (GET /courses/create).
    'createForm' => [
        'metaTitle'    => 'Create course',
        'heading'      => 'Create a course',
        'sub'          => 'Set up a new course. It starts as a draft you can publish later.',
        'titleLabel'   => 'Title',
        'titlePh'      => 'e.g. Foundations of Faith',
        'categoryLabel'=> 'Category (optional)',
        'deliveryLabel'=> 'Delivery mode',
        'descLabel'    => 'Description (optional)',
        'submit'       => 'Create course',
        'required'     => 'Required',
        'delivery' => [
            'self_paced' => 'Self-paced',
            'cohort'     => 'Cohort',
            'blended'    => 'Blended',
        ],
    ],
    // Authoring card (overview.php): publish + add-lesson write controls.
    'authoring' => [
        'heading' => 'Authoring',
        'publishBtn' => 'Publish course',
        'publishHint' => 'Make this course visible to learners.',
        'publishConfirm' => 'Publish this course now?',
        'publishedFlash' => 'Course published.',
        'lessonHeading' => 'Add a lesson',
        'lessonTitleLabel' => 'Lesson title',
        'lessonTitlePh' => 'e.g. Week 1 — Welcome',
        'lessonPositionLabel' => 'Position',
        'lessonRequiredLabel' => 'Required lesson',
        'lessonContentLabel' => 'Content reference (optional)',
        'lessonContentPh' => 'e.g. video:abc123 or a lesson URL',
        'lessonAddBtn' => 'Add lesson',
        'lessonAddedFlash' => 'Lesson added.',
        'publishedNote' => 'This course is already published.',
    ],
    'learner' => [
        'enrolPrompt' => 'Enrol to start this course and track your progress.',
        'enrolBtn' => 'Enrol now',
        'enrolledFlash' => 'You are enrolled.',
        'signInToEnrol' => 'Sign in to enrol in this course.',
        'progressLabel' => '{0} of {1} required lessons complete ({2}%)',
        'markCompleteBtn' => 'Mark complete',
        'lessonDone' => 'Completed',
        'lessonDoneFlash' => 'Lesson marked complete.',
        'lessonLocked' => 'Unlocks later',
        'courseCompleted' => 'Course completed',
    ],

];
