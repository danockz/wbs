<?php

declare(strict_types=1);

/** Reporting — French. Values only; keys mirror en/Reporting.php. */

return [
    'funnel' => [
        'title'   => 'Entonnoir Gagner · Bâtir · Envoyer',
        'sub'     => 'Tableau de bord par rôle/périmètre — les comptes distinguent les personnes uniques des actions.',

        'winHeading'          => 'Gagner — acquisition et conversion',
        'prospects'           => 'Prospects',
        'membersUnique'       => 'Membres (uniques)',
        'referralConversions' => 'Conversions par parrainage',

        'buildHeading'      => 'Bâtir — formation, présence, dons',
        'courseEnrollments' => 'Inscriptions aux cours',
        'courseCompletions' => 'Cours terminés',
        'eventsHeld'        => 'Événements tenus',
        'uniqueAttendees'   => 'Participants uniques',
        'verifiedGiving'    => 'Dons vérifiés (unité mineure)',

        'sendHeading'   => 'Envoyer — leadership et ludification',
        'pointsAwarded' => 'Points attribués',

        'asOf'          => 'À la date du : {0}',
        'source'        => 'Source : {0}',
        'completeness'  => 'Exhaustivité : {0}',
        'suppression'   => 'Seuil de suppression : {0}',
        'na'            => 'n/d',
    ],

    'member' => [
        'welcome'        => 'Bienvenue, {0}',
        'memberFallback' => 'Membre',
        'subtitle'       => 'Votre tableau de bord personnel',
        'memberSince'    => 'membre depuis {0}',

        'standing'        => 'Situation',
        'pointsSeason'    => 'Points (cette saison)',
        'seasonLabel'     => 'Saison {0}',
        'rank'            => 'Rang',
        'unranked'        => 'Sans rang',
        'ptsToRank'       => '{0} pts pour {1}',
        'topRank'         => 'Rang maximal',
        'badges'          => 'Badges',
        'achievements'    => 'Réalisations',
        'ptsToGo'         => 'encore {0} pts',
        'pctToNext'       => '{0} % vers le rang suivant',

        'milestones'    => 'Mes jalons',
        'reached'       => 'Atteints',
        'nextUp'        => 'À suivre',
        'toGo'          => 'encore {0}',
        'ms' => [
            'tenure'             => 'membre depuis {0} an(s)',
            'events_one'         => '{0} événement suivi',
            'events_other'       => '{0} événements suivis',
            'courses_one'        => '{0} cours terminé',
            'courses_other'      => '{0} cours terminés',
            'certificates_one'   => '{0} certificat obtenu',
            'certificates_other' => '{0} certificats obtenus',
            'giving_one'         => '{0} don vérifié',
            'giving_other'       => '{0} dons vérifiés',
        ],

        'badgesAch'     => 'Badges et réalisations',
        'badgesEarned'  => 'Badges obtenus',
        'awardedTitle'  => 'Attribué {0}',
        'xp'            => '{0} XP',
        'almostThere'   => 'Presque là',
        'unlockedOn'    => 'Débloqué {0}',

        'streaks'       => 'Séries',
        'best'          => 'Meilleure : {0}',

        'upcomingEvents' => 'Événements à venir',
        'noEvents'       => 'Aucun événement à venir.',
        'browseEvents'   => 'Parcourir les événements →',

        'certificates'      => 'Mes certificats',
        'noCerts'           => 'Aucun certificat pour le moment — participez à un événement pour en obtenir un.',
        'certFallback'      => 'Certificat',
        'issued'            => 'Émis {0}',
        'verifyId'          => 'ID de vérification :',
        'downloadPdf'       => 'Télécharger le PDF',

        'learning'      => 'Mes apprentissages',
        'activeCourses' => 'Cours actifs',
        'completed'     => 'Terminés',
        'courseFallback' => 'Cours',
        'completedOn'   => 'Terminé {0}',
        'enrolledOn'    => 'Inscrit {0}',

        'groups'        => 'Mes groupes',
        'noGroups'      => 'Vous n’êtes membre d’aucun groupe pour le moment.',
        'joined'        => 'Rejoint {0}',

        'metaSelfScoped' => 'À la date du {0} · vue personnelle',
    ],

    // Export history page (GET /reports/export).
    'exports' => [
        'metaTitle'   => 'Historique des exports',
        'heading'     => 'Historique des exports',
        'sub'         => 'Exports de rapports demandés et leur statut.',
        'count'       => '{0} exports',
        'countOne'    => '{0} export',
        'empty'       => 'Aucun export demandé pour le moment.',
        'colReport'   => 'Rapport',
        'colFormat'   => 'Format',
        'colStatus'   => 'Statut',
        'colRows'     => 'Lignes',
        'colRequested'=> 'Demandé',
        'colExpires'  => 'Expire',
        'noRows'      => '—',
        'noExpiry'    => '—',
        'download'    => 'Télécharger',
        'requestHeading' => 'Demander un export',
        'reportLabel' => 'Rapport',
        'formatLabel' => 'Format',
        'requestBtn' => 'Demander l’export',
        'requestedFlash' => 'Export demandé. Il apparaîtra ci-dessous une fois prêt.',
        'report' => [
            'wbs_funnel' => 'Entonnoir Gagner–Bâtir–Envoyer',
        ],
        'status' => [
            'queued'  => 'En file',
            'running' => 'En cours',
            'ready'   => 'Prêt',
            'failed'  => 'Échec',
            'expired' => 'Expiré',
        ],
    ],
];
