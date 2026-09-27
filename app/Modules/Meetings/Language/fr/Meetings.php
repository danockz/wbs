<?php

declare(strict_types=1);

return [
    'metaTitle'  => 'Réunions — Calendrier',
    'heading'    => 'Calendrier des réunions',
    'sub'        => 'Réunions et webinaires programmés et en direct dans l’organisation.',
    'count'      => '{0} réunions',
    'countOne'   => '{0} réunion',
    'empty'      => 'Aucune réunion programmée pour le moment.',
    'colTitle'   => 'Réunion',
    'colWhen'    => 'Quand',
    'colProvider'=> 'Fournisseur',
    'colStatus'  => 'Statut',
    'colAccess'  => 'Accès',
    'join'       => 'Lien de connexion',
    'noJoin'     => 'Aucun lien',
    'noTime'     => 'Heure non définie',
    'status' => [
        'scheduled' => 'Programmée',
        'live'      => 'En direct',
        'ended'     => 'Terminée',
        'canceled'  => 'Annulée',
    ],
    'provider' => [
        'zoom'  => 'Zoom',
        'meet'  => 'Google Meet',
        'teams' => 'Microsoft Teams',
        'jitsi' => 'Jitsi',
        'link'  => 'Lien hébergé',
    ],
    'mode' => [
        'meeting' => 'Réunion',
        'webinar' => 'Webinaire',
    ],
    'access' => [
        'public'     => 'Public',
        'restricted' => 'Restreint',
    ],
    // Added: write-UI (role)
    'role' => [
        'host' => 'Hôte',
        'cohost' => 'Co-hôte',
        'attendee' => 'Participant',
    ],

    // Added: write-UI (admin)
    'admin' => [
        'colActions' => 'Actions',
        'scheduleHeading' => 'Planifier une réunion',
        'titleLabel' => 'Titre',
        'titlePh' => 'ex. Appel des responsables du dimanche',
        'providerLabel' => 'Fournisseur',
        'modeLabel' => 'Mode',
        'accessLabel' => 'Accès',
        'startsLabel' => 'Début',
        'endsLabel' => 'Fin',
        'joinUrlLabel' => 'Lien de connexion hébergé (facultatif)',
        'joinUrlHint' => 'Doit commencer par https://. Laissez vide pour laisser un fournisseur connecté créer la réunion.',
        'scheduleBtn' => 'Planifier la réunion',
        'statusLabel' => 'Statut',
        'setStatusBtn' => 'Mettre à jour',
        'userIdLabel' => 'Utilisateur',
        'userIdPh' => 'ID utilisateur',
        'userIdNone' => '— Choisir une personne —',
        'roleLabel' => 'Rôle',
        'grantBtn' => 'Accorder l’accès',
        'createdFlash' => 'Réunion planifiée.',
        'transitionedFlash' => 'Statut de la réunion mis à jour.',
        'grantedFlash' => 'Accès de connexion accordé.',
    ],

];
