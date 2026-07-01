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
  block.className = 'result ' + (env.ok ? 'ok' : 'err');
  let html = '';

  if (env.output) html += `<div class="out-label">output</div><pre class="out">${escapeHtml(env.output)}</pre>`;

  if (env.ok) {
    if (env.kind === 'incomplete') html += `<pre class="note">… incomplete input</pre>`;
    else if (env.kind === 'no-value') html += `<pre class="note">✓ (no return value)</pre>`;
    else html += `<pre class="value">${escapeHtml(env.result_text || 'null')}</pre>`;
    setStatus(`ok · ${ms}ms`);
  } else {
    const e = env.error || {};
    html += `<pre class="error">${escapeHtml((e.class ? e.class + ': ' : '') + (e.message || 'error'))}</pre>`;
    setStatus(`error · ${ms}ms`, true);
  }

  block.innerHTML = html;
  const placeholder = output.querySelector('.placeholder');
  if (placeholder) placeholder.remove();
  output.prepend(block);
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
