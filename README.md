# tinker-web

**A standalone local PHP scratchpad for _any_ Laravel/PHP project — in your browser.**

Run `tinker-web`, point it at any local Laravel app, and evaluate PHP against that app's booted
container from a browser editor — no per-project package to install. Think Tinkerwell, but a small
open-source local web app.

```
$ tinker-web ~/code/my-app
  tinker-web — a local scratchpad for any PHP project
  Serving:  http://127.0.0.1:51488/?t=…    (opens automatically)
```

## How it works

- A tiny **local HTTP server** (127.0.0.1 only, single-user) serves a browser UI with a CodeMirror 6 editor (PHP syntax highlighting).
- Each run spawns a **fresh PHP runner rooted in the target project** — it `require`s the target's
  `vendor/autoload.php`, boots that app, evaluates your snippet, and renders the result with the
  target's own `symfony/var-dumper` + Laravel Tinker casters (so Eloquent models/collections read
  nicely). A separate process per eval = clean isolation, and no dependency clash with the tool.
- The target needs **nothing installed** (it uses its own PsySH/Tinker if present, with a plain-eval
  fallback otherwise).

## Requirements

- PHP **8.2+** (to run the tool).
- A local Laravel app to target (any version). `laravel/tinker` in the target gives the nicest
  rendering but isn't required.

## Install

```bash
composer global require amims71/tinker-web     # then ensure ~/.composer/vendor/bin (or ~/.config/composer/vendor/bin) is on your PATH
```

Or clone and run directly:

```bash
git clone https://github.com/amims71/tinker-web && cd tinker-web && composer install
php bin/tinker-web /path/to/app
```

## Usage

```bash
tinker-web                 # target the current directory
tinker-web .               # same — the current directory (any relative path is resolved)
tinker-web /path/to/app    # target a specific project
tinker-web --port=9000     # fixed port (default: an OS-assigned ephemeral port)
tinker-web --no-open       # don't auto-open the browser
```

Add more projects from the toolbar; recent projects are remembered in `~/.config/tinker-web`.
Write PHP in the editor and press **⌘/Ctrl + ↵** to run — or flip on **Auto-run** to re-evaluate
live as you finish each statement. Snippets run as a **notebook**: each top-level statement becomes
its own result cell (state persists within a run), and `dump()`/`dd()` output and return values
render as VarDumper's **collapsible, interactive** HTML — click to expand Eloquent models and
arrays. `dd()`/`exit()`/`die()` stop the run cleanly with a marker instead of erroring. The editor
autocompletes the target's class names (inserting the FQCN), your buffer's variables, and static
`Class::` methods/constants — sourced from the Composer classmap and reflection, with no app boot.

## Security

`tinker-web` executes arbitrary PHP against the target app, so it is deliberately **local-only**:

- Binds to **`127.0.0.1`** exclusively (never `0.0.0.0`).
- Requires a **random per-session token** in the URL (constant-time compared).
- Enforces a **Host-header allowlist** (`127.0.0.1` / `localhost`) to blunt DNS-rebinding.

Only run it on your own machine against apps you trust. Rendering a model shows its attributes
(including hidden ones like tokens) — expected for a local scratchpad.

## Roadmap

- Instance-member completion after `$var->` (type inference) and auto-import — building on the class-name, variable, and static `Class::` completion that ships now.
- Warm runner daemon per target, plus a stateful REPL session (variables persist across evals).
- Refuse/warn when the target's `APP_ENV` is `production`.

## Testing

```bash
composer install && vendor/bin/pest
```

## License

MIT.
