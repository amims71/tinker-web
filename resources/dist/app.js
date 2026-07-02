const token = new URLSearchParams(location.search).get('t') || '';
const $ = (s) => document.querySelector(s);
const editor = $('#editor');
const output = $('#output');
const projectSel = $('#project');
const statusEl = $('#status');
const laravelEl = $('#laravel');

async function api(path, opts = {}) {
  const res = await fetch(path, {
    ...opts,
    headers: { 'X-Token': token, 'Content-Type': 'application/json', ...(opts.headers || {}) },
  });
  return res.json();
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

async function run() {
  const project = projectSel.value;
  if (!project) { setStatus('add a project first', true); return; }
  setStatus('running…');
  const t0 = performance.now();
  let env;
  try {
    env = await api('/eval', { method: 'POST', body: JSON.stringify({ project, code: editor.value }) });
  } catch (e) {
    setStatus('request failed', true);
    return;
  }
  render(env, Math.round(performance.now() - t0));
}

function render(env, ms) {
  if (env.laravel) laravelEl.textContent = 'Laravel ' + env.laravel;

  const block = document.createElement('div');

  if (!env.ok) {
    // non-user failure (bad project / runner crash)
    block.className = 'run err';
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
  block.className = 'run ' + (ok ? 'ok' : 'err');
  setStatus(ok ? `ok · ${ms}ms` : `error · ${ms}ms`, !ok);
  return placeBlock(block);
}

function renderCell(c, markErr) {
  let html = '<div class="result ' + (c.kind === 'exception' ? 'err' : 'ok') + '">';
  if (c.output) html += `<div class="out-label">output</div><pre class="out">${escapeHtml(c.output)}</pre>`;
  if (c.kind === 'exception') {
    markErr();
    const e = c.error || {};
    html += `<pre class="error">${escapeHtml((e.class ? e.class + ': ' : '') + (e.message || 'error'))}</pre>`;
  } else if (c.kind === 'no-value') {
    html += `<pre class="note">✓ (no return value)</pre>`;
  } else {
    html += `<pre class="value">${escapeHtml(c.result_text || 'null')}</pre>`;
  }
  return html + '</div>';
}

function placeBlock(block) {
  const placeholder = output.querySelector('.placeholder');
  if (placeholder) placeholder.remove();
  output.prepend(block);
  return block;
}

function setStatus(text, err = false) {
  statusEl.textContent = text;
  statusEl.className = err ? 'err' : '';
}
function escapeHtml(s) { return String(s).replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c])); }
function escapeAttr(s) { return String(s).replace(/"/g, '&quot;'); }

editor.addEventListener('keydown', (e) => {
  if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') { e.preventDefault(); run(); }
  if (e.key === 'Tab') {
    e.preventDefault();
    const s = editor.selectionStart, en = editor.selectionEnd;
    editor.value = editor.value.slice(0, s) + '    ' + editor.value.slice(en);
    editor.selectionStart = editor.selectionEnd = s + 4;
  }
});
$('#run-btn').onclick = run;

loadConnections();
