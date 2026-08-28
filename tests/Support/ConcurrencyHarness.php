<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use RuntimeException;
use Throwable;

/**
 * Launches count($argvPerProcess) separate PHP CLI processes truly in parallel — proc_open forks a
 * real OS process per entry, each opening its own database connection, so this is genuine
 * process-level concurrency, not sequential calls or transactions sharing one connection.
 *
 * Every batch is held at a real ready/release handshake, not a fixed wall-clock buffer: each child
 * writes a readiness signal on fd 3 once it has forced its own PDO connection (see the
 * concurrency-children scripts), and blocks reading fd 4 until this class has received that signal
 * from every child in the batch, then releases all of them together. A fixed-timestamp buffer
 * (this class's original design) is only ever a statistical guess at worst-case boot/connect
 * latency — any child slower than the guess starts its store call late, silently understating
 * contention and producing a false-clean result. This class was corrected to a real handshake after
 * review of #93 found exactly that gap.
 *
 * Adapted from spikes/0037-isolation-level-concurrency/lib/harness.php (#37) — kept as a permanent
 * fixture here per #86's acceptance criteria, since the throwaway spike is what first established
 * this needed to be a real synchronized race, not merely separate processes.
 *
 * **On any failure — a child that fails to start, or the readiness handshake timing out —
 * `run()` forcibly terminates and reaps every child before propagating the error.** Without this,
 * a child already blocked reading its release pipe would stay blocked forever, and exception
 * unwinding would close descriptors in an unpredictable order, which could as easily release a
 * blocked child to mutate tables the test has already torn down as leave it hanging. See
 * `terminateAndReap()`.
 */
final class ConcurrencyHarness
{
    /**
     * Generous safety timeout for the readiness handshake: a child that never signals ready
     * indicates a real bug (crash, hang, misconfigured connection), not normal boot/connect
     * variance — unlike the old fixed-timestamp buffer, this is not load-bearing for correctness,
     * only for failing fast instead of hanging the test suite forever.
     */
    private const READY_TIMEOUT_SECONDS = 10.0;

    /**
     * Safety deadline for the mutation phase, after the batch is released (#359). Generous, because
     * this phase is real contended database work whose duration is the thing under test — but not
     * unbounded: a child that wedges after release (a lock it never acquires, a query that never
     * returns) previously held the drain loop until the CI job-level kill, which surfaces as an
     * opaque lane timeout instead of the hung child it is. Overridable per call so the harness's
     * own tests can prove the deadline without waiting on it.
     */
    private const MUTATION_TIMEOUT_SECONDS = 30.0;

    /**
     * @param  array<int, array<string, mixed>>  $argvPerProcess  one JSON-encodable payload per child
     * @return array<int, array{exit_code: int, stdout: string, stderr: string}>
     */
    public static function run(
        string $childScriptPath,
        array $argvPerProcess,
        ?float $mutationTimeoutSeconds = null,
    ): array {
        $processes = [];

        try {
            foreach ($argvPerProcess as $index => $payload) {
                $descriptorSpec = [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                    3 => ['pipe', 'w'], // child writes its readiness signal here
                    4 => ['pipe', 'r'], // child blocks reading its release signal here
                ];

                $cmd = ['php', $childScriptPath, json_encode($payload, JSON_THROW_ON_ERROR)];

                $process = proc_open($cmd, $descriptorSpec, $pipes);

                if ($process === false) {
                    throw new RuntimeException("Failed to start child process {$index}.");
                }

                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                stream_set_blocking($pipes[3], false);

                $processes[$index] = ['process' => $process, 'pipes' => $pipes, 'stdout' => '', 'stderr' => ''];
            }

            self::releaseWhenAllReady($processes);

            $remaining = array_keys($processes);
            $timeout = $mutationTimeoutSeconds ?? self::MUTATION_TIMEOUT_SECONDS;
            $deadline = microtime(true) + $timeout;

            while ($remaining !== []) {
                self::drainOutput($processes);

                $remaining = array_filter(
                    $remaining,
                    fn (int $index): bool => proc_get_status($processes[$index]['process'])['running'],
                );

                if ($remaining === []) {
                    break;
                }

                if (microtime(true) > $deadline) {
                    // Thrown, not returned, so run()'s existing catch routes it through
                    // terminateAndReap() — a child hung here is still holding its connection and
                    // its locks against tables the test is about to drop.
                    $stderr = array_filter(array_map(
                        fn (array $entry): string => trim($entry['stderr']),
                        $processes,
                    ));

                    throw new RuntimeException(sprintf(
                        'Timed out after %.1fs in the mutation phase waiting for %d child process(es) to exit — '
                        .'a child hung after release.%s',
                        $timeout,
                        count($remaining),
                        $stderr === [] ? '' : ' Child stderr: '.json_encode($stderr, JSON_THROW_ON_ERROR),
                    ));
                }

                usleep(5_000);
            }

            $results = [];

            foreach ($processes as $index => $entry) {
                $entry['stdout'] .= (string) stream_get_contents($entry['pipes'][1]);
                $entry['stderr'] .= (string) stream_get_contents($entry['pipes'][2]);

                fclose($entry['pipes'][1]);
                fclose($entry['pipes'][2]);
                fclose($entry['pipes'][3]);

                $exitCode = proc_close($entry['process']);

                $results[$index] = ['exit_code' => $exitCode, 'stdout' => $entry['stdout'], 'stderr' => $entry['stderr']];
            }

            return $results;
        } catch (Throwable $e) {
            self::terminateAndReap($processes);

            throw $e;
        }
    }

    /**
     * Blocks until every child in the batch has written a readiness signal to its pipe 3, then
     * releases all of them at once by closing this end of each child's pipe 4 — each child is
     * blocked on a read of its own pipe 4 that only returns (with EOF) once this happens. Nothing
     * is released until every child has proven, not assumed, that it is actually ready.
     *
     * @param  array<int, array{process: resource, pipes: array<int, resource>, stdout: string, stderr: string}>  $processes
     */
    private static function releaseWhenAllReady(array &$processes): void
    {
        $pending = array_keys($processes);
        $deadline = microtime(true) + self::READY_TIMEOUT_SECONDS;

        while ($pending !== []) {
            self::drainOutput($processes);

            foreach ($pending as $key => $index) {
                $signal = stream_get_contents($processes[$index]['pipes'][3]);

                if ($signal !== false && $signal !== '') {
                    unset($pending[$key]);
                }
            }

            if ($pending === []) {
                break;
            }

            if (microtime(true) > $deadline) {
                $stderr = array_filter(array_map(
                    fn (array $entry): string => trim($entry['stderr']),
                    $processes,
                ));

                throw new RuntimeException(sprintf(
                    'Timed out after %.1fs waiting for %d child process(es) to signal readiness — a child failed to boot or connect.%s',
                    self::READY_TIMEOUT_SECONDS,
                    count($pending),
                    $stderr === [] ? '' : ' Child stderr: '.json_encode($stderr, JSON_THROW_ON_ERROR),
                ));
            }

            usleep(200);
        }

        foreach ($processes as $entry) {
            fclose($entry['pipes'][4]);
        }
    }

    /**
     * Drain output during both the readiness handshake and the mutation phase. Without this, a
     * boot failure's stderr is lost on timeout and a verbose child can fill an undrained pipe
     * before it reaches its fd 3 readiness signal.
     *
     * @param  array<int, array{process: resource, pipes: array<int, resource>, stdout: string, stderr: string}>  $processes
     */
    private static function drainOutput(array &$processes): void
    {
        foreach ($processes as &$entry) {
            $entry['stdout'] .= (string) stream_get_contents($entry['pipes'][1]);
            $entry['stderr'] .= (string) stream_get_contents($entry['pipes'][2]);
        }
        unset($entry);
    }

    /**
     * Forcibly terminates and reaps every child process in the batch, then closes all of its
     * pipes. Used only on the failure path of run() — a startup error, or
     * releaseWhenAllReady()'s readiness timeout — never on the normal, all-ready path (which has
     * its own release and cleanup already). Without this, a child already blocked reading its
     * release pipe (fd 4) would stay blocked forever once run() throws, since nothing else closes
     * that pipe; simply letting exception unwinding drop the resources would close descriptors in
     * an unpredictable order, which could as easily release a blocked child to run its store call
     * against tables the test has already torn down as leave it hanging — either way an orphaned,
     * unreaped process. Sending SIGTERM first, before touching any pipe, guarantees a child is
     * killed rather than accidentally released by an incidental fd 4 close.
     *
     * @param  array<int, array{process: resource, pipes: array<int, resource>, stdout: string, stderr: string}>  $processes
     */
    private static function terminateAndReap(array $processes): void
    {
        foreach ($processes as $entry) {
            proc_terminate($entry['process']);
        }

        foreach ($processes as $entry) {
            foreach ($entry['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            proc_close($entry['process']);
        }
    }
}
