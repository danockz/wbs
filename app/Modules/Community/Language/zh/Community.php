<?php

declare(strict_types=1);

/** Community — Chinese (Simplified). Values only; keys mirror en/Community.php. */

return [
    'title'          => '社区动态',
    'post'           => '条动态',
    'posts'          => '条动态',
    'visibleToYou'   => '对您可见',
    'empty'          => '暂无可显示的动态。',
    'memberFallback' => '成员',
    'pinned'         => '📌 已置顶',

    'visibility' => [
        'group'   => '小组',
        'public'  => '公开',
        'private' => '私密',
        'org'     => '组织',
    ],
    // Added: write-UI (reason)
    'reason' => [
        'spam' => '垃圾信息',
        'harassment' => '骚扰',
        'inappropriate' => '不当内容',
        'misinformation' => '虚假信息',
        'other' => '其他',
    ],

    // Added: write-UI (action)
    'action' => [
        'hide' => '隐藏',
        'lock' => '锁定',
        'unlock' => '解锁',
        'restore' => '恢复',
        'delete' => '删除',
        'escalate' => '升级',
    ],

    // Added: write-UI (admin)
    'admin' => [
        'composeHeading' => '撰写帖子',
        'titleLabel' => '标题（可选）',
        'titlePh' => '简短标题',
        'bodyLabel' => '内容',
        'bodyPh' => '与您的社区分享…',
        'visibilityLabel' => '可见性',
        'postBtn' => '发布',
        'reactBtn' => '点赞',
        'commentPh' => '写评论…',
        'commentBtn' => '评论',
        'reasonLabel' => '原因',
        'reportBtn' => '举报',
        'reportConfirm' => '向版主举报此帖子？',
        'actionLabel' => '操作',
        'moderateReasonPh' => '原因（必填）',
        'moderateBtn' => '应用',
        'moderateConfirm' => '应用此审核操作？',
        'postedFlash' => '帖子已发布。',
        'commentedFlash' => '评论已添加。',
        'reactedFlash' => '反应已更新。',
        'reportedFlash' => '举报已提交，谢谢。',
        'moderatedFlash' => '审核操作已应用。',
    ],

];
