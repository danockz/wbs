<?php

declare(strict_types=1);

/** Courses — Chinese (zh). Values only; keys mirror en/Courses.php. */

return [
    'title'          => '课程',
    'course'         => '门课程',
    'courses'        => '门课程',
    'empty'          => '暂无课程。',
    'courseFallback' => '课程',
    'lesson'         => '节课',
    'lessons'        => '节课',

    'status' => [
        'draft'     => '草稿',
        'published' => '已发布',
        'archived'  => '已归档',
    ],

    'lessonsLbl'      => '课时',
    'required'        => '必修',
    'statusLbl'       => '状态',
    'description'     => '简介',
    'lessonsHeading'  => '课时',
    'noLessons'       => '尚未添加任何课时。',
    'lessonFallback'  => '课时',
    'requiredTag'     => '必修',
    'contentAttached' => '✓ 已附内容',
    'noContent'       => '⚠ 未附内容',
    'courseMeta'      => '课程 {0}',
    'viewSyllabus'    => '查看学员大纲 →',

    'drip' => [
        'immediate' => '立即',
    ],

    'syllabusTitle' => '课程大纲',
    'syllabusSub'   => '锁定的课时仅显示解锁时间',
    'nonePublished' => '尚未发布任何课时。',
    'unlocks'       => '🔒 {0} 解锁',
    'unlockLater'   => '稍后',
    'available'     => '✓ 可学习',
    'contentLabel'  => '内容：{0}',

    // Create-course FORM page (GET /courses/create).
    'createForm' => [
        'metaTitle'    => '创建课程',
        'heading'      => '创建课程',
        'sub'          => '设置一门新课程。它以草稿开始，稍后可发布。',
        'titleLabel'   => '标题',
        'titlePh'      => '例如：信仰基础',
        'categoryLabel'=> '类别（可选）',
        'deliveryLabel'=> '授课方式',
        'descLabel'    => '描述（可选）',
        'submit'       => '创建课程',
        'required'     => '必填',
        'delivery' => [
            'self_paced' => '自主进度',
            'cohort'     => '班级',
            'blended'    => '混合',
        ],
    ],
    // Authoring card (overview.php): publish + add-lesson write controls.
    'authoring' => [
        'heading' => '编创',
        'publishBtn' => '发布课程',
        'publishHint' => '让学员可以看到此课程。',
        'publishConfirm' => '现在发布此课程？',
        'publishedFlash' => '课程已发布。',
        'lessonHeading' => '添加课时',
        'lessonTitleLabel' => '课时标题',
        'lessonTitlePh' => '例如 第1周 — 欢迎',
        'lessonPositionLabel' => '排序',
        'lessonRequiredLabel' => '必修课时',
        'lessonContentLabel' => '内容引用（可选）',
        'lessonContentPh' => '例如 video:abc123 或课时链接',
        'lessonAddBtn' => '添加课时',
        'lessonAddedFlash' => '课时已添加。',
        'publishedNote' => '此课程已发布。',
    ],
    'learner' => [
        'enrolPrompt' => '报名以开始本课程并跟踪你的进度。',
        'enrolBtn' => '立即报名',
        'enrolledFlash' => '你已报名。',
        'signInToEnrol' => '登录以报名此课程。',
        'progressLabel' => '{0}/{1} 门必修课完成（{2}%）',
        'markCompleteBtn' => '标记完成',
        'lessonDone' => '已完成',
        'lessonDoneFlash' => '课程已标记为完成。',
        'lessonLocked' => '稍后解锁',
        'courseCompleted' => '课程已完成',
    ],

];
