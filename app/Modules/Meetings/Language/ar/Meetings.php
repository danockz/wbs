<?php

declare(strict_types=1);

return [
    'metaTitle'  => 'الاجتماعات — الجدول',
    'heading'    => 'جدول الاجتماعات',
    'sub'        => 'الاجتماعات والندوات المجدولة والمباشرة عبر المؤسسة.',
    'count'      => '{0} اجتماعات',
    'countOne'   => 'اجتماع واحد ({0})',
    'empty'      => 'لا توجد اجتماعات مجدولة بعد.',
    'colTitle'   => 'الاجتماع',
    'colWhen'    => 'الوقت',
    'colProvider'=> 'المزوّد',
    'colStatus'  => 'الحالة',
    'colAccess'  => 'الوصول',
    'join'       => 'رابط الانضمام',
    'noJoin'     => 'لا يوجد رابط',
    'noTime'     => 'الوقت غير محدد',
    'status' => [
        'scheduled' => 'مجدول',
        'live'      => 'مباشر',
        'ended'     => 'انتهى',
        'canceled'  => 'أُلغي',
    ],
    'provider' => [
        'zoom'  => 'Zoom',
        'meet'  => 'Google Meet',
        'teams' => 'Microsoft Teams',
        'jitsi' => 'Jitsi',
        'link'  => 'رابط مُستضاف',
    ],
    'mode' => [
        'meeting' => 'اجتماع',
        'webinar' => 'ندوة عبر الإنترنت',
    ],
    'access' => [
        'public'     => 'عام',
        'restricted' => 'مقيّد',
    ],
    // Added: write-UI (role)
    'role' => [
        'host' => 'المضيف',
        'cohost' => 'مضيف مشارك',
        'attendee' => 'مشارك',
    ],

    // Added: write-UI (admin)
    'admin' => [
        'colActions' => 'إجراءات',
        'scheduleHeading' => 'جدولة اجتماع',
        'titleLabel' => 'العنوان',
        'titlePh' => 'مثال: مكالمة قادة الأحد',
        'providerLabel' => 'المزوّد',
        'modeLabel' => 'الوضع',
        'accessLabel' => 'الوصول',
        'startsLabel' => 'يبدأ في',
        'endsLabel' => 'ينتهي في',
        'joinUrlLabel' => 'رابط انضمام مُستضاف (اختياري)',
        'joinUrlHint' => 'يجب أن يبدأ بـ https://. اتركه فارغًا للسماح لمزوّد متصل بإنشاء الاجتماع.',
        'scheduleBtn' => 'جدولة الاجتماع',
        'statusLabel' => 'الحالة',
        'setStatusBtn' => 'تحديث',
        'userIdLabel' => 'المستخدم',
        'userIdPh' => 'معرّف المستخدم',
        'userIdNone' => '— اختر شخصًا —',
        'roleLabel' => 'الدور',
        'grantBtn' => 'منح الوصول',
        'createdFlash' => 'تمت جدولة الاجتماع.',
        'transitionedFlash' => 'تم تحديث حالة الاجتماع.',
        'grantedFlash' => 'تم منح صلاحية الانضمام.',
    ],

];
