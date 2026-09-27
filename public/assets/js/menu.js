/*
 * Universal dynamic menu — zero-server-burden client renderer.
 *
 * WHY THIS EXISTS (see docs/DYNAMIC-MENU-EDGE.md):
 *   The menu is delivered as ONE cacheable resource, not re-rendered inside every
 *   page. This script:
 *     1. renders instantly from localStorage (no network, no flash of empty nav);
 *     2. revalidates GET /me/menu with If-None-Match + the signed capability word;
 *     3. the origin/edge answers 304 (nothing to render) until an authority version
 *        moves, so between changes the server does ~no menu work.
 *
 * CSP: loaded as a same-origin file (script-src 'self'); fetch() is same-origin
 * (connect-src 'self'). No inline handlers, no eval. The menu drives DISPLAY only —
 * route authorize: filters remain the security boundary.
 */
(function () {
  'use strict';

  var MOUNT = document.getElementById('wbs-menu');
  if (!MOUNT) { return; }

  var ENDPOINT = MOUNT.getAttribute('data-endpoint') || '/me/menu';

  // Active scope from ?scope= (falls back to org view). Kept in the cache key so a
  // scope switch renders its own cached tree instantly.
  var params = new URLSearchParams(window.location.search);
  var scope = params.get('scope') || '';

  // Active locale, so a LANGUAGE switch also renders its own cached tree instantly
  // (the labels are localized server-side; without this the instant paint would
  // flash the previous language until revalidation). Read from ?lang= then the
  // wbs_locale cookie (same non-secret carrier the server uses), default 'en'.
  function readLocale() {
    var q = params.get('lang');
    if (q) { return q; }
    var m = document.cookie.match(/(?:^|;\s*)wbs_locale=([^;]+)/);
    if (m) { try { return decodeURIComponent(m[1]); } catch (e) { return m[1]; } }
    var htmlLang = document.documentElement.getAttribute('lang');
    return htmlLang || 'en';
  }
  var locale = readLocale();

  // Cache key is per (scope, locale): each combination revalidates and paints its
  // own tree; a scope OR language switch never shows the wrong cached menu.
  var CACHE_KEY = 'wbs.menu.' + (scope || 'org') + '.' + locale;

  var activePath = window.location.pathname.replace(/^\/+|\/+$/g, '');

  // -- tiny inline-SVG icon set (mirrors _menu_nav.php so SSR/CSR look identical) --
  var ICONS = {
    'gauge': '<circle cx="12" cy="12" r="9"/><path d="M12 12l4-2"/>',
    'address-book': '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6"/>',
    'shield': '<path d="M12 3l7 3v6c0 4-3 7-7 8-4-1-7-4-7-8V6z"/>',
    'users': '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0112 0"/><path d="M16 11a3 3 0 000-6"/>',
    'user-plus': '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0112 0"/><path d="M19 8v6M22 11h-6"/>',
    'map-pin': '<path d="M12 21s7-6 7-11a7 7 0 10-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
    'calendar': '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 9h18M8 3v4M16 3v4"/>',
    'calendar-plus': '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 9h18M8 3v4M16 3v4M12 13v4M10 15h4"/>',
    'graduation-cap': '<path d="M12 4l10 5-10 5L2 9z"/><path d="M6 11v5c0 1 3 3 6 3s6-2 6-3v-5"/>',
    'chart-bar': '<path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/>',
    'key': '<circle cx="8" cy="12" r="4"/><path d="M12 12h9l-2 2 2 2"/>',
    'cog': '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>',
    'route': '<circle cx="6" cy="19" r="2.5"/><circle cx="18" cy="5" r="2.5"/><path d="M8 19h7a3 3 0 003-3V8"/>',
    'hand-heart': '<path d="M11 8.5c-1-1.6-3.4-1-3.4 1 0 1.6 3.4 3.5 3.4 3.5s3.4-1.9 3.4-3.5c0-2-2.4-2.6-3.4-1z"/><path d="M3 14l4 4 8 1 6-3"/>',
    'message-square': '<path d="M4 5h16v11H8l-4 4z"/>',
    'video': '<rect x="3" y="6" width="12" height="12" rx="2"/><path d="M15 10l6-3v10l-6-3z"/>',
    'trophy': '<path d="M7 4h10v4a5 5 0 01-10 0zM5 6H3v2a3 3 0 003 3M19 6h2v2a3 3 0 01-3 3M9 17h6M12 13v4M8 21h8"/>',
    'inbox': '<path d="M3 13h5l1 3h6l1-3h5"/><path d="M4 13l2-8h12l2 8v6H4z"/>',
    'git-branch': '<circle cx="6" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="8" r="2.5"/><path d="M6 8.5v7M6 15a9 9 0 019-9h1"/>',
    'list': '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    'award': '<circle cx="12" cy="9" r="5"/><path d="M9 13l-2 8 5-3 5 3-2-8"/>',
    'shuffle': '<path d="M3 6h4l10 12h4M3 18h4l3-4M17 4l4 2-4 2M17 16l4 2-4 2"/>',
    'layers': '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
    'book': '<path d="M4 5a2 2 0 012-2h13v16H6a2 2 0 00-2 2z"/><path d="M4 19V5"/>',
    'flag': '<path d="M5 21V4h11l-1.5 3L16 10H5"/>',
    'folder-plus': '<path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><path d="M12 11v4M10 13h4"/>',
    'move': '<path d="M12 2v20M2 12h20M8 6l4-4 4 4M8 18l4 4 4-4M6 8l-4 4 4 4M18 8l4 4-4 4"/>',
    'qr-code': '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3M20 14v.01M14 20v.01M20 20v.01M17 17h.01"/>',
    'receipt': '<path d="M5 3v18l2-1 2 1 2-1 2 1 2-1 2 1V3l-2 1-2-1-2 1-2-1-2 1z"/><path d="M8 8h8M8 12h8"/>',
    'sliders': '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
    'download': '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>',
    'plug': '<path d="M9 2v6M15 2v6M7 8h10v3a5 5 0 01-10 0zM12 16v6"/>',
    'alert-triangle': '<path d="M12 3l10 18H2z"/><path d="M12 10v4M12 18v.01"/>'
  };

  function svg(name) {
    var inner = ICONS[name] || '<circle cx="12" cy="12" r="2.5"/>';
    return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" ' +
      'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      inner + '</svg>';
  }

  function el(tag, cls) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    return e;
  }

  // Build the DOM from the same tree shape MenuService::render() emits:
  // { categories: [ { key, label, items: [ {id,label,route,icon,badge} ] } ], count }
  function render(tree) {
    if (!tree || !tree.categories || tree.count === 0) {
      MOUNT.textContent = '';
      return;
    }
    var frag = document.createDocumentFragment();

    tree.categories.forEach(function (cat) {
      var group = el('div', 'wbs-menu__group');
      var head = el('span', 'wbs-menu__cat');
      head.textContent = cat.label;
      group.appendChild(head);

      (cat.items || []).forEach(function (it) {
        var route = String(it.route || '').replace(/^\/+|\/+$/g, '');
        var a = el('a', 'wbs-menu__link');
        a.setAttribute('href', '/' + route);
        a.setAttribute('data-item', it.id || '');
        if (route !== '' && route === activePath) {
          a.setAttribute('aria-current', 'page');
        }
        // icon (built from a fixed allowlist, not user input)
        var iconWrap = el('span', 'wbs-menu__icon');
        iconWrap.innerHTML = svg(it.icon);
        a.appendChild(iconWrap);

        var label = el('span');
        label.textContent = it.label || '';   // textContent = XSS-safe
        a.appendChild(label);

        // Badge placeholder (hidden until fetchBadges() fills it). The provider-id
        // is static catalog data, safe as an attribute; the count comes later from
        // the separate /me/menu/badges call.
        if (it.badge) {
          var badge = el('span', 'wbs-menu__badge');
          badge.setAttribute('data-badge', String(it.badge));
          badge.hidden = true;
          a.appendChild(badge);
        }

        group.appendChild(a);
      });

      frag.appendChild(group);
    });

    MOUNT.textContent = '';
    MOUNT.appendChild(frag);
  }

  // Lazy BADGE counts (docs/DYNAMIC-MENU-DESIGN.md §6/§8.5): fetched SEPARATELY
  // from GET /me/menu/badges after paint so the volatile numbers never touch the
  // cached/304-able menu tree. Fills the `.wbs-menu__badge[data-badge]` spans that
  // SSR emits (and that render() below reproduces for CSR). Best-effort: a failure
  // just leaves the pills hidden — the menu itself is unaffected.
  var BADGES_ENDPOINT = MOUNT.getAttribute('data-badges-endpoint') || '/me/menu/badges';

  function applyBadges(counts) {
    if (!counts) { return; }
    var spans = MOUNT.querySelectorAll('.wbs-menu__badge[data-badge]');
    for (var i = 0; i < spans.length; i++) {
      var span = spans[i];
      var id = span.getAttribute('data-badge');
      var n = counts[id];
      if (typeof n === 'number' && n > 0) {
        span.textContent = n > 99 ? '99+' : String(n);
        span.hidden = false;
      } else {
        span.textContent = '';
        span.hidden = true;
      }
    }
  }

  function fetchBadges() {
    var url = BADGES_ENDPOINT + (scope ? ('?scope=' + encodeURIComponent(scope)) : '');
    fetch(url, {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    }).then(function (res) {
      return res.ok ? res.json() : null;
    }).then(function (data) {
      if (data && data.badges) { applyBadges(data.badges); }
    }).catch(function () { /* offline / preview — pills stay hidden */ });
  }

  // 1) Instant paint from cache (zero network).
  var cached = null;
  try { cached = JSON.parse(localStorage.getItem(CACHE_KEY) || 'null'); } catch (e) { cached = null; }
  if (cached && cached.tree) { render(cached.tree); }

  // 2) Revalidate. Send If-None-Match + the signed word so the origin can take the
  //    fast path (verify word, skip DB) or answer 304 (no render at all).
  var headers = { 'Accept': 'application/json' };
  if (cached && cached.etag) { headers['If-None-Match'] = cached.etag; }
  if (cached && cached.signedWord) { headers['X-Menu-Word'] = cached.signedWord; }

  var url = ENDPOINT + (scope ? ('?scope=' + encodeURIComponent(scope)) : '');

  fetch(url, {
    method: 'GET',
    headers: headers,
    credentials: 'same-origin',
    cache: 'no-store'          // we manage our own cache; just want the conditional GET
  }).then(function (res) {
    if (res.status === 304) { return null; }        // unchanged — cached paint stands
    if (!res.ok) { return null; }                   // 401/5xx — keep whatever we have
    return res.json();
  }).then(function (tree) {
    if (!tree) { return; }
    render(tree);
    try {
      localStorage.setItem(CACHE_KEY, JSON.stringify({
        tree: tree,
        etag: tree.etag || null,
        signedWord: tree.signedWord || null
      }));
    } catch (e) { /* storage full/blocked — rendering still worked */ }
    // The tree changed → re-fetch badges so a newly-rendered item gets its pill.
    fetchBadges();
  }).catch(function () {
    /* offline / preview iframe with no network — the cached paint (if any) remains */
  });

  // 3) Fill badge pills after the first paint (SSR spans or the cached CSR paint).
  //    Independent of the menu revalidation above so counts appear even on a 304.
  fetchBadges();
})();
