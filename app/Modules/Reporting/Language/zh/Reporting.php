<?php

declare(strict_types=1);

/** Reporting — Chinese (Simplified). Values only; keys mirror en/Reporting.php. */

return [
    'funnel' => [
        'title'   => '赢得 · 培育 · 差遣 漏斗',
        'sub'     => '按角色/范围的仪表板 — 计数区分唯一人数与行为次数。',

        'winHeading'          => '赢得 — 获取与转化',
        'prospects'           => '潜在对象',
        'membersUnique'       => '成员（唯一）',
        'referralConversions' => '推荐转化',

        'buildHeading'      => '培育 — 培训、出席、奉献',
        'courseEnrollments' => '课程报名',
        'courseCompletions' => '课程完成',
        'eventsHeld'        => '已举办活动',
        'uniqueAttendees'   => '唯一出席者',
        'verifiedGiving'    => '已核实奉献（最小单位）',

        'sendHeading'   => '差遣 — 领导力与游戏化',
        'pointsAwarded' => '已授予积分',

        'asOf'          => '截至：{0}',
        'source'        => '来源：{0}',
        'completeness'  => '完整度：{0}',
        'suppression'   => '抑制阈值：{0}',
        'na'            => '无',
    ],

    'member' => [
        'welcome'        => '欢迎，{0}',
        'memberFallback' => '成员',
        'subtitle'       => '您的个人仪表板',
        'memberSince'    => '成员始于 {0}',

        'standing'        => '状态',
        'pointsSeason'    => '积分（本赛季）',
        'seasonLabel'     => '第 {0} 赛季',
        'rank'            => '等级',
        'unranked'        => '未评级',
        'ptsToRank'       => '还需 {0} 分升至 {1}',
        'topRank'         => '最高等级',
        'badges'          => '徽章',
        'achievements'    => '成就',
        'ptsToGo'         => '还差 {0} 分',
        'pctToNext'       => '距下一等级 {0}%',

        'milestones'    => '我的里程碑',
        'reached'       => '已达成',
        'nextUp'        => '即将达成',
        'toGo'          => '还差 {0}',
        'ms' => [
            'tenure'             => '入会 {0} 年',
            'events_one'         => '已参加 {0} 场活动',
            'events_other'       => '已参加 {0} 场活动',
            'courses_one'        => '已完成 {0} 门课程',
            'courses_other'      => '已完成 {0} 门课程',
            'certificates_one'   => '已获得 {0} 张证书',
            'certificates_other' => '已获得 {0} 张证书',
            'giving_one'         => '{0} 笔已核实奉献',
            'giving_other'       => '{0} 笔已核实奉献',
        ],

        'badgesAch'     => '徽章与成就',
        'badgesEarned'  => '已获徽章',
        'awardedTitle'  => '授予于 {0}',
        'xp'            => '{0} 经验',
        'almostThere'   => '就快达成',
        'unlockedOn'    => '解锁于 {0}',

        'streaks'       => '连续记录',
        'best'          => '最佳：{0}',

        'upcomingEvents' => '即将举行的活动',
        'noEvents'       => '暂无即将举行的活动。',
        'browseEvents'   => '浏览活动 →',

        'certificates'      => '我的证书',
        'noCerts'           => '暂无证书 — 参加活动即可获得。',
        'certFallback'      => '证书',
        'issued'            => '颁发于 {0}',
        'verifyId'          => '验证 ID：',
        'downloadPdf'       => '下载 PDF',

        'learning'      => '我的学习',
        'activeCourses' => '进行中的课程',
        'completed'     => '已完成',
        'courseFallback' => '课程',
        'completedOn'   => '完成于 {0}',
        'enrolledOn'    => '报名于 {0}',

        'groups'        => '我的小组',
        'noGroups'      => '您还不是任何小组的成员。',
        'joined'        => '加入于 {0}',

        'metaSelfScoped' => '截至 {0} · 个人视图',
    ],

    // Export history page (GET /reports/export).
    'exports' => [
        'metaTitle'   => '导出历史',
        'heading'     => '导出历史',
        'sub'         => '已请求的报表导出及其状态。',
        'count'       => '{0} 个导出',
        'countOne'    => '{0} 个导出',
        'empty'       => '尚未请求任何导出。',
        'colReport'   => '报表',
        'colFormat'   => '格式',
        'colStatus'   => '状态',
        'colRows'     => '行数',
        'colRequested'=> '请求时间',
        'colExpires'  => '过期时间',
        'noRows'      => '—',
        'noExpiry'    => '—',
        'download'    => '下载',
        'requestHeading' => '请求导出',
        'reportLabel' => '报告',
        'formatLabel' => '格式',
        'requestBtn' => '请求导出',
        'requestedFlash' => '已请求导出。准备就绪后将显示在下方。',
        'report' => [
            'wbs_funnel' => '赢得–建立–差遣 漏斗',
        ],
        'status' => [
            'queued'  => '排队中',
            'running' => '运行中',
            'ready'   => '就绪',
            'failed'  => '失败',
            'expired' => '已过期',
        ],
    ],
];
