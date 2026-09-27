<?php

declare(strict_types=1);

/** Community — French. Values only; keys mirror en/Community.php. */

return [
    'title'          => 'Fil de la communauté',
    'post'           => 'publication',
    'posts'          => 'publications',
    'visibleToYou'   => 'visibles pour vous',
    'empty'          => 'Aucune publication à afficher pour le moment.',
    'memberFallback' => 'Membre',
    'pinned'         => '📌 épinglé',

    'visibility' => [
        'group'   => 'groupe',
        'public'  => 'public',
        'private' => 'privé',
        'org'     => 'organisation',
    ],
    // Added: write-UI (reason)
    'reason' => [
        'spam' => 'Spam',
        'harassment' => 'Harcèlement',
        'inappropriate' => 'Inapproprié',
        'misinformation' => 'Désinformation',
        'other' => 'Autre',
    ],

    // Added: write-UI (action)
    'action' => [
        'hide' => 'Masquer',
        'lock' => 'Verrouiller',
        'unlock' => 'Déverrouiller',
        'restore' => 'Restaurer',
        'delete' => 'Supprimer',
        'escalate' => 'Escalader',
    ],

    // Added: write-UI (admin)
    'admin' => [
        'composeHeading' => 'Rédiger une publication',
        'titleLabel' => 'Titre (facultatif)',
        'titlePh' => 'Un court titre',
        'bodyLabel' => 'Message',
        'bodyPh' => 'Partagez quelque chose avec votre communauté…',
        'visibilityLabel' => 'Visibilité',
        'postBtn' => 'Publier',
        'reactBtn' => 'J’aime',
        'commentPh' => 'Écrire un commentaire…',
        'commentBtn' => 'Commenter',
        'reasonLabel' => 'Motif',
        'reportBtn' => 'Signaler',
        'reportConfirm' => 'Signaler cette publication aux modérateurs ?',
        'actionLabel' => 'Action',
        'moderateReasonPh' => 'Motif (obligatoire)',
        'moderateBtn' => 'Appliquer',
        'moderateConfirm' => 'Appliquer cette action de modération ?',
        'postedFlash' => 'Publication publiée.',
        'commentedFlash' => 'Commentaire ajouté.',
        'reactedFlash' => 'Réaction mise à jour.',
        'reportedFlash' => 'Signalement envoyé. Merci.',
        'moderatedFlash' => 'Action de modération appliquée.',
    ],

];
