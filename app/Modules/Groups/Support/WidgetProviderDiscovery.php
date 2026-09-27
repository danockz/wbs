<?php

declare(strict_types=1);

namespace WBS\Groups\Support;

/**
 * Discovers and registers dashboard widget providers automatically.
 *
 * Scans the configured module namespaces for classes implementing
 * GroupDashboardWidgetProvider and registers them with the dashboard service.
 * This allows modules to add widgets without modifying the Groups module.
 */
final class WidgetProviderDiscovery
{
    /** @var list<string> Module namespaces to scan */
    private static array $moduleNamespaces = [
        'WBS\\Groups\\Services',
        'WBS\\Gamification\\Services',
        'WBS\\Identity\\Services',
        'WBS\\Journey\\Services',
        'WBS\\Events\\Services',
        'WBS\\Contributions\\Services',
        'WBS\\Courses\\Services',
        'WBS\\Community\\Services',
        'WBS\\Announcements\\Services',
        'WBS\\Notifications\\Services',
        'WBS\\AccessControl\\Services',
        'WBS\\Reporting\\Services',
        'WBS\\Referrals\\Services',
        'WBS\\Meetings\\Services',
        'WBS\\Streaming\\Services',
        'WBS\\Geo\\Services',
        'WBS\\Audit\\Services',
        'WBS\\Admin\\Services',
        'WBS\\Integrations\\Services',
        'WBS\\Shared\\Services',
    ];

    /**
     * Discover all widget providers and register them with the service.
     *
     * @param GroupDashboardService $service The dashboard service to register with
     * @return int Number of providers registered
     */
    public static function discoverAndRegister(GroupDashboardService $service): int
    {
        $count = 0;
        
        foreach (self::$moduleNamespaces as $namespace) {
            $providers = self::findProvidersInNamespace($namespace);
            foreach ($providers as $provider) {
                $service->registerProvider($provider);
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Find all widget providers in a namespace.
     *
     * @param string $namespace The namespace to scan
     * @return list<GroupDashboardWidgetProvider>
     */
    public static function findProvidersInNamespace(string $namespace): array
    {
        $providers = [];
        $namespacePath = str_replace('\\', '/', $namespace);
        
        // Get the module path from namespace (e.g., "WBS/Groups/Services" -> "/Modules/Groups/Services")
        $firstSlash = strpos($namespacePath, '/');
        if ($firstSlash === false) {
            return [];
        }
        $modulePath = substr($namespacePath, $firstSlash);
        
        // Build the base path
        $basePath = rtrim(APPPATH ?? '', '/') . '/Modules' . $modulePath;
        
        // Check if the module directory exists
        if (! is_dir($basePath)) {
            return [];
        }
        
        // Scan for PHP files in the namespace directory
        $files = self::findPhpFiles($basePath);
        
        foreach ($files as $file) {
            $className = self::fileToClassName($file, $namespace);
            if ($className === null) {
                continue;
            }
            
            // Check if the class exists and implements the interface
            if (class_exists($className) && is_subclass_of($className, GroupDashboardWidgetProvider::class)) {
                $providers[] = new $className();
            }
        }
        
        return $providers;
    }

    /**
     * Recursively find all PHP files in a directory.
     *
     * @param string $dir The directory to scan
     * @return list<string> Absolute file paths
     */
    public static function findPhpFiles(string $dir): array
    {
        $files = [];
        $items = scandir($dir);
        
        if ($items === false) {
            return [];
        }
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $path = $dir . '/' . $item;
            
            if (is_dir($path)) {
                $files = array_merge($files, self::findPhpFiles($path));
            } elseif (is_file($path) && substr($item, -4) === '.php') {
                $files[] = $path;
            }
        }
        
        return $files;
    }

    /**
     * Convert a file path to a class name within a namespace.
     *
     * @param string $file Absolute file path
     * @param string $namespace Base namespace
     * @return string|null Fully qualified class name or null
     */
    public static function fileToClassName(string $file, string $namespace): ?string
    {
        // Get the base path from the namespace
        $namespacePath = str_replace('\\', '/', $namespace);
        $firstSlash = strpos($namespacePath, '/');
        if ($firstSlash === false) {
            return null;
        }
        $modulePath = substr($namespacePath, $firstSlash);
        
        $basePath = rtrim(APPPATH ?? '', '/') . '/Modules' . $modulePath;
        
        // Get relative path from base
        $relative = substr($file, strlen($basePath) + 1);
        
        // Skip if it's the base file itself
        if ($relative === '') {
            return null;
        }
        
        // Convert path to class name
        $classPath = str_replace('/', '\\', substr($relative, 0, -4)); // Remove .php
        
        if ($classPath === '') {
            return null;
        }
        
        return $namespace . '\\' . $classPath;
    }

    /**
     * Add a module namespace to scan.
     *
     * @param string $namespace Fully qualified namespace
     */
    public static function addNamespace(string $namespace): void
    {
        if (! in_array($namespace, self::$moduleNamespaces, true)) {
            self::$moduleNamespaces[] = $namespace;
        }
    }

    /**
     * Get all registered namespaces.
     *
     * @return list<string>
     */
    public static function getNamespaces(): array
    {
        return self::$moduleNamespaces;
    }

    /**
     * Clear the namespace cache (useful for testing).
     */
    public static function clearNamespaces(): void
    {
        self::$moduleNamespaces = [];
    }
}
