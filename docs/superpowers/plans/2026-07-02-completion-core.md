# Core Code Completion (IntelliSense A) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add editor autocompletion for class names (→ FQCN), local variables, and static `Class::` members, backed by a no-boot symbols source.

**Architecture:** A standalone `symbols.php` runner (spawned per request, like the eval runner) enumerates classes without booting the target (Composer classmap ∪ non-vendor PSR-4 scan) and reflects one class for static members (autoloader only). Two token-guarded `Server` routes (`/symbols`, `/members`) expose it via a `SymbolsBridge`. The CM6 editor wrapper gains a `complete` option; the completion logic lives in `app.js` and uses the CM6 `context` object.

**Tech Stack:** PHP 8.2+ (Reflection, `proc_open`), Pest, CodeMirror 6 `@codemirror/autocomplete`, esbuild (rebuild the vendored bundle), vanilla JS.

## Global Constraints

- No target **boot** for completion: `classes` is pure file I/O (no autoloader); `members` requires the target's `vendor/autoload.php` (autoloader) but **not** `bootstrap/app.php`.
- Class completion inserts the **fully-qualified** name with a leading `\`. Auto-import (short name + `use`) is a separate roadmap item — out of scope.
- `$var->` instance members / type inference are **sub-project B** — out of scope here.
- Completion never blocks typing or surfaces an error on a keystroke; a failed `/symbols`|`/members` fetch degrades to local-vars + keywords only.
- `RunnerBridge`, `runner.php`, and the eval envelope are unchanged. The existing 31 Pest tests must stay green.
- No runtime build step; the editor bundle is rebuilt at dev time (esbuild) and the built `resources/dist/editor.js` is committed. Browser loads it locally (never a CDN).
- Namespaces: `Amims71\TinkerWeb\Runner\` → `src/Runner/`. Terse purpose-comments.
- Verification target (boots + has a big classmap): `/Users/shan/PhpstormProjects/dc-boilerplate`.
- Branch: `feat/completion-core` (already created and checked out).

---

## Task 1: `ClassScanner` — boot-free class enumeration (TDD)

**Files:**
- Create: `src/Runner/ClassScanner.php`
- Test: `tests/Unit/ClassScannerTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `ClassScanner::scan(string $project): string[]` — sorted, de-duped FQCNs from the Composer classmap ∪ a PSR-4 scan of non-vendor prefixes. Empty array when no composer autoload files exist.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/ClassScannerTest.php`:

```php
<?php

use Amims71\TinkerWeb\Runner\ClassScanner;

beforeEach(function () {
    $this->proj = sys_get_temp_dir().'/tw-scan-'.bin2hex(random_bytes(4));
    mkdir($this->proj.'/vendor/composer', 0700, true);
    mkdir($this->proj.'/app/Models', 0700, true);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->proj));
});

it('unions classmap classes with a PSR-4 scan of non-vendor dirs, sorted and de-duped', function () {
    $p = $this->proj;
    file_put_contents("$p/vendor/composer/autoload_classmap.php",
        '<?php return '.var_export(['Vendor\\Pkg\\Thing' => '/x', 'App\\Models\\User' => '/y'], true).';');
    file_put_contents("$p/vendor/composer/autoload_psr4.php",
        '<?php return '.var_export(['App\\' => ["$p/app"], 'Vendor\\' => ["$p/vendor/pkg/src"]], true).';');
    file_put_contents("$p/app/Models/Order.php", "<?php\nnamespace App\\Models;\nclass Order {}\n");

    $classes = ClassScanner::scan($p);

    expect($classes)->toContain('Vendor\\Pkg\\Thing');   // from classmap
    expect($classes)->toContain('App\\Models\\User');    // from classmap
    expect($classes)->toContain('App\\Models\\Order');   // from PSR-4 scan (not in classmap)
    $sorted = $classes;
    sort($sorted);
    expect($classes)->toBe($sorted);                     // sorted
    expect(count($classes))->toBe(count(array_unique($classes))); // de-duped
});

it('returns an empty array when there are no composer autoload files', function () {
    expect(ClassScanner::scan($this->proj))->toBe([]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/ClassScannerTest.php`
Expected: FAIL — class `ClassScanner` not found.

- [ ] **Step 3: Implement `ClassScanner`**

Create `src/Runner/ClassScanner.php`:

```php
<?php

namespace Amims71\TinkerWeb\Runner;

/** Enumerates a target's class names WITHOUT booting it: Composer classmap ∪ a PSR-4 scan of non-vendor dirs. */
final class ClassScanner
{
    /** @return string[] sorted, de-duped FQCNs */
    public static function scan(string $project): array
    {
        $project = rtrim($project, '/');
        $classes = [];

        // 1. Composer classmap — a plain FQCN => file array (covers vendor + optimized app classes).
        $classmap = $project.'/vendor/composer/autoload_classmap.php';
        if (is_file($classmap)) {
            $map = require $classmap;
            if (is_array($map)) {
                foreach (array_keys($map) as $fqcn) {
                    $classes[(string) $fqcn] = true;
                }
            }
        }

        // 2. PSR-4 scan of NON-vendor prefixes — catches app classes not present in the classmap.
        $psr4 = $project.'/vendor/composer/autoload_psr4.php';
        if (is_file($psr4)) {
            $prefixes = require $psr4;
            if (is_array($prefixes)) {
                foreach ($prefixes as $prefix => $dirs) {
                    foreach ((array) $dirs as $dir) {
                        if (str_contains($dir, '/vendor/')) {
                            continue; // vendor classes come from the classmap; skip the slow vendor walk
                        }
                        foreach (self::classesIn(rtrim((string) $dir, '/'), (string) $prefix) as $fqcn) {
                            $classes[$fqcn] = true;
                        }
                    }
                }
            }
        }

        $names = array_keys($classes);
        sort($names);

        return $names;
    }

    /** @return iterable<string> FQCNs derived from *.php files under $dir mapped to PSR-4 $prefix. */
    private static function classesIn(string $dir, string $prefix): iterable
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $rel = ltrim(substr($file->getPathname(), strlen($dir)), '/');
            $rel = substr($rel, 0, -4); // drop ".php"
            yield $prefix.str_replace('/', '\\', $rel);
        }
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/ClassScannerTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/pest`
Expected: all green (33 total).

- [ ] **Step 6: Commit**

```bash
git add src/Runner/ClassScanner.php tests/Unit/ClassScannerTest.php
git commit -m "feat: ClassScanner — boot-free class enumeration (classmap + PSR-4 scan)"
```

---

## Task 2: Symbols runner, bridge, and Server routes

**Files:**
- Create: `src/Runner/symbols.php`, `src/Runner/SymbolsBridge.php`
- Modify: `src/Server.php`, `bin/tinker-web`

**Interfaces:**
- Consumes: `ClassScanner::scan()` (Task 1).
- Produces:
  - `SymbolsBridge::classes(string $project): array` → `{ok, classes: string[]}`
  - `SymbolsBridge::members(string $project, string $class): array` → `{ok, members: [{name,kind}]}`
  - `POST /symbols {project}` and `POST /members {project, class}` on the server.

- [ ] **Step 1: Create the symbols runner**

Create `src/Runner/symbols.php`:

```php
<?php

/*
 * Standalone symbols runner for editor completion. Two modes, NEITHER boots the target app:
 *   {"mode":"classes","project":"…"}          -> {"ok":true,"classes":["Fqcn",…]}   (classmap ∪ non-vendor PSR-4)
 *   {"mode":"members","project":"…","class":"…"} -> {"ok":true,"members":[{"name":"…","kind":"method|const|property"},…]}
 * Input JSON on stdin; envelope JSON on stdout (always has an "ok" key).
 */

require_once __DIR__.'/ClassScanner.php';

use Amims71\TinkerWeb\Runner\ClassScanner;

$in = json_decode((string) stream_get_contents(STDIN), true) ?: [];
$project = rtrim((string) ($in['project'] ?? ''), '/');
$mode = (string) ($in['mode'] ?? '');

$emit = function (array $env): never {
    fwrite(STDOUT, json_encode($env, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
    exit(0);
};

if ($project === '' || ! is_dir($project.'/vendor')) {
    $emit(['ok' => false, 'error' => ['message' => 'Not a project: '.$project]]);
}

if ($mode === 'classes') {
    $emit(['ok' => true, 'classes' => ClassScanner::scan($project)]);
}

if ($mode === 'members') {
    $class = ltrim((string) ($in['class'] ?? ''), '\\');
    $autoload = $project.'/vendor/autoload.php';
    if ($class === '' || ! is_file($autoload)) {
        $emit(['ok' => true, 'members' => []]);
    }
    require $autoload; // the autoloader only — no bootstrap/app.php, no service providers
    try {
        $ref = new \ReflectionClass($class);
        $members = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->isStatic()) {
                $members[] = ['name' => $m->getName(), 'kind' => 'method'];
            }
        }
        foreach ($ref->getReflectionConstants() as $c) {
            if ($c->isPublic()) {
                $members[] = ['name' => $c->getName(), 'kind' => 'const'];
            }
        }
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            if ($p->isStatic()) {
                $members[] = ['name' => $p->getName(), 'kind' => 'property'];
            }
        }
        $emit(['ok' => true, 'members' => $members]);
    } catch (\Throwable $e) {
        $emit(['ok' => true, 'members' => []]); // class can't autoload / missing deps — degrade to empty
    }
}

$emit(['ok' => false, 'error' => ['message' => 'Unknown mode: '.$mode]]);
```

- [ ] **Step 2: Create `SymbolsBridge`**

Create `src/Runner/SymbolsBridge.php` (mirrors `RunnerBridge`):

```php
<?php

namespace Amims71\TinkerWeb\Runner;

/** Spawns symbols.php in the target to fetch class/member lists for editor completion. */
final class SymbolsBridge
{
    public function __construct(
        private string $phpBinary,
        private string $script,
    ) {}

    /** @return array<string,mixed> envelope (always has an 'ok' key) */
    public function classes(string $project): array
    {
        return $this->run(['project' => $project, 'mode' => 'classes']);
    }

    /** @return array<string,mixed> */
    public function members(string $project, string $class): array
    {
        return $this->run(['project' => $project, 'mode' => 'members', 'class' => $class]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function run(array $payload): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open([$this->phpBinary, $this->script], $descriptors, $pipes, (string) $payload['project']);
        if (! is_resource($process)) {
            return ['ok' => false, 'error' => ['message' => 'Failed to start the symbols process.']];
        }
        fwrite($pipes[0], (string) json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $env = json_decode((string) $stdout, true);

        return is_array($env) ? $env : ['ok' => false, 'error' => ['message' => 'The symbols process returned no result.']];
    }
}
```

- [ ] **Step 3: Wire `SymbolsBridge` into the Server constructor**

In `src/Server.php`, add the import near the other `use` (after line 8 `use Amims71\TinkerWeb\Runner\RunnerBridge;`):

```php
use Amims71\TinkerWeb\Runner\SymbolsBridge;
```

And add a constructor parameter (after `RunnerBridge $bridge`):

```php
    public function __construct(
        private TokenGuard $guard,
        private RunnerBridge $bridge,
        private SymbolsBridge $symbols,
        private ConnectionStore $connections,
        private string $resourcesDir,
    ) {}
```

- [ ] **Step 4: Add the routes + handlers**

In `src/Server.php`, add two arms to the `match (true)` in `route()` (after the `/eval` arm, line 104):

```php
            $request->method === 'POST' && $request->path === '/symbols' => $this->symbols($request),
            $request->method === 'POST' && $request->path === '/members' => $this->members($request),
```

And add the two handler methods (next to `eval()`):

```php
    private function symbols(Request $request): Response
    {
        $project = rtrim((string) ($request->json()['project'] ?? ''), '/');
        if (! $this->connections->isValidProject($project)) {
            return Response::json(['ok' => false, 'error' => ['message' => 'Not a Laravel project: '.$project]], 400);
        }

        return Response::json($this->symbols->classes($project));
    }

    private function members(Request $request): Response
    {
        $input = $request->json();
        $project = rtrim((string) ($input['project'] ?? ''), '/');
        $class = (string) ($input['class'] ?? '');
        if (! $this->connections->isValidProject($project)) {
            return Response::json(['ok' => false, 'error' => ['message' => 'Not a Laravel project: '.$project]], 400);
        }

        return Response::json($this->symbols->members($project, $class));
    }
```

- [ ] **Step 5: Construct `SymbolsBridge` in the CLI**

In `bin/tinker-web`, add the import near line 5 (`use Amims71\TinkerWeb\Runner\RunnerBridge;`):

```php
use Amims71\TinkerWeb\Runner\SymbolsBridge;
```

And change the bridge/server construction (lines 50–51) from:

```php
$bridge = new RunnerBridge(PHP_BINARY, $root.'/src/Runner/runner.php');
$server = new Server($guard, $bridge, $connections, $root.'/resources');
```

to:

```php
$bridge = new RunnerBridge(PHP_BINARY, $root.'/src/Runner/runner.php');
$symbols = new SymbolsBridge(PHP_BINARY, $root.'/src/Runner/symbols.php');
$server = new Server($guard, $bridge, $symbols, $connections, $root.'/resources');
```

- [ ] **Step 6: Verify the runner directly (backend, no browser)**

```bash
proj=/Users/shan/PhpstormProjects/dc-boilerplate
echo "{\"mode\":\"classes\",\"project\":\"$proj\"}" | php src/Runner/symbols.php \
 | python3 -c "import sys,json;e=json.load(sys.stdin);print('ok',e['ok'],'nclasses',len(e['classes']),'has App:', any(c.startswith('App\\\\') for c in e['classes']))"
echo "{\"mode\":\"members\",\"project\":\"$proj\",\"class\":\"Illuminate\\\\Support\\\\Str\"}" | php src/Runner/symbols.php \
 | python3 -c "import sys,json;e=json.load(sys.stdin);print('ok',e['ok'],'nmembers',len(e['members']),'sample',[m['name'] for m in e['members'][:5]])"
echo "{\"mode\":\"members\",\"project\":\"$proj\",\"class\":\"No\\\\Such\\\\Class\"}" | php src/Runner/symbols.php
```

Expected: classes → `ok True`, thousands of classes, `has App: True`; members for `Illuminate\Support\Str` → `ok True` with static methods; the bogus class → `{"ok":true,"members":[]}`. All clean single-line JSON.

- [ ] **Step 7: Run the suite (guard against regressions)**

Run: `vendor/bin/pest`
Expected: all green (33). `php -l src/Server.php` and `php -l bin/tinker-web` clean.

- [ ] **Step 8: Commit**

```bash
git add src/Runner/symbols.php src/Runner/SymbolsBridge.php src/Server.php bin/tinker-web
git commit -m "feat: /symbols + /members endpoints via a no-boot symbols runner"
```

---

## Task 3: Frontend completion (editor bundle + app.js)

**Files:**
- Modify: `resources/editor/editor.src.js` (+ rebuilt `resources/dist/editor.js`), `package.json`/`package-lock.json` (add `@codemirror/autocomplete`), `resources/dist/app.js`

**Interfaces:**
- Consumes: `POST /symbols` / `POST /members` (Task 2); `TinkerEditor.create(...)`.
- Produces: no external interface — completion in the editor.

- [ ] **Step 1: Add the autocomplete dep and a `complete` option to the wrapper**

Install the package (so it can be imported explicitly):

```bash
npm install @codemirror/autocomplete
```

In `resources/editor/editor.src.js`, add the import after line 5 (`import { keymap } from '@codemirror/view';`):

```js
import { autocompletion } from '@codemirror/autocomplete';
```

Change the `create` signature to accept `complete` (line 45) and add the extension. Replace:

```js
  create(parent, { doc = '', onChange, onRun } = {}) {
```

with:

```js
  create(parent, { doc = '', onChange, onRun, complete } = {}) {
```

and, inside the `extensions` array (after the `syntaxHighlighting(appHighlight)` line), add:

```js
          ...(complete ? [autocompletion({ override: [complete] })] : []),
```

- [ ] **Step 2: Rebuild the bundle**

Run: `npm run build:editor`
Expected: `resources/dist/editor.js` rewritten; `node --check resources/dist/editor.js` passes; `grep -c "TinkerEditor" resources/dist/editor.js` ≥ 1.

- [ ] **Step 3: Add the completion source + symbol cache to `app.js`**

In `resources/dist/app.js`, add near the other helpers (after `api()`, around line 21):

```js
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
```

- [ ] **Step 4: Pass `complete` to the editor and fetch symbols**

In `resources/dist/app.js`, add `complete: phpComplete` to the `TinkerEditor.create` options (in the object at line 182):

```js
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
```

Then replace the final `loadConnections();` line (195) with a version that also loads symbols for the selected project, and refetches on project change:

```js
loadConnections().then(() => loadSymbols(projectSel.value));
projectSel.addEventListener('change', () => { memberCache.clear(); loadSymbols(projectSel.value); });
```

- [ ] **Step 5: Verify the frontend parses + suite green**

Run:

```bash
node --check resources/dist/app.js && echo "js ok"
vendor/bin/pest 2>&1 | tail -3
```

Expected: `js ok`; `33 passed` (Task 1 added 2 tests).

- [ ] **Step 6: Browser smoke (controller-run; needs the app)**

Start `bin/tinker-web /Users/shan/PhpstormProjects/dc-boilerplate --no-open --port=889X` and confirm in the browser: typing a class prefix (e.g. `Str`) offers ranked FQCN suggestions and accepting inserts the FQCN (leading `\`); typing `$` offers variables present in the buffer; after a resolvable `Class::` (a `use`d or FQCN class) static methods/consts appear; PHP keywords appear; a failed symbols fetch (bad project) degrades to vars+keywords without an error; the v0.2–v0.4 behaviors (run, notebook, dumps, halt, highlighting) still work. If a browser tool is unavailable, verify `/symbols` and `/members` over HTTP (200 + expected JSON) and defer interactive verification to the controller.

- [ ] **Step 7: Commit**

```bash
git add resources/editor/editor.src.js resources/dist/editor.js package.json package-lock.json resources/dist/app.js
git commit -m "feat: editor completion for classes, variables, and static members"
```

---

## Self-Review (author's check — completed)

- **Spec coverage:** class enumeration (classmap ∪ non-vendor PSR-4) → Task 1 `ClassScanner`; `classes`/`members` no-boot runner → Task 2 `symbols.php`; `SymbolsBridge` + `/symbols`+`/members` routes + wiring → Task 2; CM6 `complete` option → Task 3 Step 1–2; class/var/static-member/keyword sources, FQCN insert, ranking, buffer-var scan, `use`/FQCN resolution, fetch-once+cache+refetch-on-project-change → Task 3 Step 3–4; graceful degradation → `loadSymbols`/`loadMembers` try/catch + `phpComplete` returning null. Backend testable helper → `ClassScannerTest` (Task 1).
- **Placeholder scan:** no TBD/TODO; every code step is complete. Only conditional is the browser smoke (Task 3 Step 6), flagged with an HTTP fallback.
- **Type/name consistency:** `ClassScanner::scan()` (Task 1) is used by `symbols.php` (Task 2). `SymbolsBridge::classes()/members()` (Task 2 Step 2) match the Server handlers (Task 2 Step 4) and the envelope keys (`classes`, `members`, `{name,kind}`) match what `app.js` reads (Task 3 Step 3). The Server constructor param order (guard, bridge, **symbols**, connections, resourcesDir) matches the `bin/tinker-web` call (Task 2 Steps 3, 5). `TinkerEditor.create`'s new `complete` option (Task 3 Step 1) matches the call site (Task 3 Step 4). `projectSel`, `api`, `debounceTimer`, `lastCode`, `autorunEl`, `run` are pre-existing globals reused unchanged.
```
