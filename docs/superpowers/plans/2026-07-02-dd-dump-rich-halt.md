# dd()/dump() support — graceful halt + rich dumps — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `dd()`/`exit()`/`die()` stop the notebook gracefully, and render `dump()`/`dd()` output and return values as VarDumper's collapsible interactive HTML.

**Architecture:** Keep the stateless fresh-subprocess runner. Rich dumps use VarDumper's `HtmlDumper`: the ~16KB Sfdump JS/CSS is vendored once as a static asset (`resources/dist/sfdump.{js,css}`) loaded by `index.html`, and per-item header-less fragments are injected client-side and wired up by re-executing their trailing `Sfdump("id")` init script. Graceful halt uses a `register_shutdown_function` over a shared run-state holder that drains the output buffer into a `halted` cell (distinguishing a real fatal via `error_get_last()`).

**Tech Stack:** PHP 8.2+, Pest, vanilla JS/CSS/HTML (no build step), symfony/var-dumper (the *target's* copy), `register_shutdown_function`, `token_get_all`.

## Global Constraints

- PHP `^8.2` only; **no new dependencies**. The runner subprocess loads only the *target's* autoloader — it may use PHP built-ins, the target's classes (`Symfony\…\VarDumper`, optionally `\Psy\CodeCleaner`), and tinker-web files it `require`s by path (`StatementSplitter`).
- The eval envelope always has an `ok` key. `RunnerBridge`, `Server`, and `StatementSplitter` are **not** modified — the eval envelope carries no dump assets.
- No frontend build step (plain JS/CSS/HTML). No JS test harness — frontend is verified by driving the running app.
- **Security (critical):** only VarDumper-produced fragments (`result_html`, `dumps[]`) are injected as HTML and have scripts re-executed. Plain `echo` output (cell `output`) is **always** `escapeHtml`-escaped and never injected as HTML.
- Comments explain purpose in the surrounding terse style; do not over-comment.
- Verification target (boots cleanly, has PsySH + var-dumper, Laravel 13.x): `/Users/shan/PhpstormProjects/dc-boilerplate`.
- Branch: `feat/dd-dump-rich-halt` (already created and checked out).
- The runner boots a full app, so it has no unit test (existing pattern) — backend tasks are verified by invoking the runner directly over stdin. The existing Pest suite (31 tests) must stay green.

---

## Task 1: Vendor the Sfdump assets and load them once

**Files:**
- Create: `resources/dist/sfdump.js`, `resources/dist/sfdump.css` (generated, then committed)
- Modify: `resources/web/index.html`

**Interfaces:**
- Consumes: nothing.
- Produces: a global `window.Sfdump` function and `pre.sf-dump` styles available on page load; served at `/assets/sfdump.js` and `/assets/sfdump.css` (handled automatically by `Server::serveAsset`, which serves any file in `resources/dist/`).

- [ ] **Step 1: Generate the vendored assets from the target's VarDumper**

Run from the repo root:

```bash
php -r '
require "/Users/shan/PhpstormProjects/dc-boilerplate/vendor/autoload.php";
$full = (string)(new Symfony\Component\VarDumper\Dumper\HtmlDumper())
    ->dump((new Symfony\Component\VarDumper\Cloner\VarCloner())->cloneVar(0), true);
$header = substr($full, 0, strpos($full, "<pre class=sf-dump"));
preg_match("#<script>(.*?)</script>#s", $header, $js);
preg_match("#<style>(.*?)</style>#s", $header, $css);
$ver = \Composer\InstalledVersions::getPrettyVersion("symfony/var-dumper");
file_put_contents("resources/dist/sfdump.js", "/* Sfdump — vendored from symfony/var-dumper $ver; regenerate if dumps stop collapsing. */\n".trim($js[1])."\n");
file_put_contents("resources/dist/sfdump.css", "/* Sfdump styles — vendored from symfony/var-dumper $ver. */\n".trim($css[1])."\n");
echo "wrote sfdump.js (", filesize("resources/dist/sfdump.js"), "B), sfdump.css (", filesize("resources/dist/sfdump.css"), "B), var-dumper $ver\n";
'
```

Expected: prints the two file sizes (js ~15KB, css ~1.5KB) and the var-dumper version.

- [ ] **Step 2: Verify the generated assets contain the expected content**

Run:

```bash
grep -c "Sfdump" resources/dist/sfdump.js && grep -c "pre.sf-dump" resources/dist/sfdump.css
head -1 resources/dist/sfdump.js
```

Expected: both counts ≥ 1; the first line is the vendored-from comment. Confirm neither file contains `<script` or `<style` tags (they were stripped): `grep -c "<script\|<style" resources/dist/sfdump.js resources/dist/sfdump.css` prints `0` for both.

- [ ] **Step 3: Load the assets in `index.html`**

In `resources/web/index.html`, add the stylesheet in `<head>` after the existing `app.css` link (line 7):

```html
  <link rel="stylesheet" href="/assets/sfdump.css">
```

And add the Sfdump script just before the existing `app.js` script tag (line 37, so `window.Sfdump` is defined before `app.js` runs):

```html
  <script src="/assets/sfdump.js"></script>
```

- [ ] **Step 4: Verify serving + no page breakage**

Start the server against the target and check the assets serve and the page still loads:

```bash
php bin/tinker-web /Users/shan/PhpstormProjects/dc-boilerplate --no-open --port=8891 > /tmp/tw1.log 2>&1 &
sleep 1
TOKEN=$(grep -o 't=[a-f0-9]*' /tmp/tw1.log | head -1 | cut -d= -f2)
curl -s -m5 -o /dev/null -w "sfdump.js: %{http_code} %{content_type}\n" -H "X-Token: $TOKEN" http://127.0.0.1:8891/assets/sfdump.js
curl -s -m5 -o /dev/null -w "sfdump.css: %{http_code} %{content_type}\n" -H "X-Token: $TOKEN" http://127.0.0.1:8891/assets/sfdump.css
curl -s -m5 -o /dev/null -w "index: %{http_code}\n" "http://127.0.0.1:8891/?t=$TOKEN"
kill %1 2>/dev/null
```

Expected: `sfdump.js: 200` with a javascript content-type, `sfdump.css: 200 text/css`, `index: 200`.

- [ ] **Step 5: Commit**

```bash
git add resources/dist/sfdump.js resources/dist/sfdump.css resources/web/index.html
git commit -m "feat: vendor Sfdump JS/CSS and load once for interactive dumps"
```

---

## Task 2: Rich rendering of dumps and return values

Runner and frontend land together (one envelope contract): `dump()`/`dd()` output goes into a per-cell `dumps: []` of header-less rich HTML, and return values render from `result_html`. After this task, `dump()` output and values render as collapsible HTML. (`dd()` remains a hard-stop until Task 3 — its pre-existing behavior, no regression.)

**Files:**
- Modify: `src/Runner/runner.php` (dump handler → per-cell `dumps`; add `dumps` to every cell)
- Modify: `resources/dist/app.js` (`uniqueizeDump`, `runScripts`, render `dumps`/`result_html`)
- Modify: `resources/dist/app.css` (dump wrapper spacing)

**Interfaces:**
- Consumes: `window.Sfdump` + `pre.sf-dump` CSS (Task 1); existing `$render` closure (returns `['text'=>…, 'html'=>…]`, html header-less).
- Produces: each cell gains `dumps: string[]` (header-less VarDumper HTML fragments). Frontend `uniqueizeDump(html): string` and `runScripts(el): void` helpers.

- [ ] **Step 1: Route dumps into a shared per-statement sink (runner)**

In `src/Runner/runner.php`, replace the dump handler block (lines 76–81) with a sink object + a handler that collects header-less rich HTML instead of echoing text:

```php
// dump()/dd() default to writing straight to php://stdout, which ob_start() cannot intercept.
// Collect each dumped value as a header-less VarDumper HTML fragment in a per-statement sink
// (the page already has the vendored Sfdump JS/CSS), kept separate from plain echo output.
unset($_SERVER['VAR_DUMPER_FORMAT']); // else setHandler() no-ops and dump() writes raw to stdout, corrupting our JSON
$dumpSink = new class {
    /** @var string[] */
    public array $items = [];
};
\Symfony\Component\VarDumper\VarDumper::setHandler(static function ($value) use ($render, $dumpSink): void {
    $dumpSink->items[] = $render($value)['html'];
});
```

- [ ] **Step 2: Collect `dumps` into every cell (runner)**

In `src/Runner/runner.php`, update `tinkerweb_notebook` to take the sink, reset it per statement, and attach `dumps` to each cell. Replace the whole function body (lines ~91–137) with:

```php
function tinkerweb_notebook(array $__statements, callable $__render, object $__dumpSink): array
{
    $__cells = [];
    $__preamble = '';

    foreach ($__statements as $__stmt) {
        if (StatementSplitter::isDeclaration($__stmt)) {
            $__preamble .= StatementSplitter::preambleFor($__stmt);
            $__cells[] = ['kind' => 'no-value', 'output' => '', 'dumps' => [], 'result_text' => '', 'result_html' => ''];
            continue;
        }

        $__body = rtrim(trim($__stmt), ';');
        $__dumpSink->items = []; // dumps belong to this statement only
        ob_start();
        try {
            try {
                $__value = eval($__preamble.'return '.$__body.';');
                $__hasValue = true;
            } catch (\ParseError $__pe) {
                eval($__preamble.$__stmt.';');
                $__value = null;
                $__hasValue = false;
            }

            $__rendered = $__hasValue ? $__render($__value) : ['text' => '', 'html' => ''];
            $__cells[] = [
                'kind' => $__hasValue ? 'value' : 'no-value',
                'output' => (string) ob_get_clean(),
                'dumps' => $__dumpSink->items,
                'result_text' => $__rendered['text'],
                'result_html' => $__rendered['html'],
            ];
        } catch (\Throwable $__e) {
            $__cells[] = [
                'kind' => 'exception',
                'output' => (string) ob_get_clean(),
                'dumps' => $__dumpSink->items,
                'error' => ['class' => get_class($__e), 'message' => $__e->getMessage()],
            ];
            break;
        }
    }

    return $__cells;
}
```

- [ ] **Step 3: Pass the sink at the call site (runner)**

In `src/Runner/runner.php`, update the call (line ~161) to pass `$dumpSink`:

```php
$cells = tinkerweb_notebook(StatementSplitter::split($code), $render, $dumpSink);
```

- [ ] **Step 4: Verify the runner emits rich `dumps` (backend)**

```bash
proj=/Users/shan/PhpstormProjects/dc-boilerplate
echo "{\"project\":\"$proj\",\"code\":\"dump([1,2,3])\"}" | php src/Runner/runner.php \
 | python3 -c "import sys,json;e=json.load(sys.stdin);c=e['cells'][0];print('kind',c['kind'],'ndumps',len(c['dumps']));print('dump0 starts:',c['dumps'][0][:40] if c['dumps'] else None);print('result_html present:', bool(c.get('result_html')))"
```

Expected: `kind value ndumps 1`; `dump0 starts: <pre class=sf-dump ...`; `result_html present: True`. Confirm the JSON is clean single-line (no leaked dump text): the command above would raise on invalid JSON.

- [ ] **Step 5: Add the frontend rich-render helpers (app.js)**

In `resources/dist/app.js`, add near the other helpers (after `escapeAttr`, ~line 111):

```javascript
let dumpSeq = 0;
// Give each injected VarDumper fragment a page-unique dump id so dumps from different
// cells/runs never collide on getElementById. A fragment has one base id (sf-dump-N);
// derived ref ids share that prefix, so replacing the base string rewrites them consistently.
function uniqueizeDump(html) {
  const m = html.match(/sf-dump-\d+/);
  if (!m) return html;
  return html.split(m[0]).join('sf-dump-tw-' + ++dumpSeq);
}
// innerHTML does not execute <script>; re-create them so each fragment's Sfdump("id") init runs.
function runScripts(el) {
  el.querySelectorAll('script').forEach((old) => {
    const s = document.createElement('script');
    s.textContent = old.textContent;
    old.replaceWith(s);
  });
}
```

- [ ] **Step 6: Execute injected scripts after the block is in the DOM (app.js)**

In `resources/dist/app.js`, update `placeBlock` (lines ~99–104) to run injected scripts once the block is attached (`Sfdump(id)` needs the element in the document):

```javascript
function placeBlock(block) {
  const placeholder = output.querySelector('.placeholder');
  if (placeholder) placeholder.remove();
  output.prepend(block);
  runScripts(block); // wire up any injected VarDumper dumps (elements are now in the DOM)
  return block;
}
```

- [ ] **Step 7: Render dumps and rich values in `renderCell` (app.js)**

In `resources/dist/app.js`, replace `renderCell` (lines ~84–97) with a version that injects `dumps[]` and uses `result_html` for values (escaped `result_text` as fallback):

```javascript
function renderCell(c, markErr) {
  let html = '<div class="result ' + (c.kind === 'exception' ? 'err' : 'ok') + '">';
  if (c.output) html += `<div class="out-label">output</div><pre class="out">${escapeHtml(c.output)}</pre>`;
  (c.dumps || []).forEach((d) => { html += `<div class="dump">${uniqueizeDump(d)}</div>`; });
  if (c.kind === 'exception') {
    markErr();
    const e = c.error || {};
    html += `<pre class="error">${escapeHtml((e.class ? e.class + ': ' : '') + (e.message || 'error'))}</pre>`;
  } else if (c.kind === 'no-value') {
    if (!c.output && !(c.dumps && c.dumps.length)) html += `<pre class="note">✓ (no return value)</pre>`;
  } else {
    if (c.result_html) html += `<div class="dump">${uniqueizeDump(c.result_html)}</div>`;
    else html += `<pre class="value">${escapeHtml(c.result_text || 'null')}</pre>`;
  }
  return html + '</div>';
}
```

- [ ] **Step 8: Add dump wrapper spacing (app.css)**

In `resources/dist/app.css`, add after the `.value` rule (~line 52):

```css
.dump { padding: 8px 14px; overflow-x: auto; }
.dump pre.sf-dump { margin: 0; padding: 0; }
```

- [ ] **Step 9: Verify frontend parses + PHP suite green**

```bash
node --check resources/dist/app.js && echo "js ok"
vendor/bin/pest 2>&1 | tail -3
```

Expected: `js ok`; `31 passed`.

- [ ] **Step 10: Browser smoke (controller-run; needs the app)**

Start `bin/tinker-web /Users/shan/PhpstormProjects/dc-boilerplate` and confirm in the browser: `dump(User::first())` renders a colored, collapsible dump (clicking a node expands/collapses); a return value like `User::first()` renders as a collapsible dump; two dumps in one run don't interfere (unique ids). If a browser tool is unavailable, verify via HTTP that `/eval` returns `dumps` with `<pre class=sf-dump` and that `/assets/sfdump.js` is loaded by index.html, and note that interactive verification is deferred.

- [ ] **Step 11: Commit**

```bash
git add src/Runner/runner.php resources/dist/app.js resources/dist/app.css
git commit -m "feat: render dump()/dd() output and return values as collapsible VarDumper HTML"
```

---

## Task 3: Graceful halt (dd / exit / die)

Runner and frontend land together. A shutdown net turns `dd()`/`exit()`/`die()` into a clean `halted` cell; the frontend renders it with a stop marker.

**Files:**
- Modify: `src/Runner/runner.php` (run-state holder, shutdown net, `halted` cell)
- Modify: `resources/dist/app.js` (render `halted` cell + stopped run block)
- Modify: `resources/dist/app.css` (halt marker + stopped block accent)

**Interfaces:**
- Consumes: `tinkerweb_notebook(...)`, `$dumpSink`, `$render`, `$respond` (runner); `renderCell`/`render` (frontend).
- Produces: envelope may include top-level `halted: true` and a cell of `kind: "halted"` (`{kind:"halted", output, dumps}`).

- [ ] **Step 1: Introduce the run-state holder and shutdown net (runner)**

In `src/Runner/runner.php`, immediately **before** the completeness/parse gate (before line ~139 `// --- completeness / parse gate`), add the holder + shutdown function. The holder lets the shutdown net read cells accumulated so far; `error_get_last()` distinguishes a real fatal from an intentional halt:

```php
// Shared run state so the shutdown net can report cells accumulated before a dd()/exit()/die().
$run = new class {
    /** @var array<int,array<string,mixed>> */
    public array $cells = [];
    public bool $responded = false;
    public string $laravel = '';
};
$run->laravel = $app->version();

register_shutdown_function(static function () use ($run, $dumpSink): void {
    if ($run->responded) {
        return; // normal path already emitted the envelope
    }
    // dd()/exit()/die() (or a fatal) terminated mid-run. Drain buffered output so PHP does not
    // flush it raw into our stdout, and capture the halted statement's plain output.
    $out = '';
    while (ob_get_level() > 0) {
        $out .= (string) ob_get_clean();
    }
    $fatal = error_get_last();
    if ($fatal !== null && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fwrite(STDOUT, json_encode(['ok' => false, 'kind' => 'runner-error', 'cells' => $run->cells, 'error' => ['class' => 'FatalError', 'message' => $fatal['message']]], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
        return;
    }
    $run->cells[] = ['kind' => 'halted', 'output' => $out, 'dumps' => $dumpSink->items];
    fwrite(STDOUT, json_encode(['ok' => true, 'kind' => 'value', 'halted' => true, 'cells' => $run->cells, 'laravel' => $run->laravel], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
});
```

- [ ] **Step 2: Accumulate cells in the holder as the run progresses (runner)**

`tinkerweb_notebook` returns its cells at the end, but the shutdown net needs them *as they complete*. Change the loop to append to `$run->cells` directly. In `src/Runner/runner.php`, change the function signature and body so it writes to the holder instead of a local array:

- Signature: `function tinkerweb_notebook(array $__statements, callable $__render, object $__dumpSink, object $__run): void`
- Replace every `$__cells[] = [...]` with `$__run->cells[] = [...]`, delete the local `$__cells = [];` and the final `return $__cells;`.

The full updated function:

```php
function tinkerweb_notebook(array $__statements, callable $__render, object $__dumpSink, object $__run): void
{
    $__preamble = '';

    foreach ($__statements as $__stmt) {
        if (StatementSplitter::isDeclaration($__stmt)) {
            $__preamble .= StatementSplitter::preambleFor($__stmt);
            $__run->cells[] = ['kind' => 'no-value', 'output' => '', 'dumps' => [], 'result_text' => '', 'result_html' => ''];
            continue;
        }

        $__body = rtrim(trim($__stmt), ';');
        $__dumpSink->items = [];
        ob_start();
        try {
            try {
                $__value = eval($__preamble.'return '.$__body.';');
                $__hasValue = true;
            } catch (\ParseError $__pe) {
                eval($__preamble.$__stmt.';');
                $__value = null;
                $__hasValue = false;
            }

            $__rendered = $__hasValue ? $__render($__value) : ['text' => '', 'html' => ''];
            $__run->cells[] = [
                'kind' => $__hasValue ? 'value' : 'no-value',
                'output' => (string) ob_get_clean(),
                'dumps' => $__dumpSink->items,
                'result_text' => $__rendered['text'],
                'result_html' => $__rendered['html'],
            ];
        } catch (\Throwable $__e) {
            $__run->cells[] = [
                'kind' => 'exception',
                'output' => (string) ob_get_clean(),
                'dumps' => $__dumpSink->items,
                'error' => ['class' => get_class($__e), 'message' => $__e->getMessage()],
            ];
            break;
        }
    }
}
```

- [ ] **Step 3: Update the call site + normal response to use the holder (runner)**

In `src/Runner/runner.php`, replace the `$cells = tinkerweb_notebook(...)` call and the final `$respond([...])` (lines ~161–170) with:

```php
tinkerweb_notebook(StatementSplitter::split($code), $render, $dumpSink, $run);

restore_error_handler();

$run->responded = true;
$respond([
    'ok' => true,
    'kind' => 'value',
    'cells' => $run->cells,
    'laravel' => $run->laravel,
]);
```

(The `set_error_handler(...)` call stays exactly where it is, before the `tinkerweb_notebook` call.)

- [ ] **Step 4: Verify graceful halt (backend)**

```bash
proj=/Users/shan/PhpstormProjects/dc-boilerplate
run() { echo "$1" | php src/Runner/runner.php | python3 -c "import sys,json;e=json.load(sys.stdin);print('ok',e['ok'],'halted',e.get('halted'),'kind',e['kind'],'cells',[c['kind'] for c in e['cells']])"; }
echo "A dd mid-run:";  run "{\"project\":\"$proj\",\"code\":\"\$a=1; dd(\$a); \$a+1\"}"
echo "B die:";         run "{\"project\":\"$proj\",\"code\":\"\$x=5; die('bye')\"}"
echo "C exit:";        run "{\"project\":\"$proj\",\"code\":\"1+1; exit\"}"
echo "D normal:";      run "{\"project\":\"$proj\",\"code\":\"1+1; 2+2\"}"
```

Expected:
- A → `ok True halted True kind value cells ['value', 'halted']` (the `$a+1` after dd never runs; the halted cell carries the dumped `$a` in its `dumps`).
- B → `ok True halted True ... cells ['value', 'halted']`.
- C → `ok True halted True ... cells ['value', 'halted']`.
- D → `ok True halted None kind value cells ['value', 'value']` (no `halted` key).

Confirm every output is clean single-line JSON.

- [ ] **Step 5: Render the `halted` cell (app.js)**

In `resources/dist/app.js` `renderCell`, add a `halted` branch (its `output`/`dumps` are already rendered by the shared lines at the top of the function) before the final `else` value branch:

```javascript
  } else if (c.kind === 'halted') {
    html += `<pre class="note halt">⛔ execution stopped (dd)</pre>`;
  } else {
```

So the branch chain reads: `if (exception) … else if (no-value) … else if (halted) … else { value }`.

- [ ] **Step 6: Mark the run block as stopped (app.js)**

In `resources/dist/app.js` `render`, after computing the block class for the cells path (the line `block.className = 'run-block ' + (ok ? 'ok' : 'err');`), add a stopped modifier when the run halted:

```javascript
  block.className = 'run-block ' + (ok ? 'ok' : 'err');
  if (env.halted) { block.classList.add('stopped'); setStatus(`stopped · ${ms}ms`); }
  else setStatus(ok ? `ok · ${ms}ms` : `error · ${ms}ms`, !ok);
```

Remove the now-duplicated `setStatus(ok ? … : …)` line that previously followed (the block above replaces it — there must be exactly one `setStatus` for the cells path).

- [ ] **Step 7: Style the halt marker + stopped block (app.css)**

In `resources/dist/app.css`, add after the `.note` rule (~line 53):

```css
.note.halt { color: #ffa657; }
.run-block.stopped { outline: 1px solid #ffa657; outline-offset: 2px; }
```

- [ ] **Step 8: Verify frontend parses + suite green**

```bash
node --check resources/dist/app.js && echo "js ok"
vendor/bin/pest 2>&1 | tail -3
```

Expected: `js ok`; `31 passed`.

- [ ] **Step 9: Browser smoke (controller-run)**

Confirm in the browser: `dump(1); dd(2); dump(3)` shows dump `1`, then a halted cell with dump `2` and the "⛔ execution stopped (dd)" marker, and `3` never appears; the run block shows the stopped accent and status reads "stopped". If no browser tool, rely on the Step 4 backend verification and note interactive verification is deferred.

- [ ] **Step 10: Commit**

```bash
git add src/Runner/runner.php resources/dist/app.js resources/dist/app.css
git commit -m "feat: dd()/exit()/die() halt the run gracefully with a stop marker"
```

---

## Task 4: Mark the roadmap item shipped

**Files:**
- Modify: `docs/index.html`

**Interfaces:** none (documentation).

- [ ] **Step 1: Update the Collapsible result tree roadmap card**

In `docs/index.html`, the Roadmap grid has a card: `<div class="card"><h3>Collapsible result tree<span class="soon">soon</span></h3><p>Render VarDumper's interactive HTML dump for deep, clickable inspection of objects and arrays.</p></div>`. Remove the `<span class="soon">soon</span></h3>` badge (making it `<h3>Collapsible result tree</h3>`) since it now ships, and leave the description as-is.

- [ ] **Step 2: Verify**

Run: `grep -c 'Collapsible result tree</h3>' docs/index.html`
Expected: `1`.

- [ ] **Step 3: Commit**

```bash
git add docs/index.html
git commit -m "docs: mark collapsible result tree as shipped"
```

---

## Self-Review (author's check — completed)

- **Spec coverage:** vendored assets loaded once → Task 1. dump()/dd() rich `dumps[]` + rich values → Task 2. Graceful halt (dd/exit/die) + fatal distinction + `halted` cell/marker → Task 3. Roadmap card → Task 4. Security boundary (only VarDumper fragments injected; `output` escaped) → enforced in Task 2 Step 7 (`escapeHtml` on `output`, `uniqueizeDump` only on `dumps`/`result_html`). Unique-id safeguard → Task 2 Step 5.
- **Placeholder scan:** no TBD/TODO; every code step shows complete code. The only conditionals are the browser-smoke steps (Task 2 Step 10, Task 3 Step 9), explicitly flagged with an HTTP fallback.
- **Type/name consistency:** `$dumpSink` (object with `items: string[]`) introduced in Task 2 Step 1, consumed in Task 2 Steps 2–3 and Task 3 Steps 1–3; `tinkerweb_notebook` signature changes once in Task 2 (adds `$__dumpSink`) and once in Task 3 (adds `$__run`, returns `void`, writes `$__run->cells`) — call sites updated in the same tasks. Envelope keys (`dumps`, `halted`, cell kinds `value|no-value|exception|halted`) match between runner (Task 2/3) and `renderCell` (Task 2 Step 7, Task 3 Step 5). Frontend `uniqueizeDump`/`runScripts` defined in Task 2 Step 5–6 and used in Task 2 Step 7.
