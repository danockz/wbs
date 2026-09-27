<?php

declare(strict_types=1);

/**
 * Courses — Arabic (ar, RTL). Values only; keys mirror en/Courses.php. The
 * shared layout emits dir="rtl" for this locale.
 */

return [
    'title'          => 'الدورات',
    'course'         => 'دورة',
    'courses'        => 'دورات',
    'empty'          => 'لا توجد دورات بعد.',
    'courseFallback' => 'دورة',
    'lesson'         => 'درس',
    'lessons'        => 'دروس',

    'status' => [
        'draft'     => 'مسودة',
        'published' => 'منشورة',
        'archived'  => 'مؤرشفة',
    ],

    'lessonsLbl'      => 'الدروس',
    'required'        => 'المطلوبة',
    'statusLbl'       => 'الحالة',
    'description'     => 'الوصف',
    'lessonsHeading'  => 'الدروس',
    'noLessons'       => 'لم تتم إضافة دروس بعد.',
    'lessonFallback'  => 'درس',
    'requiredTag'     => 'مطلوب',
    'contentAttached' => '✓ المحتوى مرفق',
    'noContent'       => '⚠ لا يوجد محتوى مرفق',
    'courseMeta'      => 'الدورة {0}',
    'viewSyllabus'    => 'عرض منهج المتعلم ←',

    'drip' => [
        'immediate' => 'فوري',
    ],

    'syllabusTitle' => 'منهج الدورة',
    'syllabusSub'   => 'الدروس المقفلة تُظهر وقت فتحها فقط',
    'nonePublished' => 'لم يتم نشر أي دروس بعد.',
    'unlocks'       => '🔒 يُفتح {0}',
    'unlockLater'   => 'لاحقًا',
    'available'     => '✓ متاح',
    'contentLabel'  => 'المحتوى: {0}',

    // Create-course FORM page (GET /courses/create).
    'createForm' => [
        'metaTitle'    => 'إنشاء دورة',
        'heading'      => 'إنشاء دورة',
        'sub'          => 'أنشئ دورة جديدة. تبدأ كمسودة يمكنك نشرها لاحقًا.',
        'titleLabel'   => 'العنوان',
        'titlePh'      => 'مثال: أساسيات الإيمان',
        'categoryLabel'=> 'الفئة (اختياري)',
        'deliveryLabel'=> 'أسلوب التقديم',
        'descLabel'    => 'الوصف (اختياري)',
        'submit'       => 'إنشاء الدورة',
        'required'     => 'مطلوب',
        'delivery' => [
            'self_paced' => 'حسب وتيرتك',
            'cohort'     => 'مجموعة',
            'blended'    => 'مدمج',
        ],
    ],
    // Authoring card (overview.php): publish + add-lesson write controls.
    'authoring' => [
        'heading' => 'التأليف',
        'publishBtn' => 'نشر الدورة',
        'publishHint' => 'اجعل هذه الدورة مرئية للمتعلمين.',
        'publishConfirm' => 'نشر هذه الدورة الآن؟',
        'publishedFlash' => 'تم نشر الدورة.',
        'lessonHeading' => 'إضافة درس',
        'lessonTitleLabel' => 'عنوان الدرس',
        'lessonTitlePh' => 'مثل الأسبوع 1 — ترحيب',
        'lessonPositionLabel' => 'الترتيب',
        'lessonRequiredLabel' => 'درس إلزامي',
        'lessonContentLabel' => 'مرجع المحتوى (اختياري)',
        'lessonContentPh' => 'مثل video:abc123 أو رابط درس',
        'lessonAddBtn' => 'إضافة الدرس',
        'lessonAddedFlash' => 'تمت إضافة الدرس.',
        'publishedNote' => 'هذه الدورة منشورة بالفعل.',
    ],
    'learner' => [
        'enrolPrompt' => 'سجّل لبدء هذه الدورة وتتبع تقدمك.',
        'enrolBtn' => 'سجّل الآن',
        'enrolledFlash' => 'تم تسجيلك.',
        'signInToEnrol' => 'سجّل الدخول للالتحاق بهذه الدورة.',
        'progressLabel' => 'اكتمل {0} من {1} من الدروس المطلوبة ({2}%)',
        'markCompleteBtn' => 'وضع علامة مكتمل',
        'lessonDone' => 'مكتمل',
        'lessonDoneFlash' => 'تم وضع علامة على الدرس كمكتمل.',
        'lessonLocked' => 'يُفتح لاحقًا',
        'courseCompleted' => 'اكتملت الدورة',
    ],

];
