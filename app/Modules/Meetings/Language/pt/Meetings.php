<?php

declare(strict_types=1);

return [
    'metaTitle'  => 'Reuniões — Agenda',
    'heading'    => 'Agenda de reuniões',
    'sub'        => 'Reuniões e webinars agendados e ao vivo na organização.',
    'count'      => '{0} reuniões',
    'countOne'   => '{0} reunião',
    'empty'      => 'Nenhuma reunião agendada ainda.',
    'colTitle'   => 'Reunião',
    'colWhen'    => 'Quando',
    'colProvider'=> 'Provedor',
    'colStatus'  => 'Status',
    'colAccess'  => 'Acesso',
    'join'       => 'Link de acesso',
    'noJoin'     => 'Sem link',
    'noTime'     => 'Horário não definido',
    'status' => [
        'scheduled' => 'Agendada',
        'live'      => 'Ao vivo',
        'ended'     => 'Encerrada',
        'canceled'  => 'Cancelada',
    ],
    'provider' => [
        'zoom'  => 'Zoom',
        'meet'  => 'Google Meet',
        'teams' => 'Microsoft Teams',
        'jitsi' => 'Jitsi',
        'link'  => 'Link hospedado',
    ],
    'mode' => [
        'meeting' => 'Reunião',
        'webinar' => 'Webinar',
    ],
    'access' => [
        'public'     => 'Público',
        'restricted' => 'Restrito',
    ],
    // Added: write-UI (role)
    'role' => [
        'host' => 'Anfitrião',
        'cohost' => 'Coanfitrião',
        'attendee' => 'Participante',
    ],

    // Added: write-UI (admin)
    'admin' => [
        'colActions' => 'Ações',
        'scheduleHeading' => 'Agendar uma reunião',
        'titleLabel' => 'Título',
        'titlePh' => 'ex. Chamada de líderes de domingo',
        'providerLabel' => 'Fornecedor',
        'modeLabel' => 'Modo',
        'accessLabel' => 'Acesso',
        'startsLabel' => 'Início',
        'endsLabel' => 'Fim',
        'joinUrlLabel' => 'Link de acesso hospedado (opcional)',
        'joinUrlHint' => 'Deve começar com https://. Deixe em branco para permitir que um fornecedor conectado crie a reunião.',
        'scheduleBtn' => 'Agendar reunião',
        'statusLabel' => 'Estado',
        'setStatusBtn' => 'Atualizar',
        'userIdLabel' => 'Utilizador',
        'userIdPh' => 'ID do utilizador',
        'userIdNone' => '— Selecionar uma pessoa —',
        'roleLabel' => 'Função',
        'grantBtn' => 'Conceder acesso',
        'createdFlash' => 'Reunião agendada.',
        'transitionedFlash' => 'Estado da reunião atualizado.',
        'grantedFlash' => 'Acesso de entrada concedido.',
    ],

];
