<?php

declare(strict_types=1);

namespace WBS\Groups\Support;

/**
 * Interface for modules to register their dashboard widgets.
 *
 * Each module that wants to expose widgets on the hierarchical group dashboard
 * implements this interface and registers it in the Groups module's service
 * locator. The dashboard service iterates all registered providers and collects
 * their widgets.
 */
interface GroupDashboardWidgetProvider
{
    /**
     * Return the list of widgets this module provides.
     *
     * @return list<GroupDashboardWidget>
     */
    public static function widgets(): array;
}
