/**
 * Shell JS — PRG first. This file only enhances forms marked data-ajax.
 * CSP: script-src 'self'. No inline handlers.
 */
(() => {
  const shellMenu = (() => {
    const checkbox = document.getElementById('wbs-menu-toggle');
    const menu = document.getElementById('wbs-menu');
    const toggle = document.querySelector('[data-shell-menu-toggle]');
    if (!(checkbox instanceof HTMLInputElement) || !(menu instanceof HTMLElement)) return null;
    const media = window.matchMedia('(min-width: 1024px)');
    const key = 'wbs.shell.menuCollapsed.v1';
    const read = () => {
      try {
        return window.localStorage.getItem(key);
      } catch {
        return null;
      }
    };
    const write = (value) => {
      try {
        window.localStorage.setItem(key, value);
      } catch {
        /* storage blocked; non-persistent is acceptable */
      }
    };
    const setDesktopCollapsed = (collapsed) => {
      document.body.classList.toggle('wbs-shell--menu-collapsed', collapsed);
      write(collapsed ? '1' : '0');
    };
    const firstFocusableInMenu = () => menu.querySelector('a, button, [tabindex]:not([tabindex="-1"])');
    const updateA11y = () => {
      if (!(toggle instanceof HTMLElement)) return;
      const expanded = media.matches
        ? !document.body.classList.contains('wbs-shell--menu-collapsed')
        : checkbox.checked;
      toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    };
    const openMobile = () => {
      checkbox.checked = true;
      updateA11y();
      const first = firstFocusableInMenu();
      if (first instanceof HTMLElement) first.focus({ preventScroll: true });
    };
    const closeMobile = () => {
      checkbox.checked = false;
      updateA11y();
      if (toggle instanceof HTMLElement) toggle.focus({ preventScroll: true });
    };
    const syncMode = () => {
      if (media.matches) {
        checkbox.checked = false;
        setDesktopCollapsed(read() === '1');
      } else {
        document.body.classList.remove('wbs-shell--menu-collapsed');
      }
      updateA11y();
    };

    if (toggle instanceof HTMLElement) {
      toggle.hidden = false;
      toggle.addEventListener('click', () => {
        if (media.matches) {
          setDesktopCollapsed(!document.body.classList.contains('wbs-shell--menu-collapsed'));
          updateA11y();
          return;
        }
        if (checkbox.checked) closeMobile();
        else openMobile();
      });
    }

    checkbox.addEventListener('change', updateA11y);
    menu.addEventListener('click', (e) => {
      if (!media.matches && e.target instanceof HTMLElement && e.target.closest('a[href]')) {
        closeMobile();
      }
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !media.matches && checkbox.checked) {
        closeMobile();
      }
    });
    if (typeof media.addEventListener === 'function') {
      media.addEventListener('change', syncMode);
    } else if (typeof media.addListener === 'function') {
      media.addListener(syncMode);
    }
    syncMode();
    return { closeMobile };
  })();

  const csrfCookie = () => {
    const m = document.cookie.match(/(?:^|; )wbs_csrf=([^;]*)/);
    return m ? decodeURIComponent(m[1]) : '';
  };

  const swap = (el, html) => {
    if (!el) return;
    const t = document.createElement('template');
    t.innerHTML = html.trim();
    el.replaceWith(t.content);
  };

  document.addEventListener('submit', async (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-ajax')) return;
    if (form.method && form.method.toLowerCase() === 'get') return;
    e.preventDefault();
    const action = form.getAttribute('action') || window.location.pathname;
    const body = new FormData(form);
    if (!body.has('_csrf')) {
      const t = csrfCookie();
      if (t) body.append('_csrf', t);
    }
    try {
      const res = await fetch(action, {
        method: (form.getAttribute('method') || 'post').toUpperCase(),
        body,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': csrfCookie(),
        },
      });
      const targetSel = form.getAttribute('data-ajax-target');
      const target = targetSel ? document.querySelector(targetSel) : null;
      const ct = res.headers.get('content-type') || '';
      if (ct.includes('application/json')) {
        const data = await res.json();
        if (data.redirect) {
          window.location.assign(data.redirect);
          return;
        }
        if (target && data.html) {
          swap(target, data.html);
          return;
        }
      }
      if (!res.ok) {
        form.submit();
        return;
      }
      if (shellMenu) shellMenu.closeMobile();
      window.location.reload();
    } catch {
      form.removeAttribute('data-ajax');
      form.submit();
    }
  });
})();
