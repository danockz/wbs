<?php

declare(strict_types=1);

namespace WBS\Shared\Navigation;

/**
 * The ONE canonical dynamic-menu fragment, rendered in the SAME location on every
 * page across the platform.
 *
 * Historically the menu appeared in two different places: an inline sticky sidebar
 * on pages that extend layouts/app.php, and a fixed slide-out on self-contained
 * pages injected by MenuInjectionFilter. That is two locations. This class is the
 * single source of truth so BOTH the layout and the filter emit the identical
 * markup — a fixed top-left launcher that opens the same slide-out drawer,
 * independent of each page's own structure. Result: the universal menu is in one
 * consistent location everywhere.
 *
 * Self-contained and CSP-safe: inline styles only (style-src 'self'
 * 'unsafe-inline'); a CSS-only checkbox toggle (no inline JS); the sole <script>
 * is the same-origin renderer /assets/js/menu.js (script-src 'self'). The menu is
 * DISPLAY only — route authorize: filters remain the security boundary.
 */
final class MenuFragment
{
    /**
     * @param bool $withStyle include the style block (true) or markup only
     *                        (false, when the host page already carries the CSS —
     *                        e.g. the layout emits the style once itself)
     */
    public static function html(bool $withStyle = true): string
    {
        $style = $withStyle ? self::style() : '';

        // Localized chrome strings. lang() may be unavailable in some bare render
        // contexts (harness/early boot); fall back to English and never leak the
        // raw "App.key" placeholder that lang() returns on a miss.
        $t = static function (string $key, string $fallback): string {
            if (! function_exists('lang')) {
                return $fallback;
            }
            $v = lang('App.' . $key);

            return (is_string($v) && $v !== 'App.' . $key) ? $v : $fallback;
        };

        // esc() is a CI4 global helper (always present in-app); guard it so the
        // fragment still renders in bare render contexts (harness/early boot).
        $e = static fn (string $s, string $ctx = 'html'): string => function_exists('esc')
            ? (string) esc($s, $ctx)
            : htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $open    = $e($t('menuOpen', 'Open menu'), 'attr');
        $title   = $e($t('menuTitle', 'Menu'), 'attr');
        $loading = $e($t('menuLoading', 'Loading menu…'));

        return $style . <<<HTML

<!-- Universal dynamic menu — single canonical mount (WBS\\Shared\\Navigation\\MenuFragment) -->
<input type="checkbox" id="wbs-menu-toggle" class="wbs-menu-toggle" aria-hidden="true">
<label for="wbs-menu-toggle" class="wbs-menu-fab" aria-label="{$open}" title="{$title}">
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
</label>
<label for="wbs-menu-toggle" class="wbs-menu-backdrop" aria-hidden="true"></label>
<nav id="wbs-menu" class="wbs-menu wbs-menu--drawer" aria-label="Primary navigation" data-endpoint="/me/menu">
    <p class="wbs-menu__loading">{$loading}</p>
</nav>
<script src="/assets/js/menu.js" defer></script>
HTML;
    }

    /** The canonical menu stylesheet (used by both the layout and the filter). */
    public static function style(): string
    {
        return <<<'HTML'
<style>

  /* ---- Mobile / narrow (default): fixed launcher + slide-out drawer ---- */
  /* Fixed launcher — same top-left position on every page. */
  .wbs-menu-toggle{position:fixed;left:-9999px;width:0;height:0}

  .wbs-menu-fab{position:fixed;top:14px;left:14px;z-index:10000;width:42px;height:42px;
    display:flex;align-items:center;justify-content:center;border-radius:10px;cursor:pointer;
    background:#0f172a;border:1px solid #1e293b;color:#e2e8f0;box-shadow:0 4px 14px #0006}

  .wbs-menu-fab:hover{border-color:#38bdf8}

  .wbs-menu-toggle:focus-visible + .wbs-menu-fab{outline:2px solid #38bdf8;outline-offset:2px}

  /* The one drawer — slides in from the left, over any page. */
  .wbs-menu--drawer{position:fixed;top:0;left:0;height:100vh;overflow-y:auto;z-index:9999;
    transform:translateX(-110%);transition:transform .2s ease;
    background:#0f172a;border-right:1px solid #1e293b;width:260px;padding:60px 0 16px;
    user-select:none;font:14px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#e2e8f0}

  .wbs-menu-toggle:checked ~ .wbs-menu--drawer{transform:none;box-shadow:0 0 40px #000a}

  .wbs-menu-backdrop{position:fixed;inset:0;z-index:9998;background:#0008;opacity:0;
    pointer-events:none;transition:opacity .2s}

  .wbs-menu-toggle:checked ~ .wbs-menu-backdrop{opacity:1;pointer-events:auto}

  .wbs-menu__group{padding-bottom:6px}

  .wbs-menu__cat{display:block;padding:12px 16px 4px;font-size:11px;letter-spacing:.08em;
    text-transform:uppercase;color:#94a3b8}

  .wbs-menu__link{display:flex;align-items:center;gap:10px;padding:8px 16px;color:#e2e8f0;
    text-decoration:none;border-left:3px solid transparent}

  .wbs-menu__link:hover{background:#1e293b}

  .wbs-menu__link[aria-current="page"]{background:#1e293b;border-left-color:#38bdf8;color:#fff}

  .wbs-menu__icon{display:inline-flex;color:#94a3b8;flex:0 0 auto}

  .wbs-menu__link[aria-current="page"] .wbs-menu__icon{color:#38bdf8}

  .wbs-menu__loading{padding:16px;color:#475569;font-size:.82rem}

  /* ---- Desktop / wide (>=1024px): pinned-open sidebar ----
     Same single component: the drawer stays open, the launcher/backdrop hide, and
     the page makes room with a left inset. Still one location — it just docks. */
  @media (min-width:1024px){
    body{padding-left:260px}
    .wbs-menu-fab,.wbs-menu-backdrop{display:none}

    .wbs-menu--drawer{transform:none;box-shadow:none;padding-top:16px}

    /* On desktop the toggle is irrelevant; keep the drawer open regardless. */
    .wbs-menu-toggle:checked ~ .wbs-menu--drawer{box-shadow:none}
  }
</style>
HTML;
    }
}
