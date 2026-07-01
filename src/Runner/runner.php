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

// --- clean (implicit-return the last expression) + eval + capture ---
$cleaned = null;
if (class_exists(\Psy\CodeCleaner::class)) {
    try {
        $cleaned = (new \Psy\CodeCleaner())->clean([$code]);
    } catch (\Throwable $e) {
        $respond(['ok' => false, 'kind' => 'parse-error', 'error' => ['class' => get_class($e), 'message' => $e->getMessage()]]);
    }
    if ($cleaned === false) {
        $respond(['ok' => true, 'kind' => 'incomplete', 'output' => '', 'result_text' => '', 'result_html' => '']);
    }
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (! (error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

ob_start();
try {
    $value = $cleaned !== null ? eval($cleaned) : eval('return '.$code.';');
    $output = ob_get_clean();
    restore_error_handler();

    $isNoValue = class_exists(\Psy\CodeCleaner\NoReturnValue::class) && $value instanceof \Psy\CodeCleaner\NoReturnValue;
    $rendered = $isNoValue ? ['text' => '', 'html' => ''] : $render($value);

    $respond([
        'ok' => true,
        'kind' => $isNoValue ? 'no-value' : 'value',
        'output' => $output,
        'result_text' => $rendered['text'],
        'result_html' => $rendered['html'],
        'laravel' => $app->version(),
    ]);
} catch (\Throwable $e) {
    $output = ob_get_clean();
    restore_error_handler();

    $respond([
        'ok' => false,
        'kind' => 'exception',
        'output' => $output,
        'error' => ['class' => get_class($e), 'message' => $e->getMessage()],
    ]);
}
