# Design: CodeMirror 6 editor

Date: 2026-07-02

Replace the MVP `<textarea>` with a bundled CodeMirror 6 editor: PHP syntax highlighting, line
numbers, history, bracket matching, and a dark theme that matches the app. This is the roadmap's
"CodeMirror 6 editor" item and the **foundation** the later Auto-import and IntelliSense features
build on (a textarea can't host a completion popup).

Follow-up to v0.3.0. Autocomplete is deliberately **out of scope** here — this ships the editor
substrate only.

---

## Problem

The editor is a plain `<textarea id="editor">` ([resources/web/index.html](../../../resources/web/index.html)),
wired in `app.js` via `editor.value` (run + auto-run reads), a manual Cmd/Ctrl+↵ `keydown`, a
Tab-inserts-4-spaces handler, and the auto-run `input` listener. No syntax highlighting, no line
numbers, and it's the wrong host for the completion UI the next two features need.

## Goals

- A CodeMirror 6 editor with **PHP syntax highlighting**, `basicSetup` (line-number gutter,
  undo/redo history, code folding, bracket matching, active-line, multiple selections), and Tab
  indentation — preserving today's behaviors (⌘/Ctrl+↵ runs, Tab indents, auto-run on change,
  initial content `User::count()`).
- A **dark theme matching the app** palette so the editor blends with the existing UI.
- **No runtime build step and no runtime dependencies** — the bundle is built once at dev time and
  committed, like the vendored `sfdump.js`/`app.js`. Works offline (no CDN).

## Non-goals

- Autocomplete / IntelliSense / auto-import (later features; this is their substrate).
- A textarea fallback (the bundle is always committed and served).
- Multiple editor tabs / saved snippets.

---

## Architecture

### Build (dev-time, in CI) — bundle committed & served locally

CodeMirror 6 ships as ES modules on npm. To keep the **runtime** build-free and offline, bundle it
into a single self-contained IIFE and **commit that artifact**; the browser always loads it from the
local `127.0.0.1` server (never a remote/CDN origin — see Rationale).

- New `package.json` (dev-only) with **pinned** CM6 dependencies and a script:
  `"build:editor": "esbuild resources/editor/editor.src.js --bundle --format=iife --minify --outfile=resources/dist/editor.js"`.
  Dependencies (pinned): `codemirror` (provides `EditorView` + `basicSetup`), `@codemirror/lang-php`,
  `@codemirror/commands` (`indentWithTab`), `@codemirror/view`, `@codemirror/state`,
  `@codemirror/language` + `@lezer/highlight` (for the custom `HighlightStyle`), and `esbuild`
  (devDependency).
- `package-lock.json` is committed (reproducible builds). `node_modules/` is **gitignored**.
- **Build in CI:** a GitHub Action (`.github/workflows/build-editor.yml`) runs `npm ci && npm run
  build:editor` when the editor sources change, and commits the rebuilt `resources/dist/editor.js`
  back to the branch — so contributors need no local npm and the vendored bundle stays in sync.
  (Running `npm run build:editor` locally works too, for development.)
- The **built `resources/dist/editor.js` is committed** and ships in the composer package; runtime
  serves it via the existing `Server::serveAsset` (any file in `resources/dist/`), no server change.
- Toolchain confirmed available: node v23, npm 11, npx 11, npm registry reachable.

### Rationale — why the bundle is served locally, not from GitHub Pages

Loading the editor `<script>` from a remote origin (e.g. a Pages `cdn.js`) was rejected: this page
runs a session token and POSTs arbitrary PHP to `/eval` against the user's live app, so (1) a remote
script would break offline / airgapped / locked-down-network use (the editor silently fails with no
internet), (2) it expands the trust boundary into a supply-chain risk — anyone who could tamper with
the Pages content could inject JS into every user's PHP-executing session, and (3) a
composer-installed CLI could drift against a newer latest-on-Pages bundle (envelope-contract
mismatch). Shipping the bundle in the package and serving it from `127.0.0.1` keeps it offline-safe,
trust-contained, and version-matched. Building in CI gives the "all-in-one-repo, hands-off" benefit
without the runtime remote-load.

### Wrapper — one small, stable interface

`resources/editor/editor.src.js` (authored, bundled) imports CM6 and exposes exactly one global so
`app.js` never touches CM6 internals:

```js
window.TinkerEditor = {
  // parent: the container element; doc: initial text; onChange: fired on doc edit; onRun: ⌘/Ctrl+↵
  create(parent, { doc = '', onChange, onRun }) { /* → { getDoc(), setDoc(text), focus() } */ }
};
```

Extensions assembled in `create`:
- `basicSetup` — line numbers, history, folding, bracket matching, active-line, multi-selection.
- `php()` from `@codemirror/lang-php` — PHP highlighting.
- `keymap.of([indentWithTab])` — Tab indents (preserves current behavior; standard CM6).
- A **high-precedence** `Mod-Enter` keymap (`Prec.highest`) → calls `onRun()` and returns `true`,
  so it wins over basicSetup's default `Mod-Enter` binding. `Mod` = ⌘ on macOS, Ctrl elsewhere.
- `EditorView.updateListener.of(u => { if (u.docChanged) onChange?.() })` — drives auto-run.
- The custom theme + highlight style (below).

Returned handle: `getDoc()` → `view.state.doc.toString()`; `setDoc(text)` → a replace-all
transaction; `focus()` → `view.focus()`.

### Theme (custom, matches the app)

Two pieces (the "match the app" choice implies both):
- **Chrome** via `EditorView.theme({...}, {dark:true})` using the app's CSS variables so it stays in
  sync: `"&"` → `color: var(--text)`, `backgroundColor: var(--bg)`, fill height; `.cm-content` →
  `fontFamily: var(--mono)`, `caretColor: var(--accent)`; `.cm-gutters` → `var(--bg)` bg,
  `var(--muted)` text, no border; `.cm-activeLine`/`.cm-activeLineGutter` → a subtle panel tint;
  `&.cm-focused` → `outline: none`; selection → a translucent accent. (CM6 injects these as a
  stylesheet; `var(--…)` resolves against the `:root` vars app.css already defines.)
- **Syntax** via a dark `HighlightStyle` (`@codemirror/language` + `@lezer/highlight` tags) mapping
  common tokens to the app palette — keywords/functions `var(--accent)` (#6cc7ff), strings
  `var(--ok)` (#7ee787), comments `var(--muted)`, numbers a distinct blue, variables `var(--text)` —
  so tokens are legible on the dark background (basicSetup's default highlight is light-oriented).

## Integration with `app.js`

The editor surface collapses to one construction near the top:

```js
const editorApi = TinkerEditor.create($('#editor'), {
  doc: 'User::count()',
  onChange: scheduleAutorun,   // existing debounce (extracted from the old input listener)
  onRun:   () => run(false),
});
editorApi.focus();
```

- `run(live)` and the auto-run debounce read `editorApi.getDoc()` instead of `editor.value`
  (including the `editor.value !== lastCode` comparison → `editorApi.getDoc() !== lastCode`).
- The manual `keydown` handler (Cmd+↵ and Tab) is **removed** — CM6 owns both.
- The `input` listener is replaced by the `onChange` callback wired at construction; the debounce
  timer / sequence-guard / suppression logic is otherwise unchanged.
- `run()`'s "no project" and empty-buffer guards are unchanged.

## index.html / CSS

- `index.html`: `<textarea id="editor" …>User::count()</textarea>` → `<div id="editor"></div>`; add
  `<script src="/assets/editor.js"></script>` **before** `<script src="/assets/app.js">` (so
  `TinkerEditor` is defined when `app.js` runs). The initial content moves to the `doc` option.
- `app.css`: replace the `#editor` textarea rules with container layout — `#editor { flex: 1;
  min-height: 0; overflow: hidden; }` and let CM6 fill it (`.cm-editor { height: 100%; }`,
  `.cm-scroller { overflow: auto; }`). The `.editor-pane` flex layout is unchanged.

## Testing

- **Backend untouched** — the existing Pest suite (31 tests) must stay green.
- **Build** — `npm install` then `npm run build:editor` produces `resources/dist/editor.js`;
  `node --check resources/dist/editor.js` parses; grep confirms it defines `TinkerEditor`.
- **Behavior** — no JS unit harness (unchanged pattern); verified by driving the running app
  (controller browser smoke): PHP highlighting renders with the dark theme; ⌘/Ctrl+↵ runs; Tab
  indents; typing with Auto-run on re-evaluates; the notebook cells / collapsible dumps / halt
  marker from v0.2–v0.3 still render; line numbers + bracket matching present.

## Files touched

| File | Change |
| --- | --- |
| `package.json` | new, dev-only: pinned CM6 deps + `build:editor` esbuild script |
| `package-lock.json` | new, committed (reproducible builds) |
| `.github/workflows/build-editor.yml` | new: CI builds the bundle on editor-source changes and commits `resources/dist/editor.js` |
| `.gitignore` | add `node_modules/` |
| `resources/editor/editor.src.js` | new: CM6 wrapper exposing `window.TinkerEditor.create(...)` |
| `resources/dist/editor.js` | new, built + committed: the bundled IIFE |
| `resources/web/index.html` | `<textarea>` → `<div id="editor">`; load `editor.js` before `app.js` |
| `resources/dist/app.js` | construct `TinkerEditor`; `getDoc()` reads; remove manual keydown/Tab; `onChange`/`onRun` wiring |
| `resources/dist/app.css` | `#editor` container + `.cm-editor`/`.cm-scroller` layout; drop old textarea rules |

## Known limitations / notes

- Adds a **dev-time npm/esbuild build** (first in the repo), run in CI (or locally), solely to
  regenerate the vendored editor bundle. Runtime and end users still need only PHP; `node_modules/`
  is not committed, and the browser never loads the editor from a remote origin.
- The committed `editor.js` is ~647 KB minified/un-gzipped (CM6 + lang-php). Acceptable for a local tool,
  browser-cached after first load.
- `indentWithTab` captures Tab for indentation (a known CM6 accessibility trade-off) — it matches
  the current textarea behavior, so no regression.
- Rebuild the bundle (and bump the pinned versions) when upgrading CM6.
