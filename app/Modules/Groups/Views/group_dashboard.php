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

// Helper to escape output
$e = fn ($v) => esc($v);

// Find current group name
$currentGroupName = '';
foreach ($userGroups as $g) {
    if (($g['id'] ?? '') === $currentGroupId) {
        $currentGroupName = $g['name'] ?? '';
        break;
    }
}
?>

<?= $this->section('content') ?>
    <div class="group-dashboard">
        <header class="group-dashboard__header">
            <h1><?= $e(lang('Groups.dashboard.title')) ?></h1>
            <p class="group-dashboard__subtitle">
                <?= $e(lang('Groups.dashboard.subtitle')) ?>
            </p>
        </header>

        <!-- Group Switcher -->
        <?php if ($userGroups !== []): ?>
            <nav class="group-dashboard__switcher" aria-label="<?= $e(lang('Groups.dashboard.switcherLabel')) ?>">
                <form method="get" action="/me/groups" class="switcher-form">
                    <select 
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
                            <?= $e(lang('Groups.dashboard.viewingGroup')) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>

        <!-- Dashboard Sections -->
        <?php if ($sections === []): ?>
            <div class="group-dashboard__empty">
                <p><?= $e(lang('Groups.dashboard.noWidgets')) ?></p>
                <?php if ($userGroups === []): ?>
                    <p class="muted"><?= $e(lang('Groups.dashboard.noGroupsHint')) ?></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($sections as $sectionKey => $sectionWidgets): ?>
                <?php
                // Get section label from lang or use the key
                $sectionLabel = lang("Groups.dashboard.section.{$sectionKey}") !== "Groups.dashboard.section.{$sectionKey}"
                    ? lang("Groups.dashboard.section.{$sectionKey}")
                    : ucfirst(str_replace('_', ' ', $sectionKey));
                ?>
                <section class="group-dashboard__section" aria-labelledby="section-<?= $e($sectionKey) ?>-heading">
                    <h2 id="section-<?= $e($sectionKey) ?>-heading" class="group-dashboard__section-title">
                        <?= $e($sectionLabel) ?>
                    </h2>
                    <div class="group-dashboard__widgets">
                        <?php foreach ($sectionWidgets as $widget): ?>
                            <?php
                            // Render the widget
                            $widgetId = $widget->id();
                            $widgetHtml = '';
                            
                            // For now, show a placeholder since we haven't implemented
                            // the actual widget renderers yet
                            $widgetHtml = $this->renderWidgetPlaceholder($widget);
                            
                            // Only render if we have content
                            if ($widgetHtml !== ''):
                            ?>
                            <article 
                                class="group-dashboard__widget widget-<?= $e($widget->module) ?>-<?= $e($widget->key) ?>"
                                aria-labelledby="widget-<?= $e($widgetId) ?>-heading"
                            >
                                <header class="widget__header">
                                    <h3 id="widget-<?= $e($widgetId) ?>-heading" class="widget__title">
                                        <?= $e(lang($widget->labelKey) !== $widget->labelKey ? lang($widget->labelKey) : $widget->labelKey) ?>
                                    </h3>
                                </header>
                                <div class="widget__body">
                                    <?= $widgetHtml ?>
                                </div>
                            </article>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Metadata -->
        <footer class="group-dashboard__meta muted">
            <?= $e(lang('Groups.dashboard.asOf', [$asOf])) ?>
            <?php if ($widgetCount > 0): ?>
                · <?= $e(lang('Groups.dashboard.widgetCount', [(string) $widgetCount])) ?>
            <?php endif; ?>
        </footer>
    </div>

    <style>
        /* Mobile-first, minimal styles */
        .group-dashboard {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem;
        }
        
        .group-dashboard__header {
            margin-bottom: 1.5rem;
        }
        
        .group-dashboard__header h1 {
            margin: 0 0 .5rem;
            font-size: 1.5rem;
        }
        
        .group-dashboard__subtitle {
            margin: 0;
            color: #666;
            font-size: .875rem;
        }
        
        .group-dashboard__switcher {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .switcher-form {
            display: inline;
        }
        
        .switcher-select {
            padding: .5rem .75rem;
            border: 1px solid #d1d5db;
            border-radius: .375rem;
            font-size: .875rem;
            background: white;
            min-width: 200px;
        }
        
        .switcher-current {
            font-size: .875rem;
        }
        
        .switcher-current strong {
            color: #1f2937;
        }
        
        .group-dashboard__empty {
            text-align: center;
            padding: 2rem;
            color: #666;
        }
        
        .group-dashboard__section {
            margin-bottom: 2rem;
        }
        
        .group-dashboard__section-title {
            font-size: 1rem;
            font-weight: 600;
            margin: 0 0 1rem;
            padding-bottom: .5rem;
            border-bottom: 1px solid #e5e7eb;
            color: #1f2937;
        }
        
        .group-dashboard__widgets {
            display: grid;
            gap: 1rem;
        }
        
        .group-dashboard__widget {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: .5rem;
            padding: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,.1);
        }
        
        .widget__header {
            margin-bottom: .75rem;
        }
        
        .widget__title {
            font-size: .875rem;
            font-weight: 600;
            margin: 0;
            color: #374151;
        }
        
        .widget__body {
            font-size: .875rem;
            color: #4b5563;
        }
        
        .group-dashboard__meta {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #e5e7eb;
            font-size: .75rem;
        }
        
        .muted {
            color: #9ca3af;
        }
        
        /* Responsive */
        @media (min-width: 768px) {
            .group-dashboard {
                padding: 1.5rem;
            }
            
            .group-dashboard__header h1 {
                font-size: 1.875rem;
            }
            
            .group-dashboard__widgets {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .switcher-select {
                min-width: 250px;
            }
        }
        
        @media (min-width: 1024px) {
            .group-dashboard__widgets {
                grid-template-columns: repeat(3, 1fr);
            }
        }
    </style>

    <?php
    // Helper function to render widget placeholders
    // This will be replaced with actual widget rendering logic
    function renderWidgetPlaceholder($widget) {
        $label = lang($widget->labelKey) !== $widget->labelKey ? lang($widget->labelKey) : $widget->labelKey;
        return '<p>' . esc(lang('Groups.dashboard.widgetPlaceholder', [$label])) . '</p>';
    }
    ?>
<?= $this->endSection() ?>
