<?php

declare(strict_types=1);

/** Community — Spanish. Values only; keys mirror en/Community.php. */

return [
    'title'          => 'Muro de la comunidad',
    'post'           => 'publicación',
    'posts'          => 'publicaciones',
    'visibleToYou'   => 'visibles para ti',
    'empty'          => 'Aún no hay publicaciones para mostrar.',
    'memberFallback' => 'Miembro',
    'pinned'         => '📌 fijado',

    'visibility' => [
        'group'   => 'grupo',
        'public'  => 'público',
        'private' => 'privado',
        'org'     => 'organización',
    ],
    // Added: write-UI (reason)
    'reason' => [
        'spam' => 'Spam',
        'harassment' => 'Acoso',
        'inappropriate' => 'Inapropiado',
        'misinformation' => 'Desinformación',
        'other' => 'Otro',
    ],

    // Added: write-UI (action)
    'action' => [
        'hide' => 'Ocultar',
        'lock' => 'Bloquear',
        'unlock' => 'Desbloquear',
        'restore' => 'Restaurar',
        'delete' => 'Eliminar',
        'escalate' => 'Escalar',
    ],

    // Added: write-UI (admin)
    'admin' => [
        'composeHeading' => 'Escribir una publicación',
        'titleLabel' => 'Título (opcional)',
        'titlePh' => 'Un titular breve',
        'bodyLabel' => 'Mensaje',
        'bodyPh' => 'Comparte algo con tu comunidad…',
        'visibilityLabel' => 'Visibilidad',
        'postBtn' => 'Publicar',
        'reactBtn' => 'Me gusta',
        'commentPh' => 'Escribe un comentario…',
        'commentBtn' => 'Comentar',
        'reasonLabel' => 'Motivo',
        'reportBtn' => 'Denunciar',
        'reportConfirm' => '¿Denunciar esta publicación a los moderadores?',
        'actionLabel' => 'Acción',
        'moderateReasonPh' => 'Motivo (obligatorio)',
        'moderateBtn' => 'Aplicar',
        'moderateConfirm' => '¿Aplicar esta acción de moderación?',
        'postedFlash' => 'Publicación publicada.',
        'commentedFlash' => 'Comentario añadido.',
        'reactedFlash' => 'Reacción actualizada.',
        'reportedFlash' => 'Denuncia enviada. Gracias.',
        'moderatedFlash' => 'Acción de moderación aplicada.',
    ],

];
