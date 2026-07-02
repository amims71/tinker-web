# Design: dd()/dump() support — graceful halt + rich interactive dumps

Date: 2026-07-02

Make `dd()`, `dump()`, and raw `exit()`/`die()` behave well in the notebook, and render
dumps (and return values) as VarDumper's rich, collapsible, clickable HTML instead of plain
text. This deliberately folds in the roadmap's **"Collapsible result tree"** item, since a
usable `dd()` wants a rich dump.

This is a follow-up to the v0.2.0 notebook eval (`src/Runner/runner.php` + `resources/dist/app.js`).

---

## Problem

- **`dd()` is broken.** Laravel's `dd()` dumps each argument then calls `exit(1)`. In the
  notebook that kills the runner subprocess before `$respond()` writes the JSON envelope, so
  `RunnerBridge` falls back to a generic `runner-error` and the dumped value is lost. Raw
  `exit()`/`die()` have the same failure.
- **Dumps are plain text.** `dump()` currently routes through `VarDumper::setHandler` →
  `$render(...)['text']` → `echo`, landing in the cell's `output` as flat CLI text
  ([runner.php:79](../../../src/Runner/runner.php#L79)). Return values likewise render as escaped
  `result_text`; the `result_html` the runner already produces is never consumed by the frontend.
  Deeply-nested Eloquent models are an unreadable wall of text.

## Goals

1. `dd()`, `exit()`, `die()` **stop the run gracefully** — show what was dumped/output up to the
   halt, mark the run as stopped, and always return a valid envelope. A real fatal (out of
   memory, timeout) still surfaces as an error, not a fake "halt".
2. `dump()`/`dd()` output and return values render as VarDumper's **interactive collapsible
   HTML** (syntax-colored, click-to-expand), matching what `tinker`/Laravel users expect.

## Non-goals

- Redefining/patching `dd()` itself (the shutdown-net approach below makes that unnecessary).
- Streaming dumps as they happen (a run returns one envelope; dumps are batched per cell).
- Rich rendering of plain `echo`/`print` output — that stays escaped text (only VarDumper
  fragments are injected as HTML; see Security).

---

## Feature 1 — graceful halt (dd / exit / die)

### Approach: a shutdown net over a shared run-state holder

`dd()`, `exit()`, and `die()` all terminate the process uncatchably, so a per-statement
`try/catch` cannot see them. A `register_shutdown_function` can. Rather than special-case `dd()`
(e.g. pre-defining it), one net handles all three uniformly — `dd()` internally calls `exit`, so
it is caught by the same mechanism.

Introduce a small mutable **run-state holder** shared by the eval loop, the dump handler, and the
shutdown function (an object avoids PHP reference-reset gotchas):

```php
$run = new class {
    public array $cells = [];
    public bool $responded = false;
    public string $laravel = '';
};
```

- The eval loop appends each finished cell to `$run->cells` (instead of building a private array
  and returning it).
- **Normal completion:** set `$run->responded = true`, then `$respond([...])` writes the envelope
  and `exit(0)`.
- **Shutdown function** (registered before the loop):
  1. If `$run->responded` → return (normal path already responded).
  2. Drain every output-buffer level (`while (ob_get_level() > 0) $out .= ob_get_clean();`) — this
     captures the halted statement's `dd` dump / `die` message **and prevents PHP from
     auto-flushing the raw buffer into our stdout**, which would corrupt the JSON.
  3. Inspect `error_get_last()`: if it is a real fatal (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`,
     `E_COMPILE_ERROR`) → emit `{ok:false, kind:'runner-error', cells:$run->cells, error:{…}}`.
  4. Otherwise (clean `dd`/`exit`/`die`) → append a **`halted`** cell carrying the drained `$out`
     (plain) and any dumps collected for that statement (Feature 2), then emit
     `{ok:true, kind:'value', halted:true, cells:$run->cells, laravel:$run->laravel}`.

The shutdown function writes JSON directly with `fwrite(STDOUT, …)` (it cannot call `$respond`,
which `exit(0)`s — we are already in shutdown).

Per-statement catchable throwables keep their existing behavior (exception cell + `break`); the
net only handles uncatchable exits and true fatals.

**Verified** (spike against `dc-boilerplate`): `dump(1+1); dd("stop"); dump(999)` → cells
`[value 2, halted "stop"]`, `dump(999)` never runs; `die("bye")`/`exit()` → clean `halted` cell;
normal runs unaffected; emitted JSON is byte-clean with no leaked dump text.

---

## Feature 2 — rich interactive dumps (values + dump/dd)

### VarDumper HtmlDumper anatomy (verified)

- A full `HtmlDumper::dump($data, true)` is ~16 KB: a one-time **header** (`<script> Sfdump = … `
  + `<style>…</style>`) followed by `<pre class=sf-dump id=sf-dump-NNN>…</pre>` and a trailing
  `<script>Sfdump("sf-dump-NNN")</script>` init call.
- With `setDumpHeader('')` the per-item HTML is tiny (~200 B) and **still carries its trailing
  `Sfdump("id")` init script**.
- The header is idempotent (`Sfdump = window.Sfdump || (function(){…})`).

So: ship the header **once**, header-less fragments per item, and wire each fragment up client-side.

### Sfdump assets: vendored static file, loaded once (the optimization)

Rather than ship the ~16 KB header in every eval response, **vendor the Sfdump JS + CSS as a
static asset served by tinker-web and loaded once by `index.html`** (browser-cached across runs
and sessions). This is the cheapest option (zero per-eval overhead), matches the tool's existing
hand-vendored `app.js`/`app.css`, needs **no** `RunnerBridge`/`Server`/eval-envelope change
(`serveAsset` already serves any file from `resources/dist/` by basename), and the JS is trusted
(it's VarDumper's own, committed).

- Extract the header once **during implementation** (a full `HtmlDumper` dump, split at the first
  `<pre class=sf-dump`), split into its `<script>` body → `resources/dist/sfdump.js` and its
  `<style>` body → `resources/dist/sfdump.css`. Record the source symfony/var-dumper version in a
  header comment.
- `index.html` loads them once: `<link rel="stylesheet" href="/assets/sfdump.css">` and
  `<script src="/assets/sfdump.js"></script>` — so `window.Sfdump` is defined at page load, no
  runtime injection of the header needed.
- **Cross-version:** the `sf-dump` markup + `Sfdump(id)` API are stable across VarDumper v4–v7, so
  a vendored recent copy wires up dumps from any target. If a target's markup ever diverged, dumps
  still render (colored, formatted) — only the click-to-collapse wiring would no-op. Graceful.

### Runner changes

- **Values:** keep producing header-less `result_html` (already the case via `setDumpHeader('')`)
  and `result_text` (kept as an accessibility/copy fallback). The frontend now consumes
  `result_html`.
- **dump()/dd():** the `VarDumper::setHandler` callback pushes each dumped value's **header-less
  HTML** into a per-statement sink on the holder (e.g. `$run` gets a `dumps` scratch list that the
  loop reads and clears per statement), separate from plain `echo` output. The finished cell gets
  `dumps: string[]`. Because statements split on top-level `;`, `echo` and `dump` seldom coexist in
  one cell, so keeping them separate loses no meaningful ordering.
- The runner emits **no** dump assets — fragments are header-less; the page already has the
  vendored Sfdump JS/CSS.

### Envelope (new/changed fields)

```json
{
  "ok": true,
  "kind": "value | incomplete | parse-error",
  "halted": true,
  "cells": [
    { "kind": "value",     "output": "", "dumps": [], "result_text": "…", "result_html": "<pre class=sf-dump …>…</pre><script>Sfdump(\"…\")</script>" },
    { "kind": "no-value",  "output": "", "dumps": ["<pre class=sf-dump …>…</pre><script>…</script>"], "result_text": "", "result_html": "" },
    { "kind": "exception", "output": "…", "dumps": [], "error": { "class": "…", "message": "…" } },
    { "kind": "halted",    "output": "bye", "dumps": ["<pre …>…</pre><script>…</script>"] }
  ],
  "laravel": "13.x"
}
```

`halted` is optional (present only when applicable). `incomplete`/`parse-error` keep `cells: []`.
Non-user failures keep `{ok:false, …}`. `RunnerBridge`/`Server` remain untouched (pass-through);
the eval envelope carries no dump assets.

### Frontend changes (`app.js`, `app.css`, `index.html`)

- **Assets loaded once via `index.html`** (`<link>` + `<script src>` for the vendored
  `sfdump.css`/`sfdump.js`), so `window.Sfdump` is ready at page load — no per-response asset
  handling in `app.js`.
- **`injectRich(container, html)` helper:** rewrite the fragment's dump id to a page-unique value
  first (each `sf-dump-NNN` → a monotonic `sf-dump-tw-<n>`, in both the `id=` and the
  `Sfdump("…")` call) so dumps from different runs/cells never collide on `getElementById`; then set
  `container.innerHTML = html` and replace the fragment's trailing `<script>` with a freshly-created
  executing `<script>` (this runs `Sfdump(id)`, wiring up collapsing).
- **`renderCell`:**
  - plain `output` → escaped text (unchanged),
  - each `dumps[]` entry → `injectRich` (rich, collapsible),
  - value → `result_html` via `injectRich` instead of escaped `result_text` (fallback to escaped
    `result_text` if `result_html`/assets are absent),
  - `kind:"halted"` → render its `output`/`dumps` plus a clear **"⛔ execution stopped (dd)"**
    marker; style the run block as stopped (a distinct accent, not the red error accent).
- **CSS:** VarDumper's dark palette (`#18171B`) already suits the app's dark theme; add only minor
  wrapper spacing and the halted-marker style.

### Security (XSS)

- Only **VarDumper-produced fragments** (`dumps[]`, `result_html`) are injected as HTML / have
  their scripts re-executed. VarDumper HTML-escapes all dumped data, so a dumped string like
  `"<script>alert(1)</script>"` appears as escaped text inside the dump, not as a live tag. The only
  executable script in a fragment is its trailing `Sfdump("sf-dump-…")` init call (an id, no user
  data). The Sfdump definition itself is the vendored, committed `sfdump.js` (trusted), loaded via a
  normal `<script src>` — never injected from response data.
- Plain `echo`/`print` output (the cell `output` field) is **always escaped** and never injected
  as HTML — a user echoing a `<script>` tag renders as inert text. The script-re-execution helper
  is applied **only** to VarDumper fragments, never to `output`. This boundary is security-critical
  and must be preserved.

---

## Testing

- **Backend** is exercised by invoking the runner directly over stdin against a booting Laravel
  target (`/Users/shan/PhpstormProjects/dc-boilerplate`), matching the existing pattern (the runner
  has no unit test because it boots a full app). Scenarios: `dd()` mid-run halts and shows its dump;
  `die('x')`/`exit()` halt cleanly; a real fatal → `runner-error`; `dump()` produces a `dumps`
  entry containing `<pre class=sf-dump`; a return value carries `result_html`; emitted JSON is
  always clean single-line. (The runner boots a full app, so it has no unit test — matching the
  existing pattern.)
- **Asset extraction** (`sfdump.js`/`sfdump.css`) is a one-time implementation step, not runtime
  code; verify the vendored files contain the `Sfdump` definition and `pre.sf-dump` styles.
- **Frontend:** `node --check`; behavior verified by driving the running app (the controller does
  an HTTP + browser smoke test — the vendored assets load, dumps collapse/expand, ids don't
  collide across cells, the halted cell shows the stop marker, an echoed `<script>` stays inert).

## Files touched

| File | Change |
| --- | --- |
| `src/Runner/runner.php` | run-state holder; shutdown-net halt; handler collects header-less dump HTML into per-cell `dumps`; loop appends to holder |
| `resources/dist/sfdump.js`, `resources/dist/sfdump.css` | vendored Sfdump JS/CSS, extracted once from symfony/var-dumper (source version noted in a comment) |
| `resources/dist/app.js` | `injectRich` helper (unique-id rewrite + init-script re-exec); render `dumps`/`result_html`; `halted` cell |
| `resources/dist/app.css` | halted-marker + rich-dump wrapper styling |
| `resources/web/index.html` | `<link>`/`<script src>` for the vendored Sfdump assets |
| `docs/index.html` | mark "Collapsible result tree" roadmap card as shipped (or remove) |

## Known limitations (documented, acceptable)

- The Sfdump JS/CSS is vendored (committed) rather than sourced per-target: zero per-eval overhead
  and browser-cached, at the cost of a low cross-version drift risk. The `sf-dump` markup and
  `Sfdump(id)` API are stable across VarDumper v4–v7; a mismatch degrades gracefully (dump still
  renders, only click-to-collapse would no-op). Refresh the vendored copy if it ever drifts.
- Within a single statement, interleaved `echo` + `dump` ordering is not preserved (plain output
  and dumps are separate fields); rare, since statements split on `;`.
- If a target lacks symfony/var-dumper entirely, rich rendering falls back to escaped `result_text`.
