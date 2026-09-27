/**
 * Shell JS — PRG first. This file only enhances forms marked data-ajax.
 * CSP: script-src 'self'. No inline handlers.
 */
(() => {
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
      window.location.reload();
    } catch {
      form.removeAttribute('data-ajax');
      form.submit();
    }
  });
})();
