# Open-current-folder + Live Notebook Eval — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let `tinker-web .` open the current folder, and add an opt-in "Auto-run" mode that re-evaluates the snippet on statement completion and renders each top-level statement as its own notebook cell.

**Architecture:** Keep the existing stateless design — each eval runs in a fresh subprocess that boots the target and is discarded. Feature 1 adds a pure path-resolution helper used by the CLI. Feature 2 splits the snippet into top-level statements (by top-level `;`, via `token_get_all`), evaluates them in one shared scope so state persists *within* a run, and returns a cell-per-statement envelope that the browser renders live (debounced) or on demand.

**Tech Stack:** PHP 8.2+, Pest (tests), vanilla JS/CSS/HTML (no build step), `token_get_all` + `eval` (no new dependencies).

## Global Constraints

- PHP version floor: `^8.2` (composer.json). Use only syntax available in 8.2.
- **No new runtime dependencies.** The runner subprocess loads the *target* project's autoloader, not tinker-web's — so it may only use PHP built-ins, classes the target ships (`Symfony\…\VarDumper`, optionally `\Psy\CodeCleaner`, already used conditionally), and tinker-web files it `require`s explicitly by path.
- The eval envelope always has an `ok` key (contract relied on by `RunnerBridge`).
- Comments: match the surrounding terse, purpose-explaining comment style already in these files; do not over-comment.
- Namespaces: `Amims71\TinkerWeb\` → `src/` (PSR-4); tests `Amims71\TinkerWeb\Tests\` → `tests/`.
- Frontend has no JS test harness; do not add one. Frontend tasks are verified by driving the app.
- Branch: `feat/cwd-and-live-notebook` (already created and checked out).

---

## Task 1: `tinker-web .` and relative project paths

**Files:**
- Modify: `src/Connections/ConnectionStore.php` (add a static `resolve()` method)
- Modify: `bin/tinker-web:36` (use `resolve()` for an explicit project arg)
- Test: `tests/Unit/ConnectionStoreTest.php` (append cases)

**Interfaces:**
- Consumes: nothing new.
- Produces: `ConnectionStore::resolve(string $path): string` — returns an absolute path via `realpath()`, or the `rtrim`-of-`/`ed input when the path does not exist.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/ConnectionStoreTest.php`:

```php
it('resolves a relative path to its realpath and strips a trailing slash', function () {
    $sub = $this->dir.'/nested';
    mkdir($sub);
    $prev = getcwd();
    chdir($this->dir);
    try {
        expect(ConnectionStore::resolve('nested'))->toBe(realpath($sub));
        expect(ConnectionStore::resolve('./nested/'))->toBe(realpath($sub));
    } finally {
        chdir($prev);
        rmdir($sub);
    }
});

it('falls back to the trimmed input for a non-existent path', function () {
    expect(ConnectionStore::resolve('/no/such/path/'))->toBe('/no/such/path');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest --filter=resolve`
Expected: FAIL — `Call to undefined method Amims71\TinkerWeb\Connections\ConnectionStore::resolve()`.

- [ ] **Step 3: Implement `resolve()`**

In `src/Connections/ConnectionStore.php`, add this method (e.g. just after `default()`):

```php
/** Resolve a possibly-relative path to an absolute one; fall back to the trimmed input. */
public static function resolve(string $path): string
{
    $path = rtrim($path, '/');
    $real = realpath($path);

    return $real !== false ? $real : $path;
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest --filter=resolve`
Expected: PASS (2 passed).

- [ ] **Step 5: Wire it into the CLI**

In `bin/tinker-web`, change line 36 from:

```php
$project = $project !== null ? rtrim($project, '/') : getcwd();
```

to:

```php
$project = $project !== null ? ConnectionStore::resolve($project) : getcwd();
```

(`ConnectionStore` is already imported at the top of the file.)

- [ ] **Step 6: Verify the CLI resolves `.`**

Run: `php -r 'require "vendor/autoload.php"; echo Amims71\TinkerWeb\Connections\ConnectionStore::resolve("."), "\n";'`
Expected: prints the absolute path of the current directory (not `.`).

- [ ] **Step 7: Run the full suite**

Run: `vendor/bin/pest`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add src/Connections/ConnectionStore.php bin/tinker-web tests/Unit/ConnectionStoreTest.php
git commit -m "feat: resolve \`tinker-web .\` and relative paths to absolute"
```

---

## Task 2: `StatementSplitter` — split a snippet into top-level statements

**Files:**
- Create: `src/Runner/StatementSplitter.php`
- Test: `tests/Unit/StatementSplitterTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `StatementSplitter::split(string $code): string[]` — top-level statements, cut only at a `;` at bracket-depth 0; a trailing expression without `;` becomes the final element; empty/`;`-only chunks dropped.
  - `StatementSplitter::isBalanced(string $code): bool` — true when every `{`, `(`, `[` is closed (a cheap "input looks complete" check for the fallback gate).

*(This class is `require`d by `runner.php` directly by path in Task 3, because the runner subprocess does not have tinker-web's autoloader. It has no dependencies, so a bare `require_once` is safe.)*

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/StatementSplitterTest.php`:

```php
<?php

use Amims71\TinkerWeb\Runner\StatementSplitter;

it('splits top-level statements on a semicolon', function () {
    expect(StatementSplitter::split('$a = 1; $b = 2;'))->toBe(['$a = 1;', '$b = 2;']);
});

it('keeps a trailing expression without a semicolon', function () {
    expect(StatementSplitter::split('$a = 1; count($a)'))->toBe(['$a = 1;', 'count($a)']);
});

it('does not split on a semicolon inside a string', function () {
    expect(StatementSplitter::split('$s = "a;b"; strlen($s)'))->toBe(['$s = "a;b";', 'strlen($s)']);
});

it('does not split on a semicolon inside for(;;) parens', function () {
    expect(StatementSplitter::split('for ($i = 0; $i < 2; $i++) { echo $i; }'))
        ->toBe(['for ($i = 0; $i < 2; $i++) { echo $i; }']);
});

it('splits at the semicolon after a closure, not the closure brace', function () {
    expect(StatementSplitter::split('$f = function () { return 1; }; $f()'))
        ->toBe(['$f = function () { return 1; };', '$f()']);
});

it('keeps if/else as one statement (never splits on })', function () {
    expect(StatementSplitter::split('if (true) { echo 1; } else { echo 2; }'))
        ->toBe(['if (true) { echo 1; } else { echo 2; }']);
});

it('reports balance for the incomplete gate', function () {
    expect(StatementSplitter::isBalanced('foreach ($a as $b) {'))->toBeFalse();
    expect(StatementSplitter::isBalanced('$x = [1, 2'))->toBeFalse();
    expect(StatementSplitter::isBalanced('if (true) { echo 1; }'))->toBeTrue();
    expect(StatementSplitter::isBalanced('User::count()'))->toBeTrue();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/StatementSplitterTest.php`
Expected: FAIL — class `StatementSplitter` not found.

- [ ] **Step 3: Implement `StatementSplitter`**

Create `src/Runner/StatementSplitter.php`:

```php
<?php

namespace Amims71\TinkerWeb\Runner;

/** Splits a PHP snippet into top-level statements and reports bracket balance, using token_get_all. */
final class StatementSplitter
{
    /**
     * Split a snippet into top-level statements, cutting only at a ';' at bracket-depth 0.
     * A trailing expression without ';' becomes the final statement. A '}' is never a cut
     * point (it is ambiguous: closure/else/match bodies), so if/else, try/catch and closure
     * assignments stay whole. Separators inside strings/comments/heredocs are ignored.
     *
     * @return string[]
     */
    public static function split(string $code): array
    {
        $tokens = @token_get_all('<?php '.$code); // no TOKEN_PARSE: must not throw on a trailing no-';' expr
        array_shift($tokens);                     // drop the injected open tag

        $statements = [];
        $buffer = '';
        $depth = 0;

        foreach ($tokens as $token) {
            $buffer .= is_array($token) ? $token[1] : $token;

            if (! is_array($token)) {
                if ($token === '{' || $token === '(' || $token === '[') {
                    $depth++;
                } elseif ($token === '}' || $token === ')' || $token === ']') {
                    $depth--;
                } elseif ($token === ';' && $depth === 0) {
                    $trimmed = trim($buffer);
                    if ($trimmed !== '' && $trimmed !== ';') {
                        $statements[] = $trimmed;
                    }
                    $buffer = '';
                }
            }
        }

        $trailing = trim($buffer);
        if ($trailing !== '') {
            $statements[] = $trailing;
        }

        return $statements;
    }

    /** True when every '{', '(' and '[' is closed — a cheap "input looks complete" check. */
    public static function isBalanced(string $code): bool
    {
        $depth = 0;

        foreach (@token_get_all('<?php '.$code) as $token) {
            if (is_array($token)) {
                continue;
            }
            if ($token === '{' || $token === '(' || $token === '[') {
                $depth++;
            } elseif ($token === '}' || $token === ')' || $token === ']') {
                $depth--;
            }
        }

        return $depth === 0;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/StatementSplitterTest.php`
Expected: PASS (all cases green).

- [ ] **Step 5: Commit**

```bash
git add src/Runner/StatementSplitter.php tests/Unit/StatementSplitterTest.php
git commit -m "feat: add StatementSplitter for top-level statement splitting"
```

---

## Task 3: Notebook eval end-to-end (runner cells + frontend rendering)

Backend and frontend are one contract (the cell-based envelope) and must land together so the app keeps working. After this task, clicking **Run** shows one cell per top-level statement.

**Files:**
- Modify: `src/Runner/runner.php` (replace the clean+eval+capture block, lines ~73–120)
- Modify: `resources/dist/app.js` (rewrite `render()` to consume cells)
- Modify: `resources/dist/app.css` (per-cell styling)

**Interfaces:**
- Consumes: `Amims71\TinkerWeb\Runner\StatementSplitter::split()` / `::isBalanced()` (Task 2); the existing `$render` closure and `$respond` callback in `runner.php`.
- Produces: the new eval envelope, decoded by `RunnerBridge` and rendered by `app.js`:

```json
{
  "ok": true,
  "kind": "value | incomplete | parse-error",
  "cells": [
    { "kind": "value",     "output": "…", "result_text": "…", "result_html": "…" },
    { "kind": "no-value",  "output": "…", "result_text": "",  "result_html": "" },
    { "kind": "exception", "output": "…", "error": { "class": "…", "message": "…" } }
  ],
  "laravel": "11.x"
}
```
`incomplete` and `parse-error` carry `cells: []`. Non-user failures (bad project, runner crash) keep the existing `{ "ok": false, … }` shape.

- [ ] **Step 1: Add the notebook evaluator function to `runner.php`**

In `src/Runner/runner.php`, add this function **after** the `$render` closure is defined (after line 71) and before the old clean/eval block. It runs every statement in one function scope so variables persist within the run; `__`-prefixed locals avoid colliding with user variables:

```php
/**
 * Evaluate top-level statements in one shared scope so state persists within the run.
 * Each statement becomes a cell (its captured output + value), or an exception cell that
 * stops the run — mirroring how a script aborts on an uncaught throwable.
 *
 * @param string[] $__statements
 * @return array<int, array<string,mixed>>
 */
function tinkerweb_notebook(array $__statements, callable $__render): array
{
    $__cells = [];

    foreach ($__statements as $__stmt) {
        $__body = rtrim(trim($__stmt), ';');
        ob_start();
        try {
            try {
                // An expression (incl. assignment) yields a value AND mutates the shared scope.
                $__value = eval('return '.$__body.';');
                $__hasValue = true;
            } catch (\ParseError $__pe) {
                // Not an expression (control structure, echo, declaration) — run as-is, no value.
                eval($__stmt.';');
                $__value = null;
                $__hasValue = false;
            }

            $__rendered = $__hasValue ? $__render($__value) : ['text' => '', 'html' => ''];
            $__cells[] = [
                'kind' => $__hasValue ? 'value' : 'no-value',
                'output' => (string) ob_get_clean(),
                'result_text' => $__rendered['text'],
                'result_html' => $__rendered['html'],
            ];
        } catch (\Throwable $__e) {
            $__cells[] = [
                'kind' => 'exception',
                'output' => (string) ob_get_clean(),
                'error' => ['class' => get_class($__e), 'message' => $__e->getMessage()],
            ];
            break; // stop the run at the first runtime error
        }
    }

    return $__cells;
}
```

- [ ] **Step 2: Add the `require` and `use` for `StatementSplitter`**

At the **top** of `src/Runner/runner.php`, alongside the existing `use Symfony\…` lines (lines 11–13), add:

```php
use Amims71\TinkerWeb\Runner\StatementSplitter;
```

(`use` must be at the top of the file — do **not** place it in the replacement block below.) Then, just after the existing autoload `foreach`/`break` block (around line 14, before `$raw = stream_get_contents(STDIN)`), add the direct `require` (it has no dependencies, so requiring it by path is safe and keeps the runner isolated from the target's autoloader):

```php
require_once __DIR__.'/StatementSplitter.php';
```

- [ ] **Step 3: Replace the clean/eval/capture block with the gate + split + notebook**

Replace the entire block from the `// --- clean (implicit-return the last expression) + eval + capture ---` comment (line 73) through the end of the file (line 120) with (the `use` was already added at the top in Step 2 — do not repeat it here):

```php
// --- completeness / parse gate: decide incomplete vs parse-error before splitting ---
if (class_exists(\Psy\CodeCleaner::class)) {
    try {
        $cleaned = (new \Psy\CodeCleaner())->clean([$code]);
    } catch (\Throwable $e) {
        $respond(['ok' => true, 'kind' => 'parse-error', 'cells' => [], 'error' => ['class' => get_class($e), 'message' => $e->getMessage()]]);
    }
    if ($cleaned === false) {
        $respond(['ok' => true, 'kind' => 'incomplete', 'cells' => []]);
    }
} elseif (! StatementSplitter::isBalanced($code)) {
    $respond(['ok' => true, 'kind' => 'incomplete', 'cells' => []]);
}

// --- convert warnings/notices to exceptions so a bad statement surfaces as an exception cell ---
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (! (error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

$cells = tinkerweb_notebook(StatementSplitter::split($code), $render);

restore_error_handler();

$respond([
    'ok' => true,
    'kind' => 'value',
    'cells' => $cells,
    'laravel' => $app->version(),
]);
```

Notes:
- `$app->version()` is read here (once, after the run) — user code clobbering `$app` inside a statement is harmless.
- The old top-level `set_error_handler`/`ob_start`/single-`eval` code is fully removed; the handler now wraps the whole notebook loop.

- [ ] **Step 4: Rewrite `render()` in `app.js` to consume cells**

In `resources/dist/app.js`, replace the `render(env, ms)` function (lines 52–76) with a version that renders a block containing one sub-element per cell. Return the created block so the caller can coalesce it (Task 4):

```javascript
function render(env, ms) {
  if (env.laravel) laravelEl.textContent = 'Laravel ' + env.laravel;

  const block = document.createElement('div');
  block.className = 'run ' + (env.ok ? 'ok' : 'err');

  if (!env.ok) {
    // non-user failure (bad project / runner crash)
    const e = env.error || {};
    block.innerHTML = `<div class="result err"><pre class="error">${escapeHtml((e.class ? e.class + ': ' : '') + (e.message || 'error'))}</pre></div>`;
    setStatus(`error · ${ms}ms`, true);
    return placeBlock(block);
  }

  const cells = env.cells || [];
  let ok = true;
  block.innerHTML = cells.map((c) => renderCell(c, () => { ok = false; })).join('') || '<div class="result ok"><pre class="note">✓ (no statements)</pre></div>';
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
```

- [ ] **Step 5: Add per-cell CSS**

In `resources/dist/app.css`, add after the `.error` rule (line 54). A `.run` block groups the cells of one run; cells reuse the existing `.result` styling, with a little separation:

```css
.run { margin-bottom: 14px; }
.run > .result + .result { margin-top: 6px; }
```

- [ ] **Step 6: Verify end-to-end against a Laravel target**

Requires a local Laravel project (`vendor/autoload.php` + `bootstrap/app.php`). If none is available, note it and defer this verification to the reviewer who has one.

Run: `bin/tinker-web /path/to/laravel/app`
In the browser, replace the editor contents and click **Run** for each of:
1. `$a = 1; $b = $a + 2; $a + $b` → three cells: `1`, `3`, `4`.
2. `dump(1); dump(2);` → both `1` and `2` appear in output.
3. `foreach (range(1,3) as $n) { echo $n; }` → one cell, output `123`, "no return value".
4. `throw new RuntimeException('boom'); 1 + 1` → one exception cell (`RuntimeException: boom`); the `1 + 1` cell is absent (run stopped).
5. `User::count()` → single value cell (adjust to a model the target has, or `\DB::table('migrations')->count()`).

Expected: behaviour as described; no raw/500 responses.

- [ ] **Step 7: Run the PHP suite (guard against regressions)**

Run: `vendor/bin/pest`
Expected: all green (no test asserts the old envelope shape).

- [ ] **Step 8: Commit**

```bash
git add src/Runner/runner.php resources/dist/app.js resources/dist/app.css
git commit -m "feat: notebook per-statement eval with a cell-based envelope"
```

---

## Task 4: Auto-run toggle + live re-run

**Files:**
- Modify: `resources/web/index.html` (Auto-run toggle control)
- Modify: `resources/dist/app.js` (toggle wiring, debounce, sequence guard, suppression, coalescing)
- Modify: `resources/dist/app.css` (toggle styling)

**Interfaces:**
- Consumes: `render()` / `placeBlock()` (Task 3), the existing `run()` and `api()` in `app.js`.
- Produces: no external interface; adds an `#autorun` checkbox and auto-run behaviour.

- [ ] **Step 1: Add the toggle control to `index.html`**

In `resources/web/index.html`, inside the `.hint`/header area, add a labelled checkbox before the Run button (after line 18, before line 19):

```html
    <label class="toggle"><input id="autorun" type="checkbox"> Auto-run</label>
```

- [ ] **Step 2: Style the toggle**

In `resources/dist/app.css`, add near the `.hint` rule (line 30):

```css
.toggle { display: flex; align-items: center; gap: 5px; color: var(--muted); font-size: 12px; cursor: pointer; }
.toggle input { width: auto; }
```

- [ ] **Step 3: Add auto-run state, debounce, and suppression to `app.js`**

In `resources/dist/app.js`:

(a) Add near the top (after line 7, the `laravelEl` line):

```javascript
const autorunEl = $('#autorun');
const AUTORUN_KEY = 'tinker-web:autorun';
let liveBlock = null;   // the coalesced auto-run block currently at the top
let runSeq = 0;         // sequence guard: only the latest response is rendered
let lastCode = null;    // skip re-running identical code
let debounceTimer = null;
```

(b) Replace the whole-function `run()` (lines 37–50) with a version that accepts a `live` flag and guards concurrency/suppression:

```javascript
async function run(live = false) {
  const project = projectSel.value;
  if (!project) { if (!live) setStatus('add a project first', true); return; }
  const code = editor.value;
  if (live && code.trim() === '') return;

  const seq = ++runSeq;
  lastCode = code;
  if (!live) setStatus('running…');
  const t0 = performance.now();
  let env;
  try {
    env = await api('/eval', { method: 'POST', body: JSON.stringify({ project, code }) });
  } catch (e) {
    if (seq === runSeq && !live) setStatus('request failed', true);
    return;
  }
  if (seq !== runSeq) return; // a newer run superseded this one

  // While typing, suppress half-written snippets instead of flashing errors.
  if (live && env.ok && (env.kind === 'incomplete' || env.kind === 'parse-error')) {
    setStatus('… ' + env.kind);
    return;
  }

  const ms = Math.round(performance.now() - t0);
  const block = render(env, ms);
  if (live) {
    if (liveBlock && liveBlock.parentNode) liveBlock.remove(); // coalesce: replace the previous live block
    liveBlock = block;
    block.classList.add('live');
  } else {
    liveBlock = null; // a manual run becomes permanent history
  }
}
```

Note: `render()` prepends the new block first; then for a live run we remove the *previous* live block. Because `render()` already added the new one, `liveBlock` is reassigned to it before removal of the old — order in the code above handles this (remove old, then assign new). Reorder if needed so the newly-prepended block is not the one removed.

(c) Add the debounced input listener and toggle wiring at the bottom (after line 94, `$('#run-btn').onclick = run;` — change it to `() => run(false)`):

```javascript
$('#run-btn').onclick = () => run(false);

autorunEl.checked = localStorage.getItem(AUTORUN_KEY) === '1';
autorunEl.addEventListener('change', () => {
  localStorage.setItem(AUTORUN_KEY, autorunEl.checked ? '1' : '0');
  if (autorunEl.checked) run(true);
});

editor.addEventListener('input', () => {
  if (!autorunEl.checked) return;
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    if (editor.value !== lastCode) run(true);
  }, 400);
});
```

Also update the existing ⌘/Ctrl+Enter handler (line 86) to call `run(false)` explicitly:

```javascript
  if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') { e.preventDefault(); run(false); }
```

- [ ] **Step 4: Fix the coalescing order (self-check)**

Confirm the live-block logic removes the **previous** block, not the newly-rendered one. Correct sequence in `run()`:

```javascript
  const previousLive = liveBlock;
  const block = render(env, ms);      // prepends the new block
  if (live) {
    if (previousLive && previousLive.parentNode) previousLive.remove();
    block.classList.add('live');
    liveBlock = block;
  } else {
    liveBlock = null;
  }
```

Apply this corrected ordering (capture `previousLive` before calling `render`).

- [ ] **Step 5: Add a subtle live-block accent (optional but cheap)**

In `resources/dist/app.css`:

```css
.run.live { outline: 1px dashed var(--border); outline-offset: 2px; }
```

- [ ] **Step 6: Verify in the browser against a Laravel target**

Run: `bin/tinker-web /path/to/laravel/app` (or `bin/tinker-web .` from inside one).

1. Toggle **Auto-run** on. Type `1 + 1` then `;` — after ~400ms a cell shows `2`, no click needed.
2. Continue typing so the buffer is `dump(1);\ndump(2);` — the live block updates in place (does **not** stack a new block per keystroke-pause) and shows both `1` and `2`.
3. Type an incomplete `foreach (range(1,3) as $n) {` — no error flashes; status shows `… incomplete`; the previous result stays.
4. Reload the page — Auto-run stays on (localStorage).
5. Toggle Auto-run off; click **Run** twice — two permanent blocks stack (history preserved).

Expected: all behaviours as described; no duplicate live blocks, no stale overwrite.

- [ ] **Step 7: Commit**

```bash
git add resources/web/index.html resources/dist/app.js resources/dist/app.css
git commit -m "feat: opt-in Auto-run with debounced live re-run and coalesced output"
```

---

## Task 5: Roadmap card for the deferred stateful REPL

**Files:**
- Modify: `docs/index.html` (add a Roadmap card near line 203)

**Interfaces:** none (documentation only).

- [ ] **Step 1: Add the card**

In `docs/index.html`, inside the Roadmap `.grid` (after the "Warm runner daemon" card, around line 202), add:

```html
    <div class="card"><h3>Stateful REPL session<span class="soon">soon</span></h3><p>Keep one long-lived process per target so variables persist across evaluations and each statement runs exactly once — no repeated side effects, like a terminal <code>tinker</code>. Builds on the warm runner daemon.</p></div>
```

- [ ] **Step 2: Verify it renders**

Run: `grep -c "Stateful REPL session" docs/index.html`
Expected: `1`.

- [ ] **Step 3: Commit**

```bash
git add docs/index.html
git commit -m "docs: roadmap card for the future stateful REPL session"
```

---

## Self-Review (author's check — completed)

- **Spec coverage:** Feature 1 → Task 1. Statement splitting → Task 2. Cell envelope + rendering → Task 3. Auto-run toggle/debounce/suppression/coalescing → Task 4. Deferred-REPL roadmap card → Task 5. Known limitation (brace-terminated merge) is documented in the spec and covered by the "if/else stays one statement" test in Task 2.
- **Placeholder scan:** no TBD/TODO; every code step shows complete code. The only conditional is Task 3 Step 6 / Task 4 Step 6 (manual verification needs a Laravel target) — explicitly flagged with a fallback.
- **Type consistency:** `render()` returns the block element (Task 3) and is consumed by `run(live)` (Task 4); `StatementSplitter::split()`/`isBalanced()` names match between Task 2 and Task 3; the envelope keys (`ok`, `kind`, `cells[].kind`, `output`, `result_text`, `result_html`, `error`, `laravel`) are identical between `runner.php` (Task 3 Step 3) and `render()`/`renderCell()` (Task 3 Step 4).
```
