<?php
/**
 * Universal dynamic menu — server-rendered nav partial.
 *
 * Consumes the SAME tree MenuService::buildFor()/buildFromRoles() produces (or the
 * ?bundle=1 payload folded server-side), so first paint is correct with zero JS.
 * A tiny progressive-enhancement script revalidates via the ETag and can re-fetch
 * ?bundle=1 to re-render locally on a scope switch — but the menu WORKS without it.
 *
 * Self-contained: inline styles + inline SVG only (renders in sandboxed previews
 * and under the platform's strict CSP: style-src 'self' 'unsafe-inline').
 *
 * @var array{categories:list<array{key:string,label:string,items:list<array<string,mixed>>}>,count:int,scope?:?string,version?:int,etag?:string} $menu
 * @var string $active   current path (for highlight), e.g. 'me/contacts'
 */
$menu   = $menu ?? ['categories' => [], 'count' => 0];
$active = trim($active ?? '', '/');

$icon = static function (?string $name): string {
    // A tiny inline-SVG set; unknown -> a neutral dot. Keeps the partial asset-free.
    $paths = [
        'gauge'        => '<circle cx="12" cy="12" r="9"/><path d="M12 12l4-2"/>',
        'address-book' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6"/>',
        'shield'       => '<path d="M12 3l7 3v6c0 4-3 7-7 8-4-1-7-4-7-8V6z"/>',
        'users'        => '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0112 0"/><path d="M16 11a3 3 0 000-6"/>',
        'map-pin'      => '<path d="M12 21s7-6 7-11a7 7 0 10-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'calendar'     => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 9h18M8 3v4M16 3v4"/>',
        'graduation-cap' => '<path d="M12 4l10 5-10 5L2 9z"/><path d="M6 11v5c0 1 3 3 6 3s6-2 6-3v-5"/>',
        'chart-bar'    => '<path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/>',
        'key'          => '<circle cx="8" cy="12" r="4"/><path d="M12 12h9l-2 2 2 2"/>',
        'cog'          => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>',
        'route'        => '<circle cx="6" cy="19" r="2.5"/><circle cx="18" cy="5" r="2.5"/><path d="M8 19h7a3 3 0 003-3V8"/>',
        'hand-heart'   => '<path d="M11 8.5c-1-1.6-3.4-1-3.4 1 0 1.6 3.4 3.5 3.4 3.5s3.4-1.9 3.4-3.5c0-2-2.4-2.6-3.4-1z"/><path d="M3 14l4 4 8 1 6-3"/>',
        'message-square' => '<path d="M4 5h16v11H8l-4 4z"/>',
        'video'        => '<rect x="3" y="6" width="12" height="12" rx="2"/><path d="M15 10l6-3v10l-6-3z"/>',
        'trophy'       => '<path d="M7 4h10v4a5 5 0 01-10 0zM5 6H3v2a3 3 0 003 3M19 6h2v2a3 3 0 01-3 3M9 17h6M12 13v4M8 21h8"/>',
        'inbox'        => '<path d="M3 13h5l1 3h6l1-3h5"/><path d="M4 13l2-8h12l2 8v6H4z"/>',
        'git-branch'   => '<circle cx="6" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="8" r="2.5"/><path d="M6 8.5v7M6 15a9 9 0 019-9h1"/>',
        'list'         => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'award'        => '<circle cx="12" cy="9" r="5"/><path d="M9 13l-2 8 5-3 5 3-2-8"/>',
        'shuffle'      => '<path d="M3 6h4l10 12h4M3 18h4l3-4M17 4l4 2-4 2M17 16l4 2-4 2"/>',
        'layers'       => '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
        'book'         => '<path d="M4 5a2 2 0 012-2h13v16H6a2 2 0 00-2 2z"/><path d="M4 19V5"/>',
        'flag'         => '<path d="M5 21V4h11l-1.5 3L16 10H5"/>',
        'user-plus'    => '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0112 0"/><path d="M19 8v6M22 11h-6"/>',
        'calendar-plus' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 9h18M8 3v4M16 3v4M12 13v4M10 15h4"/>',
        'qr-code'      => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3M20 14v.01M14 20v.01M20 20v.01M17 17h.01"/>',
        'receipt'      => '<path d="M5 3v18l2-1 2 1 2-1 2 1 2-1 2 1V3l-2 1-2-1-2 1-2-1-2 1z"/><path d="M8 8h8M8 12h8"/>',
        'sliders'      => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
        'download'     => '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>',
        'move'         => '<path d="M12 2v20M2 12h20M8 6l4-4 4 4M8 18l4 4 4-4M6 8l-4 4 4 4M18 8l4 4-4 4"/>',
        'folder-plus'  => '<path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><path d="M12 11v4M10 13h4"/>',
        'plug'         => '<path d="M9 2v6M15 2v6M7 8h10v3a5 5 0 01-10 0zM12 16v6"/>',
        'alert-triangle' => '<path d="M12 3l10 18H2z"/><path d="M12 10v4M12 18v.01"/>',
    ];
    $inner = $paths[$name ?? ''] ?? '<circle cx="12" cy="12" r="2.5"/>';

    return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" '
        . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $inner . '</svg>';
};
?>
<nav class="wbs-menu" aria-label="Primary" data-menu-version="<?= esc($menu['version'] ?? '', 'attr') ?>">
    <style>


        .wbs-menu{--bg:#0f172a;--fg:#e2e8f0;--mut:#94a3b8;--acc:#38bdf8;--hov:#1e293b;
            font:14px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--fg);
            background:var(--bg);width:250px;padding:12px 0;border-radius:12px;user-select:none}
</style>

    <?php if (($menu['count'] ?? 0) === 0): ?>
        <p class="wbs-menu__empty">No menu items available.</p>
    <?php else: ?>
        <?php foreach ($menu['categories'] as $cat): ?>
            <div class="wbs-menu__cat"><?= esc($cat['label']) ?></div>
            <?php foreach ($cat['items'] as $it): ?>
                <?php
                $route   = trim((string) ($it['route'] ?? ''), '/');
                $current = $route !== '' && $route === $active;
                ?>
                <a class="wbs-menu__link" href="/<?= esc($route, 'attr') ?>"
                   <?= $current ? 'aria-current="page"' : '' ?>
                   data-item="<?= esc((string) ($it['id'] ?? ''), 'attr') ?>">
                    <?= $icon($it['icon'] ?? null) ?>
                    <span><?= esc((string) ($it['label'] ?? '')) ?></span>
                    <?php if (! empty($it['badge'])): ?>
                        <span class="wbs-menu__badge" data-badge="<?= esc((string) $it['badge'], 'attr') ?>" hidden></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</nav>
