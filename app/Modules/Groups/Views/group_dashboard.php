<?= $this->extend('layouts/app') ?>

<?php
/**
 * Hierarchical group dashboard.
 *
 * Dynamic, server-rendered dashboard that pulls widgets from every module.
 * Mobile-first, fail-closed on permissions/config.
 *
 * @var array<string,mixed> $result
 */

$scope = $result['scope'] ?? [];
$sections = $result['sections'] ?? [];
$userGroups = $result['user_groups'] ?? [];
$currentGroupId = $result['current_group_id'] ?? null;
$widgetCount = $result['widget_count'] ?? 0;
$asOf = $result['as_of'] ?? '';
$csrf = $csrf ?? '';

// Helper to escape output
$e = fn ($v) => esc($v);

// Find current group name
$currentGroupName = '';
$currentGroupPath = [];
foreach ($userGroups as $g) {
    if (($g['id'] ?? '') === $currentGroupId) {
        $currentGroupName = $g['name'] ?? '';
        $currentGroupPath = $g['path'] ?? [];
        break;
    }
}

// Section labels from lang or fallback
$sectionLabels = [];
foreach (array_keys($sections) as $sectionKey) {
    $labelKey = "Groups.dashboard.section.{$sectionKey}";
    $sectionLabels[$sectionKey] = lang($labelKey) !== $labelKey ? lang($labelKey) : ucfirst(str_replace('_', ' ', $sectionKey));
}
?>

<?= $this->section('content') ?>
    <div class="group-dashboard">
        <!-- Header -->
        <header class="group-dashboard__header">
            <div class="group-dashboard__title-bar">
                <h1 class="group-dashboard__title">
                    <?= $e(lang('Groups.dashboard.title')) ?>
                </h1>
                <p class="group-dashboard__subtitle">
                    <?= $e(lang('Groups.dashboard.subtitle')) ?>
                </p>
            </div>
        </header>

        <!-- Group Switcher -->
        <?php if ($userGroups !== []): ?>
            <nav class="group-dashboard__switcher" aria-label="<?= $e(lang('Groups.dashboard.switcherLabel')) ?>">
                <form method="get" action="/me/groups" class="switcher-form">
                    <label for="group-select" class="visually-hidden">
                        <?= $e(lang('Groups.dashboard.selectGroup')) ?>
                    </label>
                    <select 
                        id="group-select"
                        name="group_id"
                        class="switcher-select"
                        onchange="this.form.submit()"
                        aria-label="<?= $e(lang('Groups.dashboard.selectGroup')) ?>"
                    >
                        <?php foreach ($userGroups as $g): ?>
                            <option 
                                value="<?= $e($g['id'] ?? '') ?>"
                                <?php if (($g['id'] ?? '') === $currentGroupId): ?>selected<?php endif; ?>
                            >
                                <?= $e($g['name'] ?? '') ?>
                                <?php if (! empty($g['role'])): ?>
                                    (<= $e($g['role']) ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <div class="switcher-current">
                    <strong><?= $e($currentGroupName) ?></strong>
                    <?php if ($currentGroupName !== ''): ?>
                        <span class="muted">
                            &mdash; <?= $e(lang('Groups.dashboard.viewingGroup')) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>

        <!-- Dashboard Content -->
        <main class="group-dashboard__main">
            <?php if ($sections === []): ?>
                <!-- Empty State -->
                <div class="group-dashboard__empty">
                    <div class="empty-icon">&#128100;</div>
                    <h2><?= $e(lang('Groups.dashboard.noWidgets')) ?></h2>
                    <?php if ($userGroups === []): ?>
                        <p class="empty-hint">
                            <?= $e(lang('Groups.dashboard.noGroupsHint')) ?>
                        </p>
                    <?php else: ?>
                        <p class="empty-hint">
                            <?= $e(lang('Groups.dashboard.noWidgetsHint')) ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- Sections with Widgets -->
                <?php foreach ($sections as $sectionKey => $sectionWidgets): ?>
                    <?php $sectionLabel = $sectionLabels[$sectionKey] ?? ucfirst(str_replace('_', ' ', $sectionKey)); ?>
                    <section 
                        class="group-dashboard__section group-dashboard__section--<?= $e($sectionKey) ?>"
                        aria-labelledby="section-<?= $e($sectionKey) ?>-heading"
                    >
                        <div class="section-header">
                            <h2 id="section-<?= $e($sectionKey) ?>-heading" class="section-title">
                                <?= $e($sectionLabel) ?>
                            </h2>
                            <span class="section-count">
                                <?= count($sectionWidgets) ?> <?= $e(lang('Groups.dashboard.widgetCountShort')) ?>
                            </span>
                        </div>
                        
                        <div class="widgets-grid widgets-grid--<?= $e($sectionKey) ?>">
                            <?php foreach ($sectionWidgets as $widget): ?>
                                <?php
                                $widgetId = $widget->id();
                                $widgetLabel = lang($widget->labelKey) !== $widget->labelKey 
                                    ? lang($widget->labelKey) 
                                    : $widget->labelKey;
                                
                                // Check visibility (should already be filtered, but double-check)
                                $isVisible = $widget->visible(
                                    $scope['org_id'] ?? '',
                                    $scope['user_id'] ?? '',
                                    [], // permissions - would come from auth in real use
                                    []  // configs
                                );
                                
                                if (! $isVisible) {
                                    continue;
                                }
                                
                                // For now, render a placeholder card since we don't have
                                // the actual renderer integration set up in the controller yet
                                $widgetHtml = '<p class="widget-placeholder">' . 
                                    $e(lang('Groups.dashboard.widgetComingSoon', [$widgetLabel])) . '</p>';
                                
                                // If we have a renderer, try to use it
                                if ($widget->rendererClass !== '' && class_exists($widget->rendererClass)) {
                                    try {
                                        $renderer = $widget->rendererClass::create();
                                        $scopeData = [
                                            'org_id' => $scope['org_id'] ?? '',
                                            'user_id' => $scope['user_id'] ?? '',
                                            'group_id' => $scope['group_id'] ?? null,
                                            'group_ids' => [], // Would be resolved by service
                                            'options' => [],
                                        ];
                                        $widgetHtml = $renderer->render(
                                            $scope['org_id'] ?? '',
                                            $scope['user_id'] ?? '',
                                            $scopeData,
                                            []
                                        );
                                    } catch (\Throwable $e) {
                                        // Render error state
                                        $widgetHtml = '<p class="widget-error">' . 
                                            $e(lang('Groups.dashboard.widgetError')) . '</p>';
                                    }
                                }
                                ?>
                                
                                <article 
                                    class="widget widget--<?= $e(strtolower($widget->module)) ?> widget--<?= $e(strtolower($widget->key)) ?>"
                                    aria-labelledby="widget-<?= $e($widgetId) ?>-heading"
                                >
                                    <header class="widget-header">
                                        <h3 
                                            id="widget-<?= $e($widgetId) ?>-heading" 
                                            class="widget-title"
                                            title="<?= $e($widget->module) ?> &middot; <?= $e($widget->key) ?>"
                                        >
                                            <?= $e($widgetLabel) ?>
                                        </h3>
                                        <?php if ($widget->permission !== null): ?>
                                            <span 
                                                class="widget-badge badge-permission"
                                                title="<?= $e(lang('Groups.dashboard.requiresPermission', [$widget->permission])) ?>"
                                            >
                                                <?= $e(lang('Groups.dashboard.permissionBadge')) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($widget->capability !== null): ?>
                                            <span 
                                                class="widget-badge badge-capability"
                                                title="<?= $e(lang('Groups.dashboard.requiresCapability', [$widget->capability])) ?>"
                                            >
                                                <?= $e(lang('Groups.dashboard.configBadge')) ?>
                                            </span>
                                        <?php endif; ?>
                                    </header>
                                    
                                    <div class="widget-body">
                                        <?= $widgetHtml ?>
                                    </div>
                                    
                                    <?php if ($widgetHtml === '<p class="widget-placeholder">' . $e(lang('Groups.dashboard.widgetComingSoon', [$widgetLabel])) . '</p>'): ?>
                                        <footer class="widget-footer">
                                            <span class="widget-scope">
                                                <?= $e(lang('Groups.dashboard.scope.' . $widget->scope, [], $widget->scope)) ?>
                                            </span>
                                        </footer>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>

        <!-- Metadata Footer -->
        <footer class="group-dashboard__meta">
            <span class="meta-item">
                <?= $e(lang('Groups.dashboard.asOf', [$asOf])) ?>
            </span>
            <?php if ($widgetCount > 0): ?>
                <span class="meta-item">
                    &middot; <?= $e(lang('Groups.dashboard.widgetCount', [(string) $widgetCount])) ?>
                </span>
            <?php endif; ?>
            <?php if ($currentGroupId): ?>
                <span class="meta-item">
                    &middot; <?= $e(lang('Groups.dashboard.groupId', [$currentGroupId])) ?>
                </span>
            <?php endif; ?>
        </footer>
    </div>

    <!-- Styles -->
    <style>
        /* ===== Base ===== */
        .group-dashboard {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }
        
        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        
        .muted {
            color: #6b7280;
        }
        
        /* ===== Header ===== */
        .group-dashboard__header {
            margin-bottom: 1.5rem;
        }
        
        .group-dashboard__title {
            margin: 0 0 0.5rem;
            font-size: 1.5rem;
            font-weight: 700;
            color: #111827;
        }
        
        .group-dashboard__subtitle {
            margin: 0;
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        /* ===== Switcher ===== */
        .group-dashboard__switcher {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .switcher-form {
            flex: 1;
            min-width: 200px;
        }
        
        .switcher-select {
            width: 100%;
            max-width: 300px;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            line-height: 1.25;
            color: #111827;
            background-color: #fff;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            appearance: none;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        
        .switcher-select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .switcher-current {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .switcher-current strong {
            color: #111827;
            font-weight: 600;
        }
        
        /* ===== Empty State ===== */
        .group-dashboard__empty {
            text-align: center;
            padding: 3rem 1rem;
            color: #6b7280;
        }
        
        .empty-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .empty-hint {
            margin-top: 0.5rem;
            font-size: 0.875rem;
            color: #9ca3af;
        }
        
        /* ===== Main Content ===== */
        .group-dashboard__main {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }
        
        /* ===== Sections ===== */
        .group-dashboard__section {
            break-inside: avoid;
        }
        
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        
        .section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #111827;
            margin: 0;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .section-count {
            font-size: 0.75rem;
            color: #9ca3af;
            font-weight: 500;
        }
        
        /* ===== Widgets Grid ===== */
        .widgets-grid {
            display: grid;
            grid-template-columns: repeat(1, 1fr);
            gap: 1rem;
        }
        
        /* ===== Widget Card ===== */
        .widget {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: box-shadow 0.15s ease-in-out, transform 0.15s ease-in-out;
        }
        
        .widget:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .widget-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #f3f4f6;
            flex-wrap: wrap;
        }
        
        .widget-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: #111827;
            margin: 0;
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .widget-badge {
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.25rem 0.5rem;
            border-radius: 9999px;
            color: #fff;
        }
        
        .badge-permission {
            background: #3b82f6;
        }
        
        .badge-capability {
            background: #8b5cf6;
        }
        
        .widget-body {
            padding: 1rem;
            font-size: 0.875rem;
            color: #374151;
            line-height: 1.5;
        }
        
        .widget-placeholder {
            color: #9ca3af;
            font-style: italic;
        }
        
        .widget-error {
            color: #ef4444;
            font-weight: 500;
        }
        
        .widget-footer {
            padding: 0.5rem 1rem;
            background: #f9fafb;
            border-top: 1px solid #f3f4f6;
            font-size: 0.75rem;
            color: #9ca3af;
        }
        
        .widget-scope {
            display: inline-block;
            padding: 0.125rem 0.5rem;
            background: #e5e7eb;
            border-radius: 0.25rem;
            font-size: 0.75rem;
        }
        
        /* ===== Metadata ===== */
        .group-dashboard__meta {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #e5e7eb;
            font-size: 0.75rem;
            color: #9ca3af;
        }
        
        .meta-item {
            display: inline-block;
        }
        
        /* ===== Responsive ===== */
        @media (min-width: 640px) {
            .group-dashboard {
                padding: 1.5rem;
            }
            
            .widgets-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (min-width: 768px) {
            .group-dashboard__title {
                font-size: 1.875rem;
            }
            
            .switcher-select {
                max-width: 350px;
            }
        }
        
        @media (min-width: 1024px) {
            .widgets-grid {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .group-dashboard__switcher {
                flex-wrap: nowrap;
            }
        }
        
        @media (min-width: 1280px) {
            .widgets-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        /* ===== Section-specific styling ===== */
        .group-dashboard__section--gamification .section-title {
            border-bottom-color: #8b5cf6;
        }
        
        .group-dashboard__section--people .section-title {
            border-bottom-color: #10b981;
        }
        
        .group-dashboard__section--journey .section-title {
            border-bottom-color: #f59e0b;
        }
        
        .group-dashboard__section--events .section-title {
            border-bottom-color: #3b82f6;
        }
        
        .group-dashboard__section--giving .section-title {
            border-bottom-color: #ef4444;
        }
        
        .group-dashboard__section--learning .section-title {
            border-bottom-color: #6366f1;
        }
        
        /* ===== Print ===== */
        @media print {
            .group-dashboard__switcher {
                display: none;
            }
            
            .widget {
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }
    </style>
<?= $this->endSection() ?>
