const token = new URLSearchParams(location.search).get('t') || '';
const $ = (s) => document.querySelector(s);
const editorEl = $('#editor');
const output = $('#output');
const projectSel = $('#project');
const statusEl = $('#status');
const laravelEl = $('#laravel');
const autorunEl = $('#autorun');
const AUTORUN_KEY = 'tinker-web:autorun';
let liveBlock = null;   // the coalesced auto-run block currently at the top
let runSeq = 0;         // sequence guard: only the latest response is rendered
let lastCode = null;    // skip re-running identical code
let debounceTimer = null;

async function api(path, opts = {}) {
  const res = await fetch(path, {
    ...opts,
    headers: { 'X-Token': token, 'Content-Type': 'application/json', ...(opts.headers || {}) },
  });
  return res.json();
}

// --- completion data (fetched once per project, cached) ---
let classList = [];
const memberCache = new Map();
const KEYWORDS = ['return','function','fn','use','new','match','foreach','for','while','if','else','elseif','switch','case','throw','class','static','public','private','protected','instanceof','array','null','true','false'];

async function loadSymbols(project) {
  if (!project) { classList = []; return; }
  try {
    const r = await api('/symbols', { method: 'POST', body: JSON.stringify({ project }) });
    classList = r && r.ok ? r.classes : [];
  } catch (e) { classList = []; }
}

async function loadMembers(project, fqcn) {
  const key = project + '|' + fqcn;
  if (memberCache.has(key)) return memberCache.get(key);
  let members = [];
  try {
    const r = await api('/members', { method: 'POST', body: JSON.stringify({ project, class: fqcn }) });
    members = r && r.ok ? r.members : [];
  } catch (e) { members = []; }
  memberCache.set(key, members);
  return members;
}

function classRank(fqcn) { return fqcn.startsWith('App\\') ? 0 : fqcn.startsWith('Illuminate\\') ? 1 : 2; }

// Resolve `Name`/`\Ns\Name` before `::` to an FQCN using the buffer's use-statements. Null = unresolved.
function resolveClass(name, doc) {
  name = name.replace(/^\\/, '');
  if (name.includes('\\')) return name;
  let m = doc.match(new RegExp('use\\s+([\\w\\\\]*\\\\' + name + ')\\s*;'));
  if (m) return m[1];
  m = doc.match(new RegExp('use\\s+([\\w\\\\]+)\\s+as\\s+' + name + '\\s*;'));
  if (m) return m[1];
  return null;
}

// CM6 completion source: static members after `::`, then $variables, then class names + keywords.
async function phpComplete(context) {
  const doc = context.state.doc.toString();
  const before = doc.slice(0, context.pos);

  const staticM = before.match(/([A-Za-z_\\][\w\\]*)::(\w*)$/);
  if (staticM) {
    const fqcn = resolveClass(staticM[1], doc);
    if (fqcn && projectSel.value) {
      const members = await loadMembers(projectSel.value, fqcn);
      const kindType = { method: 'method', const: 'constant', property: 'property' };
      return { from: context.pos - staticM[2].length, options: members.map((m) => ({ label: m.name, type: kindType[m.kind] || 'text' })) };
    }
    return null;
  }

  const varM = context.matchBefore(/\$[A-Za-z_]\w*/);
  if (varM) {
    const names = [...new Set(doc.match(/\$[A-Za-z_]\w*/g) || [])];
    return { from: varM.from, options: names.map((n) => ({ label: n, type: 'variable' })) };
  }

  const idM = context.matchBefore(/[A-Za-z_\\][\w\\]*/);
  if (idM && idM.text) {
    const q = idM.text.replace(/^\\/, '').toLowerCase();
    const matches = classList.filter((c) => c.toLowerCase().includes(q));
    matches.sort((a, b) => classRank(a) - classRank(b) || a.length - b.length);
    const options = matches.slice(0, 50).map((c) => ({ label: c, type: 'class', apply: '\\' + c }));
    for (const k of KEYWORDS) if (k.startsWith(q)) options.push({ label: k, type: 'keyword' });
    return options.length ? { from: idM.from, options } : null;
  }

  return null;
}

function options(connections) {
  return connections.length
    ? connections.map((p) => `<option value="${escapeAttr(p)}">${escapeHtml(p)}</option>`).join('')
    : '<option value="">(add a project →)</option>';
}

async function loadConnections() {
  const { connections = [] } = await api('/connections');
  projectSel.innerHTML = options(connections);
}

$('#add-btn').onclick = async () => {
  const path = $('#add-path').value.trim();
  if (!path) return;
  const res = await api('/connections', { method: 'POST', body: JSON.stringify({ project: path }) });
  if (res.error) { setStatus('error: ' + res.error.message, true); return; }
  $('#add-path').value = '';
  projectSel.innerHTML = options(res.connections || []);
};

async function run(live = false) {
  const project = projectSel.value;
  if (!project) { if (!live) setStatus('add a project first', true); return; }
  const code = editorApi.getDoc();
  if (live && code.trim() === '') return;

  const seq = ++runSeq;
  lastCode = code;
  if (!live) setStatus('running…');
  const t0 = performance.now();
  let env;
  try {
    env = await api('/eval', { method: 'POST', body: JSON.stringify({ project, code }) });
  } catch (e) {
    if (seq === runSeq) setStatus(live ? '… offline' : 'request failed', !live);
    return;
  }
  if (seq !== runSeq) return; // a newer run superseded this one

  // While typing, suppress half-written snippets instead of flashing errors.
  if (live && env.ok && (env.kind === 'incomplete' || env.kind === 'parse-error')) {
    setStatus('… ' + env.kind);
    return;
  }

  const ms = Math.round(performance.now() - t0);
  const previousLive = liveBlock;
  const block = render(env, ms); // prepends the new block
  if (live) {
    if (previousLive && previousLive.parentNode) previousLive.remove(); // coalesce: drop the old live block
    block.classList.add('live');
    liveBlock = block;
  } else {
    if (liveBlock) liveBlock.classList.remove('live'); // un-tag the prior live block so no stray outline lingers
    liveBlock = null; // a manual run becomes permanent history
  }
}

function render(env, ms) {
  if (env.laravel) laravelEl.textContent = 'Laravel ' + env.laravel;

  const block = document.createElement('div');

  if (!env.ok) {
    // non-user failure (bad project / runner crash)
    block.className = 'run-block err';
    const e = env.error || {};
    block.innerHTML = `<div class="result err"><pre class="error">${escapeHtml((e.class ? e.class + ': ' : '') + (e.message || 'error'))}</pre></div>`;
    setStatus(`error · ${ms}ms`, true);
    return placeBlock(block);
  }

  const cells = env.cells || [];
  let ok = true;
  if (cells.length) {
    block.innerHTML = cells.map((c) => renderCell(c, () => { ok = false; })).join('');
  } else if (env.kind === 'incomplete') {
    block.innerHTML = '<div class="result ok"><pre class="note">… incomplete input</pre></div>';
  } else if (env.kind === 'parse-error') {
    ok = false;
    const e = env.error || {};
    block.innerHTML = `<div class="result err"><pre class="error">${escapeHtml((e.class ? e.class + ': ' : '') + (e.message || 'error'))}</pre></div>`;
  } else {
    block.innerHTML = '<div class="result ok"><pre class="note">✓ (no statements)</pre></div>';
  }
  block.className = 'run-block ' + (ok ? 'ok' : 'err');
  if (env.halted) { block.classList.add('stopped'); setStatus(`stopped · ${ms}ms`); }
  else setStatus(ok ? `ok · ${ms}ms` : `error · ${ms}ms`, !ok);
  return placeBlock(block);
}

function renderCell(c, markErr) {
  let html = '<div class="result ' + (c.kind === 'exception' ? 'err' : 'ok') + '">';
  if (c.output) html += `<div class="out-label">output</div><pre class="out">${escapeHtml(c.output)}</pre>`;
  (c.dumps || []).forEach((d) => { html += wrapDump(d); });
  if (c.kind === 'exception') {
    markErr();
    const e = c.error || {};
    html += `<pre class="error">${escapeHtml((e.class ? e.class + ': ' : '') + (e.message || 'error'))}</pre>`;
  } else if (c.kind === 'no-value') {
    if (!c.output && !(c.dumps && c.dumps.length)) html += `<pre class="note">✓ (no return value)</pre>`;
  } else if (c.kind === 'halted') {
    html += `<pre class="note halt">⛔ execution stopped (dd)</pre>`;
  } else {
    if (c.result_html) html += wrapDump(c.result_html);
    else html += `<pre class="value">${escapeHtml(c.result_text || 'null')}</pre>`;
  }
  return html + '</div>';
}

function placeBlock(block) {
  const placeholder = output.querySelector('.placeholder');
  if (placeholder) placeholder.remove();
  output.prepend(block);
  runScripts(block); // wire up any injected VarDumper dumps (elements are now in the DOM)
  return block;
}

function setStatus(text, err = false) {
  statusEl.textContent = text;
  statusEl.className = err ? 'err' : '';
}
function escapeHtml(s) { return String(s).replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c])); }
function escapeAttr(s) { return String(s).replace(/"/g, '&quot;'); }

let dumpSeq = 0;
// Give each injected VarDumper fragment a page-unique dump id so dumps from different
// cells/runs never collide on getElementById. A fragment has one base id (sf-dump-N);
// derived ref ids share that prefix, so replacing the base string rewrites them consistently.
function uniqueizeDump(html) {
  const m = html.match(/sf-dump-\d+/);
  if (!m) return html;
  return html.split(m[0]).join('sf-dump-tw-' + ++dumpSeq);
}
function wrapDump(html) { return `<div class="dump">${uniqueizeDump(html)}</div>`; }
// innerHTML does not execute <script>; re-create them so each fragment's Sfdump("id") init runs.
function runScripts(el) {
  el.querySelectorAll('script').forEach((old) => {
    const s = document.createElement('script');
    s.textContent = old.textContent;
    old.replaceWith(s);
  });
}

$('#run-btn').onclick = () => run(false);

autorunEl.checked = localStorage.getItem(AUTORUN_KEY) === '1';
autorunEl.addEventListener('change', () => {
  clearTimeout(debounceTimer); // cancel any pending live-run so toggling off can't fire one
  localStorage.setItem(AUTORUN_KEY, autorunEl.checked ? '1' : '0');
  if (autorunEl.checked) {
    run(true);
  } else if (liveBlock) {
    liveBlock.classList.remove('live'); // toggle off: drop the dashed accent, stop tracking
    liveBlock = null;
  }
});

// --- editor: CodeMirror 6 via the vendored bundle (owns ⌘/Ctrl+↵, Tab, and change events) ---
const editorApi = TinkerEditor.create(editorEl, {
  doc: 'User::count()',
  complete: phpComplete,
  onRun: () => run(false),
  onChange: () => {
    if (!autorunEl.checked) return;
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
      if (autorunEl.checked && editorApi.getDoc() !== lastCode) run(true); // re-check: toggled off during the wait
    }, 400);
  },
});
editorApi.focus();

loadConnections().then(() => loadSymbols(projectSel.value));
projectSel.addEventListener('change', () => { memberCache.clear(); loadSymbols(projectSel.value); });
