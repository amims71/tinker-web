# Design: core code completion (IntelliSense, part A)

Date: 2026-07-02

Add editor autocompletion to the CodeMirror 6 editor (shipped v0.4.0) for the three high-value,
achievable cases: **class names**, **local variables**, and **static members after `Class::`**.
This is sub-project **A** of the roadmap's "Autocomplete / IntelliSense" item; instance-member
completion (`$var->`, which needs type inference) is sub-project **B**, a separate later spec that
reuses A's plumbing.

## Problem / goals

The CM6 editor has no completion. Users type class names, variables, and static calls by hand.
Deliver:
- **Class-name completion** from the target's Composer autoload data — **no Laravel boot**. Accept
  inserts the **fully-qualified** name (e.g. `\App\Models\User`). (Auto-import — inserting a short
  name + adding `use` — stays a separate roadmap item, per the scope decision.)
- **Local-variable completion** — client-side, from `$name` tokens already in the buffer. No backend.
- **Static-member completion** after `Class::` — public static methods, constants, and public
  static properties of the resolved class (incl. inherited), via reflection of that one class.
- A small static list of **PHP keywords**.

## Non-goals (this spec)

- **`$var->` instance-member completion / type inference** — sub-project B.
- Auto-import (short name + `use`) — separate roadmap item; here completion inserts the FQCN.
- App-defined global functions/helpers (need a full boot); docblock inference; snippet templates.

---

## Architecture

### Backend — a no-boot symbols source

New standalone runner `src/Runner/symbols.php`, spawned like the eval runner (JSON on stdin:
`{project, mode, class?}`; JSON envelope on stdout). Two modes, **neither full-boots** the target:

- **`classes`** — enumerate the target's class names without autoloading or booting:
  - `require $project/vendor/composer/autoload_classmap.php` (a plain `FQCN => file` array Composer
    always ships) → `array_keys()`.
  - **∪** a PSR-4 directory scan of the **non-vendor** prefixes from
    `$project/vendor/composer/autoload_psr4.php` (dirs not under `/vendor/`), walking `*.php` and
    deriving each FQCN from `prefix + relative-path`. This catches `App\` classes in non-optimized
    installs (where they aren't in the classmap). Vendor classes come from the classmap.
  - Return a sorted, de-duplicated `string[]` of FQCNs.
  - This is pure file I/O — no `require vendor/autoload.php`, no `bootstrap/app.php`.
- **`members`** — `require $project/vendor/autoload.php` (the **autoloader only**, not the app
  bootstrap), then `new ReflectionClass($fqcn)` and collect: public **static** methods
  (`getMethods` filtered `isStatic() && isPublic()`), class constants (`getReflectionConstants`
  public), and public static properties (`getProperties` `isStatic() && isPublic()`), including
  inherited members. Return `[{name, kind}]` where `kind ∈ {method, const, property}`. Any
  `Throwable` (class can't autoload / missing deps) → `{ok:true, members:[]}` (graceful empty).

Envelope always has `ok`; failures return `{ok:false, error:{message}}`. A `ClassScanner` helper
(`src/Runner/ClassScanner.php`) holds the boot-free class-enumeration logic (classmap read + PSR-4
scan) so it is **unit-testable** without a target boot.

### Server — two token-guarded routes

`Server` gains `POST /symbols` `{project}` → `{ok, classes: string[]}` and `POST /members`
`{project, class}` → `{ok, members: [{name,kind}]}`, both behind the existing `TokenGuard` and
Host-allowlist, delegating to a small `SymbolsBridge` (spawns `symbols.php`, decodes the envelope —
same shape as `RunnerBridge`). `RunnerBridge`/`runner.php` are unchanged.

### Frontend — CM6 completion sources in `app.js`

- **Editor bundle** (`editor.src.js`, rebuilt) gains a `complete` option on
  `TinkerEditor.create(parent, {doc, onChange, onRun, complete})`: when provided, the wrapper adds
  `autocompletion({override: [complete]})` to the extensions (CM6's autocomplete is already in
  `basicSetup`; `override` supplies our source). `complete` is a CM6 completion source
  `(context) => result | null`.
- **Completion logic lives in `app.js`** (it holds the fetched symbol data + `api()`), using only
  the `context` object CM6 passes (`context.matchBefore(re)`, `context.state.doc`, `context.pos`,
  `context.explicit`) and returning plain objects `{from, options: [{label, type, detail?, apply?}]}`
  — no CM6 import in `app.js`. Dispatched by what precedes the cursor:
  - **Static members** — if the text before the cursor matches `<FQCN-or-alias>::\w*`, resolve the
    class name to an FQCN using `use` statements / leading-`\` in the buffer, fetch `POST /members`
    (cached per class in a `Map`), return method/const/property options (`type: 'method'|'…'`).
  - **Class names** — else if the token is an identifier that could be a class (starts uppercase, or
    contains `\`), filter the cached class list (prefix/substring on the short name and FQCN),
    **rank** `App\*` → `Illuminate\*` → other, cap to a sane number (e.g. 50), `apply` inserts the
    FQCN (leading `\`).
  - **Local variables** — if the token starts with `$`, scan `context.state.doc` for `/\$[A-Za-z_]\w*/`
    matches, suggest the distinct names.
  - **Keywords** — merge a small static PHP keyword list (`return`, `function`, `foreach`, `new`,
    `use`, `fn`, `match`, `throw`, …) into the identifier case.
- **Symbol fetching/caching:** on editor construction (and whenever the project `<select>` changes),
  fetch `POST /symbols` once and cache the class list in a module variable (`~13k` FQCNs ≈ ~1 MB
  JSON on localhost, fetched once, filtered client-side per keystroke). Member lists are fetched
  lazily and cached per `project+class`. If a fetch fails, completion degrades to just local
  variables + keywords (no error surfaced on keystrokes).

## Data flow

1. Editor loads → `app.js` POSTs `/symbols {project}` → `symbols.php classes` reads classmap+PSR-4 →
   class list cached in the browser.
2. Keystroke → CM6 calls `complete(context)` → `app.js` picks a source from the preceding text →
   returns options synchronously (classes/vars/keywords) or awaits `/members` (static members).
3. Accepting a class option inserts its FQCN; a member option inserts the member name.

## Error handling

- `symbols.php`: bad project / missing classmap → `{ok:false,error}`; a member reflection throw →
  `{ok:true,members:[]}`. `SymbolsBridge` returns a `{ok:false}` envelope if the process yields no
  JSON (mirrors `RunnerBridge::fail`).
- Frontend: any `/symbols` or `/members` failure is swallowed (completion silently falls back);
  never block typing or pop an error toast on a keystroke.

## Testing

- **`ClassScannerTest` (Pest):** the class-enumeration helper against fixture dirs — classmap array
  read, PSR-4 scan deriving FQCNs from paths, union + de-dup + sort, and skipping vendor prefixes.
  Boot-free, so genuinely unit-testable.
- **`symbols.php` end-to-end:** invoke over stdin against `/Users/shan/PhpstormProjects/dc-boilerplate`
  — `classes` returns thousands incl. `App\…`; `members` for a known class returns its static
  methods/consts; JSON is clean; a bogus class → empty members.
- **Frontend:** no JS unit harness; verified by driving the app (controller browser smoke): typing a
  class prefix offers ranked FQCNs and accepting inserts the FQCN; `Class::` offers static members;
  `$` offers buffer variables; keywords appear; a failed symbols fetch degrades gracefully.
- Existing 31 Pest tests stay green; `RunnerBridge`/`runner.php`/eval envelope unchanged.

## Files touched

| File | Change |
| --- | --- |
| `src/Runner/ClassScanner.php` | new: boot-free class enumeration (classmap ∪ non-vendor PSR-4 scan) |
| `src/Runner/symbols.php` | new: standalone runner — `classes` (via ClassScanner) + `members` (reflect one class, autoloader-only) |
| `src/Runner/SymbolsBridge.php` | new: spawns `symbols.php`, decodes the envelope |
| `src/Server.php` | add token-guarded `POST /symbols` + `POST /members` routes |
| `resources/editor/editor.src.js` (+ rebuilt `resources/dist/editor.js`) | `create()` accepts a `complete` source → `autocompletion({override:[complete]})` |
| `resources/dist/app.js` | completion source (classes/vars/static-members/keywords), symbol fetch + cache, re-fetch on project change |
| `tests/Unit/ClassScannerTest.php` | new: unit tests for the class scanner |

## Known limitations (documented, acceptable)

- Class list is fetched whole (~1 MB for a large app) once per session and filtered client-side —
  fine on localhost; a server-side query endpoint is a future optimization.
- Static-member completion needs the class to autoload (its file + parents/traits); a class whose
  autoload throws yields no members (graceful). No full app boot, so no runtime-registered classes.
- Class-name resolution for `Class::` uses `use`/FQCN in the buffer only (no alias-less short-name
  guessing) — keeps it unambiguous; falls back to no member completion if unresolved.
- Instance members (`$var->`), auto-import, and app helper functions are out of scope (sub-project B
  / separate roadmap items).
