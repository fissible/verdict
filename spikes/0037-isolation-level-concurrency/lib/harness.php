<?php

declare(strict_types=1);

/**
 * Launches count($argvPerProcess) separate PHP CLI processes truly in parallel — proc_open forks a
 * real OS process per entry, each opening its own database connection, so this is genuine
 * process-level concurrency, not sequential calls or transactions sharing one connection.
 *
 * @param  array<int, array<string, mixed>>  $argvPerProcess  one JSON-encodable payload per child
 * @return array<int, array{exit_code: int, stdout: string, stderr: string}>
 */
function spike_run_concurrent(string $childScriptPath, array $argvPerProcess): array
{
    $processes = [];

    foreach ($argvPerProcess as $index => $payload) {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cmd = ['php', $childScriptPath, json_encode($payload, JSON_THROW_ON_ERROR)];

        $process = proc_open($cmd, $descriptorSpec, $pipes);

        if ($process === false) {
            throw new RuntimeException("Failed to start child process {$index}.");
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $processes[$index] = ['process' => $process, 'pipes' => $pipes, 'stdout' => '', 'stderr' => ''];
    }

    $remaining = array_keys($processes);

    while ($remaining !== []) {
        foreach ($remaining as $index) {
            $processes[$index]['stdout'] .= (string) stream_get_contents($processes[$index]['pipes'][1]);
            $processes[$index]['stderr'] .= (string) stream_get_contents($processes[$index]['pipes'][2]);
        }

        $remaining = array_filter(
            $remaining,
            fn (int $index): bool => proc_get_status($processes[$index]['process'])['running'],
        );

        if ($remaining !== []) {
            usleep(5_000);
        }
    }

    $results = [];

    foreach ($processes as $index => $entry) {
        $entry['stdout'] .= (string) stream_get_contents($entry['pipes'][1]);
        $entry['stderr'] .= (string) stream_get_contents($entry['pipes'][2]);

        fclose($entry['pipes'][1]);
        fclose($entry['pipes'][2]);

        $exitCode = proc_close($entry['process']);

        $results[$index] = ['exit_code' => $exitCode, 'stdout' => $entry['stdout'], 'stderr' => $entry['stderr']];
    }

    return $results;
}
