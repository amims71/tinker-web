<?php

namespace Amims71\TinkerWeb\Runner;

/**
 * Spawns the standalone runner as a fresh subprocess rooted in the target project, hands it
 * {project, code} as JSON on stdin, and returns the decoded eval envelope from stdout.
 */
final class RunnerBridge
{
    public function __construct(
        private string $phpBinary,
        private string $runnerScript,
    ) {}

    /**
     * @return array<string,mixed> the eval envelope (always has an 'ok' key)
     */
    public function eval(string $project, string $code): array
    {
        $payload = json_encode(['project' => $project, 'code' => $code], JSON_INVALID_UTF8_SUBSTITUTE);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open([$this->phpBinary, $this->runnerScript], $descriptors, $pipes, $project);

        if (! is_resource($process)) {
            return $this->fail('Failed to start the runner process.');
        }

        fwrite($pipes[0], (string) $payload);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $envelope = json_decode((string) $stdout, true);

        if (! is_array($envelope)) {
            $detail = trim((string) ($stderr !== '' ? $stderr : $stdout));

            return $this->fail($detail !== '' ? $detail : 'The runner returned no result.');
        }

        return $envelope;
    }

    /** @return array<string,mixed> */
    private function fail(string $message): array
    {
        return ['ok' => false, 'kind' => 'runner-error', 'error' => ['class' => 'RuntimeException', 'message' => $message]];
    }
}
