# CodeMirror 6 Editor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the MVP `<textarea>` with a bundled CodeMirror 6 editor (PHP highlighting, `basicSetup`, dark theme matching the app), served locally.

**Architecture:** CM6 is bundled once (esbuild) into a self-contained IIFE committed at `resources/dist/editor.js`, exposing one global `window.TinkerEditor.create(...)`; the browser loads it from the local `127.0.0.1` server (never a remote origin). `app.js` talks only to that wrapper — construct the editor, read `getDoc()`, and wire `onRun`/`onChange`. A GitHub Action rebuilds the bundle when editor sources change.

**Tech Stack:** CodeMirror 6 (`codemirror` + `@codemirror/lang-php` + friends), esbuild (dev bundler), vanilla JS/CSS/HTML, PHP 8.2+ runtime (unchanged).

## Global Constraints

- **No runtime dependency and no runtime build step** — the browser loads the committed `resources/dist/editor.js` from `127.0.0.1`, never a CDN/remote origin (offline-safe, supply-chain-contained, version-matched).
- The build is **dev-time only**: `node_modules/` is gitignored; `package.json`/`package-lock.json` and the built `editor.js` are committed. Toolchain present: node v23, npm 11, npx 11; npm registry reachable.
- Preserve today's behaviors: ⌘/Ctrl+↵ runs, Tab indents, auto-run on change, initial content `User::count()`.
- Autocomplete/IntelliSense/auto-import are **out of scope** (later features).
- `Server`, `RunnerBridge`, and all PHP are **unchanged**; the existing Pest suite (31 tests) must stay green.
- Theme uses the app's `:root` CSS vars (`--bg` #0e1116, `--text` #d7dde5, `--muted` #8b95a3, `--accent` #6cc7ff, `--ok` #7ee787, `--mono`).
- Comments explain purpose in the surrounding terse style; no JS test harness (frontend verified by driving the app).
- Branch: `feat/codemirror-editor` (already created and checked out).

---

## Task 1: Build tooling, wrapper, vendored bundle, and CI

Produce the committed `editor.js` and the CM6 wrapper. The app is **not** wired to it yet (still the textarea), so the running app is unchanged after this task — this task's deliverable is the bundle itself.

**Files:**
- Create: `package.json`, `package-lock.json` (generated), `.gitignore` (append), `resources/editor/editor.src.js`, `resources/dist/editor.js` (built), `.github/workflows/build-editor.yml`

**Interfaces:**
- Consumes: nothing.
- Produces: global `window.TinkerEditor.create(parentEl, { doc?, onChange?, onRun? }) → { getDoc(): string, setDoc(text: string): void, focus(): void }`. `onRun` fires on ⌘/Ctrl+↵; `onChange` fires on every document edit.

- [ ] **Step 1: Ignore node_modules**

Append to `.gitignore`:

```
/node_modules/
```

- [ ] **Step 2: Create `package.json`**

Create `package.json`:

```json
{
  "name": "tinker-web-editor",
  "private": true,
  "type": "module",
  "scripts": {
    "build:editor": "esbuild resources/editor/editor.src.js --bundle --format=iife --minify --outfile=resources/dist/editor.js"
  }
}
```

- [ ] **Step 3: Install the CM6 deps + esbuild (populates versions + lockfile)**

Run (this writes `dependencies`/`devDependencies` into `package.json` with resolved versions and creates `package-lock.json`):

```bash
npm install codemirror @codemirror/state @codemirror/view @codemirror/commands @codemirror/lang-php @codemirror/language @lezer/highlight
npm install -D esbuild
```

Expected: `node_modules/` created, `package-lock.json` written, both `package.json` dependency blocks populated. If the registry is unreachable, STOP and report BLOCKED (the bundle can't be built offline).

- [ ] **Step 4: Author the wrapper `resources/editor/editor.src.js`**

Create `resources/editor/editor.src.js`:

```js
// CodeMirror 6 wrapper bundled (esbuild) into resources/dist/editor.js and served locally.
// Exposes one global so app.js never touches CM6 internals.
import { EditorView, basicSetup } from 'codemirror';
import { EditorState, Prec } from '@codemirror/state';
import { keymap } from '@codemirror/view';
import { indentWithTab } from '@codemirror/commands';
import { php } from '@codemirror/lang-php';
import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import { tags as t } from '@lezer/highlight';

// Chrome theme — uses the app's :root CSS vars so it stays in sync with the surrounding UI.
const appTheme = EditorView.theme(
  {
    '&': { color: 'var(--text)', backgroundColor: 'var(--bg)', height: '100%', fontSize: '13px' },
    '.cm-content': { fontFamily: 'var(--mono)', caretColor: 'var(--accent)', padding: '12px 0' },
    '.cm-cursor, .cm-dropCursor': { borderLeftColor: 'var(--accent)' },
    '&.cm-focused': { outline: 'none' },
    '.cm-selectionBackground, &.cm-focused .cm-selectionBackground, .cm-content ::selection': {
      backgroundColor: 'rgba(108,199,255,0.25)',
    },
    '.cm-gutters': { backgroundColor: 'var(--bg)', color: 'var(--muted)', border: 'none' },
    '.cm-activeLine': { backgroundColor: 'rgba(255,255,255,0.03)' },
    '.cm-activeLineGutter': { backgroundColor: 'rgba(255,255,255,0.04)', color: 'var(--text)' },
    '.cm-matchingBracket, &.cm-focused .cm-matchingBracket': {
      backgroundColor: 'rgba(108,199,255,0.2)', outline: 'none',
    },
    '.cm-scroller': { overflow: 'auto', fontFamily: 'var(--mono)' },
  },
  { dark: true }
);

// Syntax token colors legible on the dark background.
const appHighlight = HighlightStyle.define([
  { tag: [t.keyword, t.controlKeyword, t.moduleKeyword, t.operatorKeyword], color: 'var(--accent)' },
  { tag: [t.function(t.variableName), t.function(t.propertyName)], color: 'var(--accent)' },
  { tag: [t.string, t.special(t.string)], color: 'var(--ok)' },
  { tag: [t.number, t.bool, t.null], color: '#79c0ff' },
  { tag: [t.comment, t.lineComment, t.blockComment], color: 'var(--muted)', fontStyle: 'italic' },
  { tag: [t.variableName, t.propertyName], color: 'var(--text)' },
  { tag: [t.className, t.typeName, t.namespace], color: '#e3b341' },
  { tag: [t.operator, t.punctuation, t.bracket, t.separator], color: 'var(--muted)' },
]);

window.TinkerEditor = {
  create(parent, { doc = '', onChange, onRun } = {}) {
    // High precedence so Mod-Enter beats basicSetup's default binding.
    const runKey = Prec.highest(
      keymap.of([{ key: 'Mod-Enter', preventDefault: true, run: () => { if (onRun) onRun(); return true; } }])
    );
    const view = new EditorView({
      parent,
      state: EditorState.create({
        doc,
        extensions: [
          runKey,
          basicSetup,
          keymap.of([indentWithTab]),
          php(),
          appTheme,
          syntaxHighlighting(appHighlight),
          EditorView.updateListener.of((u) => { if (u.docChanged && onChange) onChange(); }),
        ],
      }),
    });
    return {
      getDoc: () => view.state.doc.toString(),
      setDoc: (text) => view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: text } }),
      focus: () => view.focus(),
    };
  },
};
```

- [ ] **Step 5: Build the bundle**

Run:

```bash
npm run build:editor
```

Expected: `resources/dist/editor.js` written (a minified IIFE, ~300–400KB).

- [ ] **Step 6: Verify the built bundle**

Run:

```bash
node --check resources/dist/editor.js && echo "parse ok"
grep -c "TinkerEditor" resources/dist/editor.js
ls -l resources/dist/editor.js
```

Expected: `parse ok`; `TinkerEditor` count ≥ 1; file size in the hundreds of KB. (The minified IIFE will show `TinkerEditor` where the global is assigned.)

- [ ] **Step 7: Add the CI workflow**

Create `.github/workflows/build-editor.yml`:

```yaml
name: Build editor bundle
on:
  push:
    paths:
      - 'resources/editor/**'
      - 'package.json'
      - 'package-lock.json'
      - '.github/workflows/build-editor.yml'
permissions:
  contents: write
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'
      - run: npm ci
      - run: npm run build:editor
      - name: Commit rebuilt bundle if changed
        run: |
          git config user.name "github-actions[bot]"
          git config user.email "github-actions[bot]@users.noreply.github.com"
          git add resources/dist/editor.js
          git diff --staged --quiet || git commit -m "chore: rebuild editor bundle [skip ci]"
          git push
```

- [ ] **Step 8: Confirm the PHP suite is unaffected**

Run: `vendor/bin/pest`
Expected: `31 passed` (nothing PHP changed).

- [ ] **Step 9: Commit**

```bash
git add .gitignore package.json package-lock.json resources/editor/editor.src.js resources/dist/editor.js .github/workflows/build-editor.yml
git commit -m "build: vendor a CodeMirror 6 editor bundle (esbuild) + CI to rebuild it"
```

---

## Task 2: Swap the frontend to the CodeMirror editor

Wire the app to `TinkerEditor`: replace the textarea, load the bundle, and route `app.js` through the wrapper. After this task the editor is live.

**Files:**
- Modify: `resources/web/index.html`
- Modify: `resources/dist/app.js`
- Modify: `resources/dist/app.css`

**Interfaces:**
- Consumes: `window.TinkerEditor.create(...)` from Task 1.
- Produces: no external interface.

- [ ] **Step 1: Replace the textarea and load the bundle (`index.html`)**

In `resources/web/index.html`, change the editor element (line 26) from:

```html
      <textarea id="editor" spellcheck="false" autofocus>User::count()</textarea>
```

to:

```html
      <div id="editor"></div>
```

And add the bundle `<script>` immediately before the `app.js` script (before line 40 `<script src="/assets/app.js"></script>`):

```html
  <script src="/assets/editor.js"></script>
```

- [ ] **Step 2: Container layout for the editor (`app.css`)**

In `resources/dist/app.css`, replace the `#editor` textarea rules (lines 40–44):

```css
#editor {
  flex: 1; resize: none; border: 0; outline: 0; padding: 16px;
  background: var(--bg); color: var(--text); font-family: var(--mono); font-size: 13px; line-height: 1.6;
  tab-size: 4;
}
```

with a fill-the-pane container (CM6 renders its own inner surface, themed by the bundle):

```css
#editor { flex: 1; min-width: 0; min-height: 0; overflow: hidden; }
#editor .cm-editor { height: 100%; }
#editor .cm-scroller { overflow: auto; }
```

- [ ] **Step 3: Point the editor element ref at the container (`app.js`)**

In `resources/dist/app.js`, change line 3:

```js
const editor = $('#editor');
```

to:

```js
const editorEl = $('#editor');
```

- [ ] **Step 4: Read the doc through the wrapper in `run()` (`app.js`)**

In `resources/dist/app.js`, change line 46 inside `run()`:

```js
  const code = editor.value;
```

to:

```js
  const code = editorApi.getDoc();
```

- [ ] **Step 5: Replace the manual keydown + input listeners with the editor construction (`app.js`)**

In `resources/dist/app.js`, delete the old editor `keydown` handler (lines 167–174):

```js
editor.addEventListener('keydown', (e) => {
  if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') { e.preventDefault(); run(false); }
  if (e.key === 'Tab') {
    e.preventDefault();
    const s = editor.selectionStart, en = editor.selectionEnd;
    editor.value = editor.value.slice(0, s) + '    ' + editor.value.slice(en);
    editor.selectionStart = editor.selectionEnd = s + 4;
  }
});
```

and delete the old editor `input` listener (lines 190–196):

```js
editor.addEventListener('input', () => {
  if (!autorunEl.checked) return;
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    if (autorunEl.checked && editor.value !== lastCode) run(true); // re-check: user may have toggled off during the wait
  }, 400);
});
```

Then, where the `input` listener was (after the autorun `change` handler block), construct the editor — CM6 owns ⌘/Ctrl+↵ (via `onRun`), Tab (via `indentWithTab`), and change events (via `onChange`):

```js
// --- editor: CodeMirror 6 via the vendored bundle (owns ⌘/Ctrl+↵, Tab, and change events) ---
const editorApi = TinkerEditor.create(editorEl, {
  doc: 'User::count()',
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
```

Leave `$('#run-btn').onclick = () => run(false);` and the autorun `change` handler unchanged (they already share `debounceTimer`/`run`). `editorApi` is module-scoped and only referenced at call time (after construction), so `run()` reading `editorApi.getDoc()` is safe.

- [ ] **Step 6: Verify the frontend parses + PHP suite green**

Run:

```bash
node --check resources/dist/app.js && echo "js ok"
vendor/bin/pest 2>&1 | tail -3
```

Expected: `js ok`; `31 passed`.

- [ ] **Step 7: Browser smoke (controller-run; needs the app)**

Start `bin/tinker-web /Users/shan/PhpstormProjects/dc-boilerplate --no-open --port=889X` and confirm in the browser: the editor renders as CodeMirror with **PHP syntax highlighting**, a **line-number gutter**, and the app's dark palette; **⌘/Ctrl+↵ runs**; **Tab indents**; editing with **Auto-run on** re-evaluates after the pause; the v0.2–v0.3 **notebook cells, collapsible dumps, and dd() halt marker still render**; bracket matching works. If a browser tool is unavailable, verify `/assets/editor.js` serves 200 and index.html references it, and note interactive verification is deferred to the controller.

- [ ] **Step 8: Commit**

```bash
git add resources/web/index.html resources/dist/app.js resources/dist/app.css
git commit -m "feat: replace the textarea with a CodeMirror 6 editor"
```

---

## Self-Review (author's check — completed)

- **Spec coverage:** vendored bundle + wrapper global → Task 1 (Steps 2–6); dev-only build + gitignore + committed artifact → Task 1 (Steps 1–3, 9); CI build → Task 1 (Step 7); `basicSetup` + `php()` + `indentWithTab` + high-precedence Mod-Enter + change listener → Task 1 (Step 4); custom dark theme + `HighlightStyle` using app vars → Task 1 (Step 4); textarea → div + load order + initial `doc` → Task 2 (Steps 1, 5); `getDoc()` replaces `editor.value` and manual keydown/Tab removed → Task 2 (Steps 4–5); `#editor` container CSS → Task 2 (Step 2); backend untouched / pest green → Task 1 Step 8, Task 2 Step 6.
- **Placeholder scan:** no TBD/TODO; every code step shows complete code. The only conditional is the browser smoke (Task 2 Step 7), flagged with an HTTP fallback.
- **Type/name consistency:** the wrapper interface (`create(parent, {doc,onChange,onRun}) → {getDoc,setDoc,focus}`) defined in Task 1 Step 4 is used exactly in Task 2 (`editorApi.getDoc()`, `editorApi.focus()`, `onRun`, `onChange`); `editorEl` (Task 2 Step 3) is the `create` parent (Step 5); `debounceTimer`/`lastCode`/`autorunEl`/`run` are pre-existing module globals reused unchanged.
