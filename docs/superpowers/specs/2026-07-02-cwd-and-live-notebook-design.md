# Design: open-current-folder shorthand + live notebook eval

Date: 2026-07-02

Two features for tinker-web, to land before the previously-planned work:

1. **Open the current folder** with `tinker-web .` (and any relative path).
2. **Live re-run on statement completion**, with **notebook-style per-line results** —
   instead of clicking Run, finishing a statement re-evaluates the snippet and shows each
   top-level statement's result.

A third, larger capability (a **stateful persistent REPL**) is explicitly deferred and
recorded on the roadmap; see [Deferred work](#deferred-work).

---

## Feature 1 — `tinker-web .` and relative project paths

### Problem

The CLI already defaults to `getcwd()` when no project is given
([`bin/tinker-web`](../../../bin/tinker-web) around line 36). But passing an explicit path is
taken almost verbatim:

```php
$project = $project !== null ? rtrim($project, '/') : getcwd();
```

So `tinker-web .` stores the literal string `"."` in
`~/.config/tinker-web/connections.json`. That relative string:

- pollutes the remembered-connections list (a bare `.` in the dropdown),
- breaks when the same connection is reused from a different working directory,
- is fragile in the runner subprocess, which resolves paths against its own cwd.

### Change

Resolve the project argument to an absolute path before it is remembered or handed to the
runner.

Add a pure, static helper to `ConnectionStore` (the existing home for path normalization —
it already `rtrim`s trailing slashes):

```php
/** Resolve a possibly-relative path to an absolute one; fall back to the trimmed input. */
public static function resolve(string $path): string
{
    $path = rtrim($path, '/');
    $real = realpath($path);

    return $real !== false ? $real : $path;
}
```

- `realpath('.')` → absolute cwd; `realpath('./billing')`, `realpath('../app')` resolve too.
- Fallback to the trimmed input when `realpath()` returns `false` (non-existent path), so a
  wrong path still yields a sensible "Not a Laravel project: <path>" message downstream.

In `bin/tinker-web`, run an explicitly-provided project through the helper:

```php
$project = $project !== null ? ConnectionStore::resolve($project) : getcwd();
```

The no-argument case is unchanged: `getcwd()` is already absolute.

### Testing

`tests/Unit/ConnectionStoreTest.php` gains cases for `resolve()`:

- an existing relative path (e.g. `.` from a known cwd, or a temp dir reached relatively)
  resolves to the expected absolute path;
- a non-existent path falls back to the trimmed input unchanged;
- a trailing slash is stripped.

---

## Feature 2 — live re-run with notebook per-line results

### Behaviour

- A new **"Auto-run"** toggle in the header. **Default OFF.** State persisted in
  `localStorage` so it survives reloads. The Run button and ⌘/Ctrl+↵ continue to work
  regardless.
- With auto-run ON: typing in the editor schedules a debounced (~400 ms) evaluation. When it
  fires — auto-run on, buffer non-empty, and changed since the last run — the **whole snippet**
  is sent to `/eval`.
- The result renders as a **notebook**: one cell per top-level statement, each showing that
  statement's captured output and its value (or "no value" for statements like `foreach`,
  `echo`, assignments-used-as-statements without a displayed value). A runtime error stops the
  run at the offending cell and is shown there.

### Key constraint: statements need real terminators

A bare newline does **not** terminate a PHP statement. `User::count()` on one line followed by
`Order::count()` on the next is a *syntax error*, not two statements. Therefore:

- The statement **separator is a top-level `;`** — only the trailing expression may omit its `;`
  (exactly like `tinker`/PsySH). A block-closing `}` is **not** a separator: a `}` at depth 0 is
  ambiguous (it ends `foreach`/`if`, but also closes a closure body `$f = function () { … };` or
  one arm of `if (…) {} else {}` mid-statement), so cutting on it would produce invalid fragments
  like `else { … }`. Cutting on `;` only is safe and was validated against `if/else`, `try/catch`,
  closures, `match`, and `for (;;)`.
- A **newline is a re-run _trigger_**, not a separator. Pressing Enter (or any input) schedules a
  re-evaluation; whether the snippet then splits into 1 or N cells is decided by top-level `;`.

This means the "closer runs it" behaviour is realised through the runner's completeness parser:
an unfinished snippet (`foreach (…) {`) is reported incomplete and suppressed; once you type the
closer that makes it parse, it evaluates and renders.

**Known limitation (documented, acceptable for MVP):** because we split on `;` only, a statement
that follows a brace-terminated construct without an intervening `;` is absorbed into that
construct's cell — e.g. `foreach ([1,2] as $n) { echo $n; } 99` is one cell (runs correctly,
echoes `12`, but `99` gets no separate value cell). Accurate per-statement splitting for that
case needs a real AST and is folded into the future stateful-REPL work.

### Stateless architecture is preserved

Each eval still runs in a **fresh subprocess** that boots the target and is thrown away
([`runner.php`](../../../src/Runner/runner.php) lines ~29–34). State does **not** persist across
separate runs. Consequences the user has accepted:

- Each run is a clean slate; earlier runs' variables are gone.
- Side effects repeat on every run (an `Order::create(...)` inserts again) — which is why the
  toggle defaults OFF.

Within a **single** run, however, state accumulates across the snippet's statements (see below),
so `$u = User::first();` on one line is visible to `$u->name` on the next.

### Runner changes (`src/Runner/runner.php`)

The envelope becomes **cell-based**. New shape:

```json
{
  "ok": true,
  "kind": "value | incomplete | parse-error",
  "cells": [
    { "kind": "value | no-value", "output": "…", "result_text": "…", "result_html": "…" },
    { "kind": "exception", "output": "…", "error": { "class": "…", "message": "…" } }
  ],
  "laravel": "11.x"
}
```

- `kind: 'incomplete'` and `kind: 'parse-error'` carry an empty `cells` array (the frontend
  decides how to surface them per run mode).
- Top-level failures unrelated to user code (bad project, runner error) keep the existing
  `{ok:false, …}` shape.

Algorithm:

1. **Completeness / parse gate.** If `\Psy\CodeCleaner` is available (PsySH ships with
   `laravel/tinker`, near-universal for tinker targets), use it on the whole snippet:
   `clean()` returns `false` → `incomplete`; throws → `parse-error`. When PsySH is absent, fall
   back to a `token_get_all` brace/paren/bracket **balance check** for incompleteness; genuine
   parse errors then surface as an exception cell.
2. **Split** the (valid) snippet into top-level statements with `token_get_all` — no
   `TOKEN_PARSE` flag (must not throw on a trailing no-`;` expression) — tracking `{ ( [` /
   `} ) ]` depth and cutting **only at a `;` at depth 0** (never on `}`; see the constraint
   above). Depth tracking still covers all bracket kinds so a `;` inside `for (;;)`, parens, or
   arrays is not mistaken for a separator. `token_get_all` tokenizes strings, comments, and
   heredocs correctly, so a `;` inside them is ignored too.
3. **Evaluate each statement** in **one shared scope** so variables persist within the run:
   - Try as an expression: `eval('return ' . rtrim($stmt, ';') . ';')` — captures the value, and
     assignments still mutate the scope.
   - On `\ParseError` (control structures, `echo`, declarations), run the statement as-is:
     `eval($stmt . ';')` — no value.
   - Wrap each statement in its own `ob_start()`/`ob_get_clean()` to capture that cell's output.
   - Render values with the target's own VarDumper (the existing `$render` closure and Tinker
     casters), producing `result_text` + `result_html` per cell.
   - A `\Throwable` from a statement becomes that cell's `exception` and **stops** the run
     (subsequent statements are not evaluated), mirroring how a script aborts.

The loop's own locals use `__`-prefixed names to minimise collision with user variables (the
same class of limitation PsySH itself has).

This mechanic was validated with a throwaway spike: splitting keeps `"a;b;c"` intact, treats
`if/else`, `try/catch`, `match`, and a closure-assignment (`$f = function () { … };`) as single
valid cells, and correctly splits `$f = …; $f(21)` at the `;` after the closure (→ `42`);
`$a = 1; $b = $a + 2; $a + $b` yields cells `1`, `3`, `4`; and a mid-snippet `throw` stops the
run at that cell.

### Frontend changes

`resources/web/index.html`:

- Add an **Auto-run** toggle control in the `.conn`/header area (a labelled checkbox styled to
  match the existing header).

`resources/dist/app.css`:

- Style the toggle and the per-cell notebook layout (a subtle separator/index per cell; reuse
  the existing `.out`, `.value`, `.error`, `.note` styles inside each cell).

`resources/dist/app.js`:

- **Toggle wiring:** read/write `localStorage`, reflect state in the control, and (optionally)
  trigger one run when switched on.
- **Debounced auto-run:** an `input` listener that schedules a ~400 ms timer; on fire, if
  auto-run is on, the buffer is non-empty and differs from the last-run code, call the eval path.
- **Sequence guard:** tag each request with an incrementing id; ignore a response whose id is
  not the latest, so a slow earlier run can't overwrite a newer result.
- **Suppression:** in auto-run mode, `incomplete` and `parse-error` envelopes render nothing —
  only a subtle status (e.g. `… incomplete`) — and leave the previous cells in place. Manual Run
  still surfaces them as today.
- **Cell rendering:** `render()` builds a **run block** containing one sub-element per cell
  (each cell showing its `output` and `value`/`error`). **Auto-run coalesces**: if the top block
  is the current live auto-run block, replace it in place (continuous editing updates one block);
  otherwise create it. **Manual Run** always prepends a new, permanent block. Both modes show
  every cell, so `dump(1)` + `dump(2)` both appear.

### Testing

- Runner behaviour is exercised through a real target subprocess, so it is verified manually /
  via the app (the `/verify` and `/run` flows) rather than a unit test: single expression, a
  multi-statement chain that relies on persisted state within one run, a `foreach` (no-value
  cell with output), and a mid-snippet exception that stops the run.
- The `ConnectionStore::resolve()` helper (Feature 1) is unit-tested with Pest as above.
- Frontend logic is vanilla JS with no existing test harness; adding one is out of scope. The
  debounce/suppression/coalesce behaviour is verified by driving the running app.

---

## Deferred work

**Stateful persistent REPL.** The user wants true incremental evaluation — one long-lived,
already-bootstrapped process per target so variables persist across separate evals and each
statement runs exactly once (no repeated side effects), as a terminal `tinker` does. This is a
substantially larger change (process lifecycle, session/reset handling, per-connection state) and
is **deliberately not built now**. It builds directly on the existing **"Warm runner daemon"**
roadmap idea.

Action: add a **"Stateful REPL session"** card to the Roadmap in
[`docs/index.html`](../../../docs/index.html) (around line 199), describing persisted state across
evals and once-only statement execution, noting it extends the warm-daemon work.

---

## Files touched

| File | Change |
| --- | --- |
| `src/Connections/ConnectionStore.php` | new static `resolve()` helper |
| `bin/tinker-web` | resolve an explicit project arg via `ConnectionStore::resolve()` |
| `src/Runner/runner.php` | completeness gate + statement split + per-cell shared-scope eval; cell-based envelope |
| `resources/web/index.html` | Auto-run toggle control |
| `resources/dist/app.js` | toggle wiring, debounced auto-run, sequence guard, suppression, cell rendering + coalescing |
| `resources/dist/app.css` | toggle + notebook cell styling |
| `tests/Unit/ConnectionStoreTest.php` | tests for `resolve()` |
| `docs/index.html` | "Stateful REPL session" roadmap card |

## Out of scope / non-goals

- The stateful persistent REPL (deferred, above).
- Making `dd()` degrade gracefully (it dumps-and-dies; use `dump()` for multiple outputs).
- A JS test harness for the frontend.
- Any change to `RunnerBridge` or `Server` — both pass the envelope through untouched.
