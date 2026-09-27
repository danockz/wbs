<?php

declare(strict_types=1);

/** Reporting — Portuguese. Values only; keys mirror en/Reporting.php. */

return [
    'funnel' => [
        'title'   => 'Funil Ganhar · Edificar · Enviar',
        'sub'     => 'Painel por função/escopo — as contagens distinguem pessoas únicas de ações.',

        'winHeading'          => 'Ganhar — captação e conversão',
        'prospects'           => 'Prospectos',
        'membersUnique'       => 'Membros (únicos)',
        'referralConversions' => 'Conversões por indicação',

        'buildHeading'      => 'Edificar — formação, presença, doações',
        'courseEnrollments' => 'Inscrições em cursos',
        'courseCompletions' => 'Cursos concluídos',
        'eventsHeld'        => 'Eventos realizados',
        'uniqueAttendees'   => 'Participantes únicos',
        'verifiedGiving'    => 'Doações verificadas (unidade menor)',

        'sendHeading'   => 'Enviar — liderança e gamificação',
        'pointsAwarded' => 'Pontos atribuídos',

        'asOf'          => 'Em: {0}',
        'source'        => 'Fonte: {0}',
        'completeness'  => 'Completude: {0}',
        'suppression'   => 'Limite de supressão: {0}',
        'na'            => 'n/d',
    ],

    'member' => [
        'welcome'        => 'Bem-vindo, {0}',
        'memberFallback' => 'Membro',
        'subtitle'       => 'Seu painel pessoal',
        'memberSince'    => 'membro desde {0}',

        'standing'        => 'Situação',
        'pointsSeason'    => 'Pontos (esta temporada)',
        'seasonLabel'     => 'Temporada {0}',
        'rank'            => 'Classificação',
        'unranked'        => 'Sem classificação',
        'ptsToRank'       => '{0} pts para {1}',
        'topRank'         => 'Classificação máxima',
        'badges'          => 'Emblemas',
        'achievements'    => 'Conquistas',
        'ptsToGo'         => 'faltam {0} pts',
        'pctToNext'       => '{0}% para a próxima classificação',

        'milestones'    => 'Meus marcos',
        'reached'       => 'Alcançados',
        'nextUp'        => 'A seguir',
        'toGo'          => 'faltam {0}',
        'ms' => [
            'tenure'             => 'membro há {0} ano(s)',
            'events_one'         => '{0} evento participado',
            'events_other'       => '{0} eventos participados',
            'courses_one'        => '{0} curso concluído',
            'courses_other'      => '{0} cursos concluídos',
            'certificates_one'   => '{0} certificado obtido',
            'certificates_other' => '{0} certificados obtidos',
            'giving_one'         => '{0} doação verificada',
            'giving_other'       => '{0} doações verificadas',
        ],

        'badgesAch'     => 'Emblemas e conquistas',
        'badgesEarned'  => 'Emblemas obtidos',
        'awardedTitle'  => 'Concedido {0}',
        'xp'            => '{0} XP',
        'almostThere'   => 'Quase lá',
        'unlockedOn'    => 'Desbloqueado {0}',

        'streaks'       => 'Sequências',
        'best'          => 'Melhor: {0}',

        'upcomingEvents' => 'Próximos eventos',
        'noEvents'       => 'Nenhum evento próximo.',
        'browseEvents'   => 'Explorar eventos →',

        'certificates'      => 'Meus certificados',
        'noCerts'           => 'Ainda sem certificados — participe de um evento para conquistar um.',
        'certFallback'      => 'Certificado',
        'issued'            => 'Emitido {0}',
        'verifyId'          => 'ID de verificação:',
        'downloadPdf'       => 'Baixar PDF',

        'learning'      => 'Meus estudos',
        'activeCourses' => 'Cursos ativos',
        'completed'     => 'Concluídos',
        'courseFallback' => 'Curso',
        'completedOn'   => 'Concluído {0}',
        'enrolledOn'    => 'Inscrito {0}',

        'groups'        => 'Meus grupos',
        'noGroups'      => 'Você ainda não é membro de nenhum grupo.',
        'joined'        => 'Ingressou {0}',

        'metaSelfScoped' => 'Em {0} · visão pessoal',
    ],

    // Export history page (GET /reports/export).
    'exports' => [
        'metaTitle'   => 'Histórico de exportações',
        'heading'     => 'Histórico de exportações',
        'sub'         => 'Exportações de relatórios solicitadas e seu status.',
        'count'       => '{0} exportações',
        'countOne'    => '{0} exportação',
        'empty'       => 'Nenhuma exportação solicitada ainda.',
        'colReport'   => 'Relatório',
        'colFormat'   => 'Formato',
        'colStatus'   => 'Status',
        'colRows'     => 'Linhas',
        'colRequested'=> 'Solicitado',
        'colExpires'  => 'Expira',
        'noRows'      => '—',
        'noExpiry'    => '—',
        'download'    => 'Baixar',
        'requestHeading' => 'Solicitar uma exportação',
        'reportLabel' => 'Relatório',
        'formatLabel' => 'Formato',
        'requestBtn' => 'Solicitar exportação',
        'requestedFlash' => 'Exportação solicitada. Aparecerá abaixo quando estiver pronta.',
        'report' => [
            'wbs_funnel' => 'Funil Ganhar–Edificar–Enviar',
        ],
        'status' => [
            'queued'  => 'Na fila',
            'running' => 'Em execução',
            'ready'   => 'Pronto',
            'failed'  => 'Falhou',
            'expired' => 'Expirado',
        ],
    ],
];
