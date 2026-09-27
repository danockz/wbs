/*
 * FUNCTIONAL test for public/assets/js/table-enhance.js — executes the REAL
 * script against a tiny hand-rolled DOM shim (no jsdom, no npm install, so it
 * runs in the sandbox and CI with only `node`). Asserts behaviour, not markup:
 * column sort (num + text), live filter, CSV export (visible rows only, actions
 * column excluded), and copy-to-clipboard.
 *
 *   node app/Modules/Shared/Navigation/tests/table_enhance_functional.mjs
 *
 * Exits non-zero on any failure and prints "== N passed, M failed ==" so the
 * PHP wrapper (table_enhance_functional_test.php) can fold it into the suite.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SCRIPT = path.resolve(__dirname, '../../../../../public/assets/js/table-enhance.js');

let pass = 0, fail = 0;
function chk(label, ok, detail = '') {
  console.log((ok ? '  ok   ' : '  FAIL ') + label + (ok ? '' : ' — ' + detail));
  ok ? pass++ : fail++;
}

/* ---------------------------------------------------------------- DOM shim */
class ClassList {
  constructor(el) { this.el = el; this.set = new Set(); }
  add(...c) { c.forEach((x) => this.set.add(x)); }
  remove(...c) { c.forEach((x) => this.set.delete(x)); }
  contains(c) { return this.set.has(c); }
}
class El {
  constructor(tag) {
    this.tagName = (tag || 'div').toUpperCase();
    this.children = [];
    this.parent = null;
    this.attrs = {};
    this.listeners = {};
    this.classList = new ClassList(this);
    this.style = {};
    this._text = '';
    this.value = '';
    this.type = '';
    this.disabled = false;
    this.placeholder = '';
    this.href = '';
    this.download = '';
  }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attrs, k) ? this.attrs[k] : null; }
  hasAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attrs, k); }
  appendChild(node) {
    if (node.parent) { node.parent.removeChild(node); }
    node.parent = this;
    this.children.push(node);
    return node;
  }
  insertBefore(node, ref) {
    if (node.parent) { node.parent.removeChild(node); }
    node.parent = this;
    const i = ref ? this.children.indexOf(ref) : -1;
    if (i < 0) { this.children.push(node); } else { this.children.splice(i, 0, node); }
    return node;
  }
  removeChild(node) {
    const i = this.children.indexOf(node);
    if (i >= 0) { this.children.splice(i, 1); node.parent = null; }
    return node;
  }
  get firstChild() { return this.children[0] || null; }
  addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); }
  dispatch(type, ev = {}) { (this.listeners[type] || []).forEach((fn) => fn(ev)); }
  click() { this.dispatch('click', {}); }
  querySelector(sel) { return this._find(sel, false)[0] || null; }
  querySelectorAll(sel) { return this._find(sel, true); }
  _match(sel) {
    if (sel.startsWith('[')) {
      const m = sel.match(/^\[([^\]=]+)(?:="([^"]*)")?\]$/);
      if (!m) { return false; }
      const v = this.getAttribute(m[1]);
      return m[2] === undefined ? v !== null : v === m[2];
    }
    return this.tagName === sel.toUpperCase();
  }
  _find(sel) {
    let out = [];
    for (const c of this.children) {
      if (c._match(sel)) { out.push(c); }
      out = out.concat(c._find(sel));
    }
    return out;
  }
  // table-ish helpers
  get rows() { return this.children.filter((c) => c.tagName === 'TR'); }
  get cells() { return this.children.filter((c) => c.tagName === 'TD' || c.tagName === 'TH'); }
  get tHead() { return this.children.find((c) => c.tagName === 'THEAD') || null; }
  get tBodies() { return this.children.filter((c) => c.tagName === 'TBODY'); }
  get textContent() {
    if (this.children.length === 0) { return this._text; }
    return this.children.map((c) => c.textContent).join('');
  }
  set textContent(v) { this._text = String(v); this.children = []; }
  select() { /* no-op */ }
}

function mkTable(headers, rows, opts = {}) {
  // headers: [{label, sort}]   rows: [[{text, sortValue}]]
  const table = new El('table');
  const thead = new El('thead');
  const htr = new El('tr');
  headers.forEach((h) => {
    const th = new El('th');
    th.textContent = h.label;
    if (h.sort) { th.setAttribute('data-sort', h.sort); }
    if (h.noExport) { th.setAttribute('data-no-export', ''); }
    htr.appendChild(th);
  });
  thead.appendChild(htr);
  table.appendChild(thead);
  const tbody = new El('tbody');
  rows.forEach((r) => {
    const tr = new El('tr');
    r.forEach((c) => {
      const td = new El('td');
      td.textContent = c.text;
      if (c.sortValue !== undefined) { td.setAttribute('data-sort-value', String(c.sortValue)); }
      tr.appendChild(td);
    });
    tbody.appendChild(tr);
  });
  table.appendChild(tbody);

  const container = new El('div');
  container.setAttribute('data-enhance', 'table');
  container.setAttribute('data-filter-min', String(opts.filterMin ?? 2));
  container.setAttribute('data-filter-label', 'Filter');
  if (opts.export !== false) { container.setAttribute('data-export', 'Export CSV'); container.setAttribute('data-export-name', 'demo'); }
  if (opts.copy !== false) { container.setAttribute('data-copy', 'Copy'); container.setAttribute('data-copy-done', 'Copied'); }
  if (opts.columns !== false) { container.setAttribute('data-columns', 'Columns'); }
  if (opts.density !== false) { container.setAttribute('data-density', 'Compact'); }
  container.appendChild(table);
  return { container, table, tbody };
}

/* ------------------------------------------------------------ environment */
const root = new El('body');
const captured = { download: null, clipboard: null };

const document = {
  readyState: 'loading',
  _domReady: [],
  addEventListener(type, fn) { if (type === 'DOMContentLoaded') { this._domReady.push(fn); } },
  createElement(tag) { return new El(tag); },
  querySelectorAll(sel) { return root.querySelectorAll(sel); },
  body: root,
  execCommand() { captured.clipboard = '(execCommand)'; return true; },
};
const navigator = {
  clipboard: { writeText(t) { captured.clipboard = t; return Promise.resolve(); } },
};
class Blob { constructor(parts) { this.parts = parts; this._text = parts.join(''); } }
const URL = { createObjectURL(b) { captured.download = b._text; return 'blob:x'; }, revokeObjectURL() {} };
const window = { location: { search: '' } };
const timers = [];
const setTimeout = (fn) => { timers.push(fn); return 0; };
function flushTimers() { while (timers.length) { timers.shift()(); } }

/* --------------------------------------------------------- run the script */
const code = fs.readFileSync(SCRIPT, 'utf8');
const runner = new Function(
  'document', 'navigator', 'Blob', 'URL', 'window', 'setTimeout', 'URLSearchParams',
  code + '\n//# functional-harness',
);
runner(document, navigator, Blob, URL, window, setTimeout, URLSearchParams);

/* ----------------------------------------------------------------- tests */
// A leaderboard-like table: rank(num), name(text), score(num).
const { container, table, tbody } = mkTable(
  [
    { label: 'Rank', sort: 'num' },
    { label: 'Name', sort: 'text' },
    { label: 'Score', sort: 'num' },
    { label: 'Actions', noExport: true },
  ],
  [
    [{ text: '2', sortValue: 2 }, { text: 'Kofi', sortValue: 'Kofi' }, { text: '280', sortValue: 280 }, { text: 'x' }],
    [{ text: '1', sortValue: 1 }, { text: 'Ama', sortValue: 'Ama' }, { text: '320', sortValue: 320 }, { text: 'x' }],
    [{ text: '3', sortValue: 3 }, { text: 'Zoe', sortValue: 'Zoe' }, { text: '210', sortValue: 210 }, { text: 'x' }],
  ],
  { filterMin: 2 },
);
root.appendChild(container);
document._domReady.forEach((fn) => fn()); // fire DOMContentLoaded → enhance()

const headThs = container.querySelector('thead').querySelector('tr').cells;
const names = () => tbody.rows.map((r) => r.cells[1].textContent);

// 1. sort by score (col 2) ascending then descending
headThs[2].click();
chk('sort score asc → 210,280,320', JSON.stringify(tbody.rows.map((r) => r.cells[2].textContent)) === '["210","280","320"]', names().join(','));
headThs[2].click();
chk('sort score desc → 320,280,210', JSON.stringify(tbody.rows.map((r) => r.cells[2].textContent)) === '["320","280","210"]', names().join(','));

// 2. sort by name (text) ascending
headThs[1].click();
chk('sort name asc → Ama,Kofi,Zoe', JSON.stringify(names()) === '["Ama","Kofi","Zoe"]', names().join(','));

// 3. keyboard sort on rank via Enter
headThs[0].dispatch('keydown', { key: 'Enter', preventDefault() {} });
chk('keyboard sort rank asc → 1,2,3', JSON.stringify(tbody.rows.map((r) => r.cells[0].textContent)) === '["1","2","3"]');

// 4. aria-sort reflects state
chk('aria-sort set on active header', headThs[0].getAttribute('aria-sort') === 'ascending');
chk('other headers reset to none', headThs[1].getAttribute('aria-sort') === 'none');

// 5. filter box appears (rows ≥ filterMin) and narrows rows
const filterInput = container.querySelector('[class]') && null; // find by attr instead
const inputEl = container._find('input').find((e) => e.type === 'search');
chk('filter input rendered', !!inputEl);
inputEl.value = 'kofi';
inputEl.dispatch('input', {});
const visible = () => tbody.rows.filter((r) => r.style.display !== 'none');
chk('filter narrows to matching row', visible().length === 1 && visible()[0].cells[1].textContent === 'Kofi');

// 6. CSV export respects filter (only visible) + excludes actions column
const buttons = container._find('button');
const exportBtn = buttons.find((b) => b.textContent === 'Export CSV');
chk('export button rendered', !!exportBtn);
exportBtn.click();
// strip the UTF-8 BOM the Blob prepends before comparing the header row
const csvHeader = () => captured.download.replace(/^\uFEFF/, '').split('\r\n')[0];
chk('CSV excludes Actions column header', captured.download && csvHeader() === 'Rank,Name,Score');
chk('CSV exports only the filtered (visible) row', captured.download && captured.download.trim().split('\r\n').length === 2);
chk('CSV row uses raw sort-value data', captured.download && captured.download.includes('2,Kofi,280'));

// 7. clear filter → export all rows again
inputEl.value = '';
inputEl.dispatch('input', {});
exportBtn.click();
chk('CSV after clearing filter exports all 3 rows', captured.download && captured.download.trim().split('\r\n').length === 4);

// 8. copy-to-clipboard
const copyBtn = buttons.find((b) => b.textContent === 'Copy' || b.textContent === 'Copied');
chk('copy button rendered', !!copyBtn);
copyBtn.click();
chk('clipboard received table text', typeof captured.clipboard === 'string' && captured.clipboard.includes('Kofi'));
flushTimers();

// 9. tiny table (1 row) still gets a toolbar but no sort errors
captured.download = null;
const solo = mkTable(
  [{ label: 'Rank', sort: 'num' }, { label: 'Name', sort: 'text' }],
  [[{ text: '1', sortValue: 1 }, { text: 'Solo', sortValue: 'Solo' }]],
  { filterMin: 5 },
);
root.appendChild(solo.container);
document._domReady.forEach((fn) => fn());
const soloExport = solo.container._find('button').find((b) => b.textContent === 'Export CSV');
chk('single-row table still exportable', !!soloExport);
soloExport.click();
chk('single-row CSV has header + 1 row', captured.download && captured.download.trim().split('\r\n').length === 2);

// 10. column-visibility toggle hides a whole column (header + body cells)
const colBtns = container._find('button');
const colToggle = colBtns.find((b) => (b.textContent || '').indexOf('Columns') === 0);
chk('columns menu button rendered', !!colToggle);
colToggle.click(); // open panel
const checks = container._find('input').filter((e) => e.type === 'checkbox');
chk('one checkbox per data column (3, actions excluded)', checks.length === 3, String(checks.length));
// uncheck the "Score" column (index 2 → third checkbox)
const scoreCb = checks[2];
scoreCb.checked = false;
scoreCb.dispatch('change', {});
const head0 = container.querySelector('thead').querySelector('tr');
chk('score header hidden after uncheck', head0.cells[2].style.display === 'none');
chk('score body cells hidden after uncheck', tbody.rows[0].cells[2].style.display === 'none');
chk('other columns remain visible', head0.cells[1].style.display !== 'none');
// re-check restores it
scoreCb.checked = true;
scoreCb.dispatch('change', {});
chk('score column restored after re-check', head0.cells[2].style.display !== 'none');

// 11. density toggle flips a class on the table + aria-pressed
const densityBtn = colBtns.find((b) => b.textContent === 'Compact');
chk('density button rendered', !!densityBtn);
densityBtn.click();
chk('compact class applied', table.classList.contains('tbl-compact') && densityBtn.getAttribute('aria-pressed') === 'true');
densityBtn.click();
chk('compact class removed on second click', !table.classList.contains('tbl-compact') && densityBtn.getAttribute('aria-pressed') === 'false');

// 12. idempotency: re-running enhance() on the same container adds nothing
const toolbarsBefore = container._find('div').filter((d) => d.className === 'tbl-actions').length;
document._domReady.forEach((fn) => fn()); // fire again
const toolbarsAfter = container._find('div').filter((d) => d.className === 'tbl-actions').length;
chk('enhance is idempotent (no duplicate toolbar)', toolbarsBefore === 1 && toolbarsAfter === 1, toolbarsBefore + '→' + toolbarsAfter);

console.log(`\n== ${pass} passed, ${fail} failed ==`);
process.exit(fail > 0 ? 1 : 0);
