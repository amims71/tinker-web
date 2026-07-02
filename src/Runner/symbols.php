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
