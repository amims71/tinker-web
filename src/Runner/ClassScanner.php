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
