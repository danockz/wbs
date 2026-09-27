<?php

declare(strict_types=1);

/** Reporting — Spanish. Values only; keys mirror en/Reporting.php. */

return [
    'funnel' => [
        'title'   => 'Embudo Ganar · Edificar · Enviar',
        'sub'     => 'Panel por rol/alcance — los conteos distinguen personas únicas de acciones.',

        'winHeading'          => 'Ganar — captación y conversión',
        'prospects'           => 'Prospectos',
        'membersUnique'       => 'Miembros (únicos)',
        'referralConversions' => 'Conversiones por referido',

        'buildHeading'      => 'Edificar — formación, asistencia, donaciones',
        'courseEnrollments' => 'Inscripciones a cursos',
        'courseCompletions' => 'Cursos completados',
        'eventsHeld'        => 'Eventos realizados',
        'uniqueAttendees'   => 'Asistentes únicos',
        'verifiedGiving'    => 'Donaciones verificadas (unidad menor)',

        'sendHeading'   => 'Enviar — liderazgo y gamificación',
        'pointsAwarded' => 'Puntos otorgados',

        'asOf'          => 'A fecha de: {0}',
        'source'        => 'Fuente: {0}',
        'completeness'  => 'Integridad: {0}',
        'suppression'   => 'Umbral de supresión: {0}',
        'na'            => 'n/d',
    ],

    'member' => [
        'welcome'        => 'Bienvenido, {0}',
        'memberFallback' => 'Miembro',
        'subtitle'       => 'Tu panel personal',
        'memberSince'    => 'miembro desde {0}',

        'standing'        => 'Situación',
        'pointsSeason'    => 'Puntos (esta temporada)',
        'seasonLabel'     => 'Temporada {0}',
        'rank'            => 'Rango',
        'unranked'        => 'Sin rango',
        'ptsToRank'       => '{0} pts para {1}',
        'topRank'         => 'Rango máximo',
        'badges'          => 'Insignias',
        'achievements'    => 'Logros',
        'ptsToGo'         => 'faltan {0} pts',
        'pctToNext'       => '{0}% hacia el siguiente rango',

        'milestones'    => 'Mis hitos',
        'reached'       => 'Alcanzados',
        'nextUp'        => 'Próximos',
        'toGo'          => 'faltan {0}',
        'ms' => [
            'tenure'             => 'miembro desde hace {0} año(s)',
            'events_one'         => '{0} evento asistido',
            'events_other'       => '{0} eventos asistidos',
            'courses_one'        => '{0} curso completado',
            'courses_other'      => '{0} cursos completados',
            'certificates_one'   => '{0} certificado obtenido',
            'certificates_other' => '{0} certificados obtenidos',
            'giving_one'         => '{0} donación verificada',
            'giving_other'       => '{0} donaciones verificadas',
        ],

        'badgesAch'     => 'Insignias y logros',
        'badgesEarned'  => 'Insignias obtenidas',
        'awardedTitle'  => 'Otorgada {0}',
        'xp'            => '{0} XP',
        'almostThere'   => 'Casi lo logras',
        'unlockedOn'    => 'Desbloqueado {0}',

        'streaks'       => 'Rachas',
        'best'          => 'Mejor: {0}',

        'upcomingEvents' => 'Próximos eventos',
        'noEvents'       => 'No hay eventos próximos.',
        'browseEvents'   => 'Explorar eventos →',

        'certificates'      => 'Mis certificados',
        'noCerts'           => 'Aún no hay certificados — asiste a un evento para obtener uno.',
        'certFallback'      => 'Certificado',
        'issued'            => 'Emitido {0}',
        'verifyId'          => 'ID de verificación:',
        'downloadPdf'       => 'Descargar PDF',

        'learning'      => 'Mi aprendizaje',
        'activeCourses' => 'Cursos activos',
        'completed'     => 'Completados',
        'courseFallback' => 'Curso',
        'completedOn'   => 'Completado {0}',
        'enrolledOn'    => 'Inscrito {0}',

        'groups'        => 'Mis grupos',
        'noGroups'      => 'Aún no eres miembro de ningún grupo.',
        'joined'        => 'Te uniste {0}',

        'metaSelfScoped' => 'A fecha de {0} · vista personal',
    ],

    // Export history page (GET /reports/export).
    'exports' => [
        'metaTitle'   => 'Historial de exportaciones',
        'heading'     => 'Historial de exportaciones',
        'sub'         => 'Exportaciones de informes solicitadas y su estado.',
        'count'       => '{0} exportaciones',
        'countOne'    => '{0} exportación',
        'empty'       => 'Aún no se han solicitado exportaciones.',
        'colReport'   => 'Informe',
        'colFormat'   => 'Formato',
        'colStatus'   => 'Estado',
        'colRows'     => 'Filas',
        'colRequested'=> 'Solicitado',
        'colExpires'  => 'Expira',
        'noRows'      => '—',
        'noExpiry'    => '—',
        'download'    => 'Descargar',
        'requestHeading' => 'Solicitar una exportación',
        'reportLabel' => 'Informe',
        'formatLabel' => 'Formato',
        'requestBtn' => 'Solicitar exportación',
        'requestedFlash' => 'Exportación solicitada. Aparecerá abajo cuando esté lista.',
        'report' => [
            'wbs_funnel' => 'Embudo Ganar–Edificar–Enviar',
        ],
        'status' => [
            'queued'  => 'En cola',
            'running' => 'En ejecución',
            'ready'   => 'Listo',
            'failed'  => 'Fallido',
            'expired' => 'Expirado',
        ],
    ],
];
