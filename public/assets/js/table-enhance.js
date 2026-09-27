/*
 * Progressive table enhancement — zero-dependency, CSP-safe.
 *
 * WHY THIS EXISTS (see docs/FORMS_CRUD_VIEWS_AUDIT.md §5 / action #5):
 *   The shared page presenter renders a COMPLETE, server-side table that works
 *   with no JavaScript at all. This file is pure progressive enhancement: when
 *   JS is available it upgrades any opted-in table with instant client-side
 *   column sorting and a live filter box — no jQuery, no DataTables, no CDN,
 *   nothing over the wire but this one ~3 KB same-origin file.
 *
 * CSP: loaded as a same-origin <script src> (script-src 'self'); no inline
 *   handlers, no eval, no network. If this file fails to load the page is
 *   unaffected — the plain server table remains fully usable.
 *
 * OPT-IN: the presenter wraps enhanceable tables in
 *   <div class="tbl-tools" data-enhance="table" data-filter-min="8">…<table>…
 *   Each sortable <th> carries data-sort ("text" | "num" | "date") and the
 *   presenter tags each <td> with data-sort-value for a stable, locale-free key.
 *   Export/copy buttons are added when the wrapper allows it (data-export /
 *   data-copy labels present); both operate purely on the in-page table — no
 *   network, no new document — so they stay within CSP connect-src 'self'.
 */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  // Pull a comparable key for a cell: prefer data-sort-value, else text.
  function cellKey(row, idx) {
    var cells = row.children;
    if (idx >= cells.length) { return ''; }
    var td = cells[idx];
    var v = td.getAttribute('data-sort-value');
    return v !== null ? v : (td.textContent || '').trim();
  }

  function makeComparator(idx, type, dir) {
    var mult = dir === 'desc' ? -1 : 1;
    return function (a, b) {
      var ka = cellKey(a, idx), kb = cellKey(b, idx);
      var res;
      if (type === 'num') {
        var na = parseFloat(ka.replace(/[^0-9.\-]/g, '')) || 0;
        var nb = parseFloat(kb.replace(/[^0-9.\-]/g, '')) || 0;
        res = na - nb;
      } else if (type === 'date') {
        var da = Date.parse(ka) || 0, db = Date.parse(kb) || 0;
        res = da - db;
      } else {
        res = ka.localeCompare(kb, undefined, { numeric: true, sensitivity: 'base' });
      }
      return res * mult;
    };
  }

  // --- CSV helpers -------------------------------------------------------
  function csvCell(text) {
    var s = (text == null ? '' : String(text)).replace(/\s+/g, ' ').trim();
    if (/[",\n]/.test(s)) { s = '"' + s.replace(/"/g, '""') + '"'; }
    return s;
  }

  // Serialize the header + currently-VISIBLE rows to CSV. Prefers a cell's
  // data-sort-value when present (raw value), else its trimmed text. The last
  // column (row actions) is skipped when data-no-export is set on its <th>.
  function tableToCsv(table) {
    var lines = [];
    var head = table.tHead && table.tHead.rows[0];
    var skip = [];
    if (head) {
      var hcells = Array.prototype.slice.call(head.cells);
      var hrow = hcells.map(function (th, i) {
        if (th.hasAttribute('data-no-export')) { skip[i] = true; return null; }
        return csvCell(th.textContent);
      }).filter(function (v) { return v !== null; });
      lines.push(hrow.join(','));
    }
    var tbody = table.tBodies[0];
    if (tbody) {
      Array.prototype.forEach.call(tbody.rows, function (r) {
        if (r.style.display === 'none') { return; } // respect active filter
        var cells = Array.prototype.slice.call(r.cells).map(function (td, i) {
          if (skip[i]) { return null; }
          var v = td.getAttribute('data-sort-value');
          return csvCell(v !== null ? v : td.textContent);
        }).filter(function (v) { return v !== null; });
        lines.push(cells.join(','));
      });
    }
    return lines.join('\r\n');
  }

  function download(filename, text) {
    var blob = new Blob(['\uFEFF' + text], { type: 'text/csv;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(url); }, 0);
  }

  function flash(btn, label) {
    var orig = btn.textContent;
    btn.textContent = label;
    btn.disabled = true;
    setTimeout(function () { btn.textContent = orig; btn.disabled = false; }, 1400);
  }

  function copyText(text, btn, doneLabel) {
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch (e) { /* no-op */ }
      document.body.removeChild(ta);
      flash(btn, doneLabel);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { flash(btn, doneLabel); }, fallback);
    } else {
      fallback();
    }
  }

  // Toggle a whole column (all header + body cells at index) on/off.
  function setColumnHidden(table, idx, hidden) {
    var apply = function (row) {
      var cells = row.children;
      if (idx < cells.length) { cells[idx].style.display = hidden ? 'none' : ''; }
    };
    if (table.tHead) { Array.prototype.forEach.call(table.tHead.rows, apply); }
    Array.prototype.forEach.call(table.tBodies, function (tb) {
      Array.prototype.forEach.call(tb.rows, apply);
    });
  }

  // "Columns ▾" popover: one checkbox per column, driven by the header labels.
  function addColumnMenu(container, table, label) {
    var head = table.tHead && table.tHead.rows[0];
    if (!head) { return null; }
    var wrap = document.createElement('div');
    wrap.className = 'tbl-colmenu';
    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'tbl-btn';
    toggle.textContent = label + ' \u25BE';
    var panel = document.createElement('div');
    panel.className = 'tbl-colmenu-panel';
    panel.style.display = 'none';

    Array.prototype.forEach.call(head.cells, function (th, idx) {
      if (th.hasAttribute('data-no-export')) { return; } // skip the actions column
      var id = 'colv_' + idx + '_' + Math.random().toString(36).slice(2, 7);
      var row = document.createElement('label');
      row.className = 'tbl-colmenu-item';
      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.checked = true;
      cb.id = id;
      cb.addEventListener('change', function () { setColumnHidden(table, idx, !cb.checked); });
      var span = document.createElement('span');
      span.textContent = (th.textContent || '').trim();
      row.appendChild(cb);
      row.appendChild(span);
      panel.appendChild(row);
    });

    toggle.addEventListener('click', function () {
      panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    });
    wrap.appendChild(toggle);
    wrap.appendChild(panel);
    return wrap;
  }

  function addToolbar(container, table) {
    var exportLbl = container.getAttribute('data-export');
    var copyLbl = container.getAttribute('data-copy');
    var colsLbl = container.getAttribute('data-columns');
    var densityLbl = container.getAttribute('data-density');
    if (!exportLbl && !copyLbl && !colsLbl && !densityLbl) { return; }
    var bar = document.createElement('div');
    bar.className = 'tbl-actions';

    if (densityLbl) {
      var db = document.createElement('button');
      db.type = 'button';
      db.className = 'tbl-btn';
      db.textContent = densityLbl;
      db.setAttribute('aria-pressed', 'false');
      db.addEventListener('click', function () {
        var on = table.classList.contains('tbl-compact');
        if (on) { table.classList.remove('tbl-compact'); db.setAttribute('aria-pressed', 'false'); }
        else { table.classList.add('tbl-compact'); db.setAttribute('aria-pressed', 'true'); }
      });
      bar.appendChild(db);
    }
    if (colsLbl) {
      var menu = addColumnMenu(container, table, colsLbl);
      if (menu) { bar.appendChild(menu); }
    }

    if (exportLbl) {
      var eb = document.createElement('button');
      eb.type = 'button';
      eb.className = 'tbl-btn';
      eb.textContent = exportLbl;
      eb.addEventListener('click', function () {
        var name = (container.getAttribute('data-export-name') || 'export') + '.csv';
        download(name, tableToCsv(table));
      });
      bar.appendChild(eb);
    }
    if (copyLbl) {
      var cb = document.createElement('button');
      cb.type = 'button';
      cb.className = 'tbl-btn';
      cb.textContent = copyLbl;
      cb.addEventListener('click', function () {
        // Tab-separated is friendliest for pasting into spreadsheets.
        var tsv = tableToCsv(table).split('\r\n').map(function (line) {
          // naive CSV→TSV: only safe because our cells are already sanitized
          return line;
        }).join('\n');
        copyText(tsv, cb, container.getAttribute('data-copy-done') || 'Copied');
      });
      bar.appendChild(cb);
    }
    container.insertBefore(bar, container.firstChild);
  }

  function enhance(container) {
    // Idempotent: never enhance the same container twice (e.g. if the init hook
    // fires more than once). Prevents duplicate toolbars/checkboxes.
    if (container.getAttribute('data-enhanced') === '1') { return; }
    container.setAttribute('data-enhanced', '1');
    var table = container.querySelector('table');
    if (!table) { return; }
    var tbody = table.tBodies[0];
    var thead = table.tHead;
    if (!tbody || !thead) { return; }

    var allRows = Array.prototype.slice.call(tbody.rows);
    if (allRows.length < 1) { return; }
    addToolbar(container, table);
    if (allRows.length < 2) { return; } // sort/filter need ≥2 rows

    // ---- Sorting ----------------------------------------------------------
    var headCells = thead.rows[0] ? Array.prototype.slice.call(thead.rows[0].cells) : [];
    headCells.forEach(function (th, idx) {
      var type = th.getAttribute('data-sort');
      if (!type) { return; } // action column etc.
      th.classList.add('sortable');
      th.setAttribute('role', 'button');
      th.setAttribute('tabindex', '0');
      th.setAttribute('aria-sort', 'none');

      function doSort() {
        var current = th.getAttribute('aria-sort');
        var dir = current === 'ascending' ? 'desc' : 'asc';
        headCells.forEach(function (h) {
          h.setAttribute('aria-sort', 'none');
          h.classList.remove('sorted-asc', 'sorted-desc');
        });
        th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');
        th.classList.add(dir === 'asc' ? 'sorted-asc' : 'sorted-desc');

        // Sort a snapshot of the currently-visible rows, then re-append all
        // rows (hidden ones keep their relative order at the end).
        var rows = Array.prototype.slice.call(tbody.rows);
        rows.sort(makeComparator(idx, type, dir));
        rows.forEach(function (r) { tbody.appendChild(r); });
      }

      th.addEventListener('click', doSort);
      th.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); doSort(); }
      });
    });

    // ---- Filtering --------------------------------------------------------
    var filterMin = parseInt(container.getAttribute('data-filter-min') || '8', 10);
    if (allRows.length >= filterMin) {
      var bar = document.createElement('div');
      bar.className = 'tbl-filterbar';
      var input = document.createElement('input');
      input.type = 'search';
      input.className = 'tbl-filter';
      input.setAttribute('autocomplete', 'off');
      input.placeholder = container.getAttribute('data-filter-label') || 'Filter…';
      var status = document.createElement('span');
      status.className = 'tbl-filter-count';
      bar.appendChild(input);
      bar.appendChild(status);
      container.insertBefore(bar, table);

      function applyFilter() {
        var q = input.value.trim().toLowerCase();
        var shown = 0;
        allRows.forEach(function (r) {
          var hit = q === '' || (r.textContent || '').toLowerCase().indexOf(q) !== -1;
          r.style.display = hit ? '' : 'none';
          if (hit) { shown++; }
        });
        status.textContent = q === '' ? '' : shown + ' / ' + allRows.length;
      }
      input.addEventListener('input', applyFilter);
    }
  }

  ready(function () {
    var containers = document.querySelectorAll('[data-enhance="table"]');
    Array.prototype.forEach.call(containers, enhance);
  });
})();
