<?php

declare(strict_types=1);

/** Community — Arabic (RTL). Values only; keys mirror en/Community.php. */

return [
    'title'          => 'موجز المجتمع',
    'post'           => 'منشور',
    'posts'          => 'منشورات',
    'visibleToYou'   => 'مرئية لك',
    'empty'          => 'لا توجد منشورات لعرضها بعد.',
    'memberFallback' => 'عضو',
    'pinned'         => '📌 مثبّت',

    'visibility' => [
        'group'   => 'مجموعة',
        'public'  => 'عام',
        'private' => 'خاص',
        'org'     => 'مؤسسة',
    ],
    // Added: write-UI (reason)
    'reason' => [
        'spam' => 'رسائل مزعجة',
        'harassment' => 'تحرّش',
        'inappropriate' => 'غير لائق',
        'misinformation' => 'معلومات مضللة',
        'other' => 'أخرى',
    ],

    // Added: write-UI (action)
    'action' => [
        'hide' => 'إخفاء',
        'lock' => 'قفل',
        'unlock' => 'إلغاء القفل',
        'restore' => 'استعادة',
        'delete' => 'حذف',
        'escalate' => 'تصعيد',
    ],

    // Added: write-UI (admin)
    'admin' => [
        'composeHeading' => 'كتابة منشور',
        'titleLabel' => 'العنوان (اختياري)',
        'titlePh' => 'عنوان قصير',
        'bodyLabel' => 'الرسالة',
        'bodyPh' => 'شارك شيئًا مع مجتمعك…',
        'visibilityLabel' => 'مدى الظهور',
        'postBtn' => 'نشر',
        'reactBtn' => 'إعجاب',
        'commentPh' => 'اكتب تعليقًا…',
        'commentBtn' => 'تعليق',
        'reasonLabel' => 'السبب',
        'reportBtn' => 'إبلاغ',
        'reportConfirm' => 'الإبلاغ عن هذا المنشور للمشرفين؟',
        'actionLabel' => 'الإجراء',
        'moderateReasonPh' => 'السبب (مطلوب)',
        'moderateBtn' => 'تطبيق',
        'moderateConfirm' => 'تطبيق إجراء الإشراف هذا؟',
        'postedFlash' => 'تم نشر المنشور.',
        'commentedFlash' => 'تمت إضافة التعليق.',
        'reactedFlash' => 'تم تحديث التفاعل.',
        'reportedFlash' => 'تم إرسال البلاغ. شكرًا لك.',
        'moderatedFlash' => 'تم تطبيق إجراء الإشراف.',
    ],

];
