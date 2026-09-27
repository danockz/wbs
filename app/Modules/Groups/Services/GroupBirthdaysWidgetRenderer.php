<?php

declare(strict_types=1);

namespace WBS\Groups\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Config\Services as GroupServices;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the birthdays widget for the group dashboard.
 *
 * Delegates to the BirthdayService to get upcoming birthdays for the
 * current scope (ancestor groups).
 */
final class GroupBirthdaysWidgetRenderer implements WidgetRenderer
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Factory method for lazy instantiation.
     */
    public static function create(): static
    {
        return new static(\Config\Database::connect(), new Clock());
    }

    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $groupIds = $scopeData['group_ids'] ?? [];
        
        if ($groupIds === []) {
            return '<p class="widget-empty">' . esc(lang('Groups.birthdays.empty')) . '</p>';
        }

        // Use the BirthdayService to collect birthdays for ancestor groups
        $birthdayService = GroupServices::birthdays();
        
        // For now, show a simple message - the actual integration with BirthdayService
        // will be added in a follow-up
        return '<p>' . esc(lang('Groups.birthdays.sub')) . '</p>';
    }
}
