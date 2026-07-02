<?php

/*
 * Standalone runner: bootstrap an ARBITRARY Laravel project and evaluate one snippet in its
 * context, rendering with the TARGET's own symfony/var-dumper (+ Tinker casters when present).
 * Runs as a fresh subprocess rooted in the target, so the tinker-web tool's code never clashes
 * with the target's autoloader. Input (JSON on stdin): {"project": "...", "code": "..."}.
 * Output (JSON on stdout): the eval envelope.
 */

use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;
use Amims71\TinkerWeb\Runner\StatementSplitter;

require_once __DIR__.'/StatementSplitter.php';

$raw = stream_get_contents(STDIN) ?: '';
$in = json_decode($raw, true) ?: [];
$project = rtrim((string) ($in['project'] ?? ''), '/');
$code = (string) ($in['code'] ?? '');

$respond = function (array $envelope): never {
    fwrite(STDOUT, json_encode($envelope, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
    exit(0);
};

if ($project === '' || ! is_file($project.'/vendor/autoload.php') || ! is_file($project.'/bootstrap/app.php')) {
    $respond(['ok' => false, 'error' => ['class' => 'RuntimeException', 'message' => 'Not a Laravel project: '.$project]]);
}

chdir($project);
require $project.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $project.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// --- prepare renderers from the target's own vendored symfony/var-dumper ---
$casters = [];
if (class_exists(\Laravel\Tinker\TinkerCaster::class)) {
    $map = [
        'Illuminate\Support\Collection' => 'castCollection',
        'Illuminate\Database\Eloquent\Model' => 'castModel',
        'Illuminate\Support\Stringable' => 'castStringable',
        'Illuminate\Support\HtmlString' => 'castHtmlString',
        'Illuminate\Process\ProcessResult' => 'castProcessResult',
        'Illuminate\Foundation\Application' => 'castApplication',
    ];
    foreach ($map as $class => $method) {
        if (class_exists($class) && method_exists(\Laravel\Tinker\TinkerCaster::class, $method)) {
            $casters[$class] = [\Laravel\Tinker\TinkerCaster::class, $method];
        }
    }
}

$render = function ($value) use ($casters): array {
    $cloner = new VarCloner();
    if ($casters !== []) {
        $cloner->addCasters($casters);
    }
    $data = $cloner->cloneVar($value);

    $cli = new CliDumper();
    $cli->setColors(false);

    $html = new HtmlDumper();
    $html->setDumpHeader('');           // suppress the ~16KB per-dump prelude; ship Sfdump assets once

    return [
        'text' => rtrim((string) $cli->dump($data, true)),
        'html' => (string) $html->dump($data, true),
    ];
};

// dump()/dd() default to writing straight to php://stdout, which — unlike echo — ob_start()
// cannot intercept; route them through $render+echo instead so the output lands in the cell.
unset($_SERVER['VAR_DUMPER_FORMAT']); // else setHandler() no-ops and dump() writes raw to stdout, corrupting our JSON
\Symfony\Component\VarDumper\VarDumper::setHandler(static function ($value) use ($render): void {
    echo $render($value)['text'], "\n";
});

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
    $__preamble = '';

    foreach ($__statements as $__stmt) {
        // use/namespace declarations don't carry across separate eval() units — replay the
        // effective ones before each statement, and show a no-value cell for the declaration.
        if (StatementSplitter::isDeclaration($__stmt)) {
            $__preamble .= StatementSplitter::preambleFor($__stmt);
            $__cells[] = ['kind' => 'no-value', 'output' => '', 'result_text' => '', 'result_html' => ''];
            continue;
        }

        $__body = rtrim(trim($__stmt), ';');
        ob_start();
        try {
            try {
                // An expression (incl. assignment) yields a value AND mutates the shared scope.
                $__value = eval($__preamble.'return '.$__body.';');
                $__hasValue = true;
            } catch (\ParseError $__pe) {
                // Not an expression (control structure, echo, declaration) — run as-is, no value.
                eval($__preamble.$__stmt.';');
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
