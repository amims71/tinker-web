<?php

namespace Amims71\TinkerWeb\Connections;

/** Remembers recently-used target project paths in a user-level JSON file. */
final class ConnectionStore
{
    public function __construct(private string $file) {}

    public static function default(): self
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
        $dir = $home.'/.config/tinker-web';

        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return new self($dir.'/connections.json');
    }

    /** @return string[] absolute project paths, most-recent first */
    public function all(): array
    {
        if (! is_file($this->file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->file), true);

        return is_array($data) ? array_values(array_filter($data, 'is_string')) : [];
    }

    public function remember(string $project): void
    {
        $project = rtrim($project, '/');
        $all = array_values(array_filter($this->all(), fn (string $p): bool => $p !== $project));
        array_unshift($all, $project);

        @file_put_contents($this->file, json_encode(array_slice($all, 0, 20), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function isValidProject(string $project): bool
    {
        $project = rtrim($project, '/');

        return $project !== '' && is_file($project.'/vendor/autoload.php') && is_file($project.'/bootstrap/app.php');
    }
}
