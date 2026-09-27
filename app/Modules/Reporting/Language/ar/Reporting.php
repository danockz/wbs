<?php

declare(strict_types=1);

/** Reporting — Arabic (RTL). Values only; keys mirror en/Reporting.php. */

return [
    'funnel' => [
        'title'   => 'مسار الكسب · البناء · الإرسال',
        'sub'     => 'لوحة حسب الدور/النطاق — تميّز الأعداد بين الأشخاص الفريدين والإجراءات.',

        'winHeading'          => 'الكسب — الاستقطاب والتحويل',
        'prospects'           => 'المهتمون',
        'membersUnique'       => 'الأعضاء (فريدون)',
        'referralConversions' => 'تحويلات الإحالة',

        'buildHeading'      => 'البناء — التدريب والحضور والعطاء',
        'courseEnrollments' => 'التسجيلات في الدورات',
        'courseCompletions' => 'الدورات المكتملة',
        'eventsHeld'        => 'الفعاليات المنعقدة',
        'uniqueAttendees'   => 'الحاضرون الفريدون',
        'verifiedGiving'    => 'العطاء المُتحقَّق منه (وحدة صغرى)',

        'sendHeading'   => 'الإرسال — القيادة والتحفيز',
        'pointsAwarded' => 'النقاط الممنوحة',

        'asOf'          => 'حتى تاريخ: {0}',
        'source'        => 'المصدر: {0}',
        'completeness'  => 'الاكتمال: {0}',
        'suppression'   => 'حد الإخفاء: {0}',
        'na'            => 'غير متاح',
    ],

    'member' => [
        'welcome'        => 'مرحبًا، {0}',
        'memberFallback' => 'عضو',
        'subtitle'       => 'لوحتك الشخصية',
        'memberSince'    => 'عضو منذ {0}',

        'standing'        => 'الحالة',
        'pointsSeason'    => 'النقاط (هذا الموسم)',
        'seasonLabel'     => 'الموسم {0}',
        'rank'            => 'الرتبة',
        'unranked'        => 'بلا رتبة',
        'ptsToRank'       => '{0} نقطة للوصول إلى {1}',
        'topRank'         => 'أعلى رتبة',
        'badges'          => 'الشارات',
        'achievements'    => 'الإنجازات',
        'ptsToGo'         => 'يتبقى {0} نقطة',
        'pctToNext'       => '{0}% للرتبة التالية',

        'milestones'    => 'محطاتي',
        'reached'       => 'تم بلوغها',
        'nextUp'        => 'التالي',
        'toGo'          => 'يتبقى {0}',
        'ms' => [
            'tenure'             => 'عضو منذ {0} سنة',
            'events_one'         => 'حضر {0} فعالية',
            'events_other'       => 'حضر {0} فعاليات',
            'courses_one'        => 'أكمل {0} دورة',
            'courses_other'      => 'أكمل {0} دورات',
            'certificates_one'   => 'حصل على {0} شهادة',
            'certificates_other' => 'حصل على {0} شهادات',
            'giving_one'         => '{0} هدية مُوثّقة',
            'giving_other'       => '{0} هدايا مُوثّقة',
        ],

        'badgesAch'     => 'الشارات والإنجازات',
        'badgesEarned'  => 'الشارات المكتسبة',
        'awardedTitle'  => 'مُنحت {0}',
        'xp'            => '{0} نقطة خبرة',
        'almostThere'   => 'أوشكت على الوصول',
        'unlockedOn'    => 'فُتحت {0}',

        'streaks'       => 'السلاسل',
        'best'          => 'الأفضل: {0}',

        'upcomingEvents' => 'الفعاليات القادمة',
        'noEvents'       => 'لا توجد فعاليات قادمة.',
        'browseEvents'   => 'تصفّح الفعاليات →',

        'certificates'      => 'شهاداتي',
        'noCerts'           => 'لا توجد شهادات بعد — احضر فعالية لتكسب واحدة.',
        'certFallback'      => 'شهادة',
        'issued'            => 'صدرت {0}',
        'verifyId'          => 'معرّف التحقق:',
        'downloadPdf'       => 'تنزيل PDF',

        'learning'      => 'تعلّمي',
        'activeCourses' => 'الدورات النشطة',
        'completed'     => 'المكتملة',
        'courseFallback' => 'دورة',
        'completedOn'   => 'اكتملت {0}',
        'enrolledOn'    => 'سُجّلت {0}',

        'groups'        => 'مجموعاتي',
        'noGroups'      => 'لست عضوًا في أي مجموعة بعد.',
        'joined'        => 'انضممت {0}',

        'metaSelfScoped' => 'حتى {0} · عرض شخصي',
    ],

    // Export history page (GET /reports/export).
    'exports' => [
        'metaTitle'   => 'سجل التصدير',
        'heading'     => 'سجل التصدير',
        'sub'         => 'عمليات تصدير التقارير المطلوبة وحالتها.',
        'count'       => '{0} عمليات تصدير',
        'countOne'    => 'عملية تصدير واحدة ({0})',
        'empty'       => 'لا توجد عمليات تصدير مطلوبة بعد.',
        'colReport'   => 'التقرير',
        'colFormat'   => 'الصيغة',
        'colStatus'   => 'الحالة',
        'colRows'     => 'الصفوف',
        'colRequested'=> 'طُلب',
        'colExpires'  => 'ينتهي',
        'noRows'      => '—',
        'noExpiry'    => '—',
        'download'    => 'تنزيل',
        'requestHeading' => 'طلب تصدير',
        'reportLabel' => 'التقرير',
        'formatLabel' => 'الصيغة',
        'requestBtn' => 'طلب التصدير',
        'requestedFlash' => 'تم طلب التصدير. سيظهر أدناه عند جاهزيته.',
        'report' => [
            'wbs_funnel' => 'مسار الكسب–البناء–الإرسال',
        ],
        'status' => [
            'queued'  => 'في الطابور',
            'running' => 'قيد التشغيل',
            'ready'   => 'جاهز',
            'failed'  => 'فشل',
            'expired' => 'منتهٍ',
        ],
    ],
];
