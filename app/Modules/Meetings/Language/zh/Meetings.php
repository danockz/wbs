<?php

declare(strict_types=1);

return [
    'metaTitle'  => '会议 — 日程',
    'heading'    => '会议日程',
    'sub'        => '组织内已安排和正在进行的会议及网络研讨会。',
    'count'      => '{0} 场会议',
    'countOne'   => '{0} 场会议',
    'empty'      => '暂无已安排的会议。',
    'colTitle'   => '会议',
    'colWhen'    => '时间',
    'colProvider'=> '提供商',
    'colStatus'  => '状态',
    'colAccess'  => '访问权限',
    'join'       => '加入链接',
    'noJoin'     => '无链接',
    'noTime'     => '时间未设定',
    'status' => [
        'scheduled' => '已安排',
        'live'      => '进行中',
        'ended'     => '已结束',
        'canceled'  => '已取消',
    ],
    'provider' => [
        'zoom'  => 'Zoom',
        'meet'  => 'Google Meet',
        'teams' => 'Microsoft Teams',
        'jitsi' => 'Jitsi',
        'link'  => '托管链接',
    ],
    'mode' => [
        'meeting' => '会议',
        'webinar' => '网络研讨会',
    ],
    'access' => [
        'public'     => '公开',
        'restricted' => '受限',
    ],
    // Added: write-UI (role)
    'role' => [
        'host' => '主持人',
        'cohost' => '联席主持人',
        'attendee' => '与会者',
    ],

    // Added: write-UI (admin)
    'admin' => [
        'colActions' => '操作',
        'scheduleHeading' => '安排会议',
        'titleLabel' => '标题',
        'titlePh' => '例如：周日领袖通话',
        'providerLabel' => '提供商',
        'modeLabel' => '模式',
        'accessLabel' => '访问',
        'startsLabel' => '开始时间',
        'endsLabel' => '结束时间',
        'joinUrlLabel' => '托管加入链接（可选）',
        'joinUrlHint' => '必须以 https:// 开头。留空则由已连接的提供商创建会议。',
        'scheduleBtn' => '安排会议',
        'statusLabel' => '状态',
        'setStatusBtn' => '更新',
        'userIdLabel' => '用户',
        'userIdPh' => '用户 ID',
        'userIdNone' => '— 选择一个人 —',
        'roleLabel' => '角色',
        'grantBtn' => '授予访问权限',
        'createdFlash' => '会议已安排。',
        'transitionedFlash' => '会议状态已更新。',
        'grantedFlash' => '已授予加入权限。',
    ],

];
