<?php

declare(strict_types=1);

return [
    'metaTitle'  => 'Reuniones — Agenda',
    'heading'    => 'Agenda de reuniones',
    'sub'        => 'Reuniones y seminarios web programados y en vivo en la organización.',
    'count'      => '{0} reuniones',
    'countOne'   => '{0} reunión',
    'empty'      => 'Aún no hay reuniones programadas.',
    'colTitle'   => 'Reunión',
    'colWhen'    => 'Cuándo',
    'colProvider'=> 'Proveedor',
    'colStatus'  => 'Estado',
    'colAccess'  => 'Acceso',
    'join'       => 'Enlace de acceso',
    'noJoin'     => 'Sin enlace',
    'noTime'     => 'Hora no definida',
    'status' => [
        'scheduled' => 'Programada',
        'live'      => 'En vivo',
        'ended'     => 'Finalizada',
        'canceled'  => 'Cancelada',
    ],
    'provider' => [
        'zoom'  => 'Zoom',
        'meet'  => 'Google Meet',
        'teams' => 'Microsoft Teams',
        'jitsi' => 'Jitsi',
        'link'  => 'Enlace alojado',
    ],
    'mode' => [
        'meeting' => 'Reunión',
        'webinar' => 'Seminario web',
    ],
    'access' => [
        'public'     => 'Público',
        'restricted' => 'Restringido',
    ],
    // Added: write-UI (role)
    'role' => [
        'host' => 'Anfitrión',
        'cohost' => 'Coanfitrión',
        'attendee' => 'Asistente',
    ],

    // Added: write-UI (admin)
    'admin' => [
        'colActions' => 'Acciones',
        'scheduleHeading' => 'Programar una reunión',
        'titleLabel' => 'Título',
        'titlePh' => 'p. ej. Llamada de líderes del domingo',
        'providerLabel' => 'Proveedor',
        'modeLabel' => 'Modo',
        'accessLabel' => 'Acceso',
        'startsLabel' => 'Inicio',
        'endsLabel' => 'Fin',
        'joinUrlLabel' => 'Enlace de acceso alojado (opcional)',
        'joinUrlHint' => 'Debe comenzar con https://. Déjelo en blanco para que un proveedor conectado cree la reunión.',
        'scheduleBtn' => 'Programar reunión',
        'statusLabel' => 'Estado',
        'setStatusBtn' => 'Actualizar',
        'userIdLabel' => 'Usuario',
        'userIdPh' => 'ID de usuario',
        'userIdNone' => '— Seleccionar una persona —',
        'roleLabel' => 'Rol',
        'grantBtn' => 'Conceder acceso',
        'createdFlash' => 'Reunión programada.',
        'transitionedFlash' => 'Estado de la reunión actualizado.',
        'grantedFlash' => 'Acceso de conexión concedido.',
    ],

];
