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
