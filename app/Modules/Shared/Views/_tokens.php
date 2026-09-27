<?php

declare(strict_types=1);

/**
 * SHARED DESIGN TOKENS — a single inline :root token block distilled from the
 * Velzon admin template's design language (colors, spacing, radius, typography),
 * WITHOUT any of Velzon's runtime cost (no jQuery, no CDN, no 500 KB app.css, no
 * blocking requests). See docs/FORMS_CRUD_VIEWS_AUDIT.md §5: adopt the LOOK, not
 * the LOAD.
 *
 * Emits ~1 KB of inline CSS variables shared platform-wide: the base layout
 * (app/Views/layouts/app.php), the shared page presenter, and any other
 * self-contained view can reference these tokens. CSP-safe: inline style
 * only, allowed by style-src 'self' 'unsafe-inline'. Include once in <head>
 * before a page's own style block.
 *
 * Token source: Velzon 4.0.0 default palette — primary #405189, success #0ab39c,
 * danger #f06548, warning #f7b84b, info #299cdb — mapped onto the platform's
 * existing dark surface (#0b1120/#0f172a/#1e293b) so nothing else has to change.
 */
?>
<style>
:root{
  /* Brand + semantic (Velzon palette) */
  --wbs-primary:#405189; --wbs-primary-2:#4c5fa6; --wbs-on-primary:#fff;
  --wbs-success:#0ab39c; --wbs-danger:#f06548; --wbs-warning:#f7b84b; --wbs-info:#299cdb;
  /* Surfaces (platform dark scale) */
  --wbs-bg:#0b1120; --wbs-surface:#0f172a; --wbs-surface-2:#111c33; --wbs-border:#1e293b; --wbs-border-2:#334155;
  /* Text */
  --wbs-text:#e2e8f0; --wbs-muted:#94a3b8; --wbs-faint:#64748b;
  /* Radius scale (Velzon: sm .25 / base .375 / lg .5 rem, pill) */
  --wbs-radius-sm:5px; --wbs-radius:8px; --wbs-radius-lg:12px; --wbs-radius-pill:999px;
  /* Spacing scale */
  --wbs-sp-1:4px; --wbs-sp-2:8px; --wbs-sp-3:12px; --wbs-sp-4:16px; --wbs-sp-5:22px; --wbs-sp-6:32px;
  /* Typography */
  --wbs-font:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  --wbs-fs-h1:clamp(1.6rem,3.5vw,2.2rem); --wbs-fs-body:.9rem; --wbs-fs-sm:.82rem; --wbs-fs-xs:.66rem;
  /* Elevation */
  --wbs-shadow:0 4px 14px rgba(0,0,0,.35);
}
</style>
