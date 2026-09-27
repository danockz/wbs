<?php

declare(strict_types=1);

/**
 * Shared admin-console chrome (app/Modules/Shared/Views/admin_console.php).
 * Used by every back-office read page (AccessControl, Gamification, Admin,
 * Groups, Events managerial, Contributions, Streaming, Referrals, Integrations).
 *
 * English is the guaranteed fallback: the view falls back to the English literal
 * when a key is missing, so a partial translation never blanks the page.
 */

return [
    'consoleTitle'  => 'Admin console',
    'badge'         => 'admin',
    'record'        => 'record',
    'records'       => 'records',
    'error'         => 'Error',
    'status'        => 'status {0}',
    'requestFailed' => 'Request could not be completed.',
    'noRecords'     => 'No records.',
    'meta'          => 'Meta',
];
