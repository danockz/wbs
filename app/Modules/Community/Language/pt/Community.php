<?php

declare(strict_types=1);

/** Community — Portuguese. Values only; keys mirror en/Community.php. */

return [
    'title'          => 'Mural da comunidade',
    'post'           => 'publicação',
    'posts'          => 'publicações',
    'visibleToYou'   => 'visíveis para você',
    'empty'          => 'Ainda não há publicações para mostrar.',
    'memberFallback' => 'Membro',
    'pinned'         => '📌 fixado',

    'visibility' => [
        'group'   => 'grupo',
        'public'  => 'público',
        'private' => 'privado',
        'org'     => 'organização',
    ],
    // Added: write-UI (reason)
    'reason' => [
        'spam' => 'Spam',
        'harassment' => 'Assédio',
        'inappropriate' => 'Inadequado',
        'misinformation' => 'Desinformação',
        'other' => 'Outro',
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
        'composeHeading' => 'Escrever uma publicação',
        'titleLabel' => 'Título (opcional)',
        'titlePh' => 'Um título breve',
        'bodyLabel' => 'Mensagem',
        'bodyPh' => 'Partilhe algo com a sua comunidade…',
        'visibilityLabel' => 'Visibilidade',
        'postBtn' => 'Publicar',
        'reactBtn' => 'Gosto',
        'commentPh' => 'Escreva um comentário…',
        'commentBtn' => 'Comentar',
        'reasonLabel' => 'Motivo',
        'reportBtn' => 'Denunciar',
        'reportConfirm' => 'Denunciar esta publicação aos moderadores?',
        'actionLabel' => 'Ação',
        'moderateReasonPh' => 'Motivo (obrigatório)',
        'moderateBtn' => 'Aplicar',
        'moderateConfirm' => 'Aplicar esta ação de moderação?',
        'postedFlash' => 'Publicação publicada.',
        'commentedFlash' => 'Comentário adicionado.',
        'reactedFlash' => 'Reação atualizada.',
        'reportedFlash' => 'Denúncia enviada. Obrigado.',
        'moderatedFlash' => 'Ação de moderação aplicada.',
    ],

];
