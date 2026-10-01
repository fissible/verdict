<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;

// #544: genuine process-level proof that review issueAdmitted() serializes concurrent issuers for the
// same (capability, binding_fingerprint). Two separate OS processes on separate connections race a
// MISSING binding with distinct ids. The holder (A) is pinned INSIDE its admission hook — lock acquired,
// before its insert — and the observer then proves the contender (B) is blocked by A's lock
// (pg_blocking_pids) rather than running its own hook. A fix that acquires the lock only AFTER the
// admission read would let B read "missing" and attest before blocking — this test catches that, which
// the single-process "lock held at the hook" probe cannot. PostgreSQL-only (deterministic advisory-lock
// blocking + pg_blocking_pids); self-skips elsewhere, like the other concurrency suites.

const RAC_REVIEW_TABLE = 'verdict_review_requests';
const RAC_LOCKS_TABLE = 'verdict_binding_admission_locks';

/** @return array<string,mixed> */
function racConnectionConfig(): array
{
    $m = app(DatabaseManager::class);

    return config("database.connections.{$m->getDefaultConnection()}");
}

/** Spawn a worker; returns [proc, reportPipe(read), releasePipe(write)]. */
function racSpawn(array $payload): array
{
    $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'w'], 4 => ['pipe', 'r']];
    $child = __DIR__.'/../Support/concurrency-children/review-issue-admitted.php';
    $proc = proc_open([PHP_BINARY, $child, json_encode($payload, JSON_THROW_ON_ERROR)], $spec, $pipes);
    expect(is_resource($proc))->toBeTrue();
    stream_set_blocking($pipes[3], false);

    return [$proc, $pipes];
}

/** Read the next newline-delimited JSON event from a worker, or null within $deadline seconds. */
function racNextEvent(array &$pipes, float $deadline): ?array
{
    $buf = '';
    $end = microtime(true) + $deadline;
    while (microtime(true) < $end) {
        $chunk = fgets($pipes[3]);
        if ($chunk !== false && $chunk !== '') {
            $buf .= $chunk;
            if (str_contains($buf, "\n")) {
                return json_decode(trim($buf), true, flags: JSON_THROW_ON_ERROR);
            }

            continue;
        }
        usleep(5000);
    }

    return null;
}

function racCleanup(array $workers): void
{
    foreach ($workers as [$proc, $pipes]) {
        foreach ($pipes as $p) {
            if (is_resource($p)) {
                @fclose($p);
            }
        }
        if (is_resource($proc)) {
            @proc_terminate($proc);
            @proc_close($proc);
        }
    }
}

beforeEach(function (): void {
    if (app(DatabaseManager::class)->connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Deterministic admission-lock blocking requires PostgreSQL advisory locks + pg_blocking_pids.');
    }
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(RAC_REVIEW_TABLE);
    $schema->dropIfExists(RAC_LOCKS_TABLE);
    (require __DIR__.'/../../database/migrations/create_verdict_review_requests_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/create_verdict_binding_admission_locks_table.php.stub')->up();
});

afterEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(RAC_REVIEW_TABLE);
    $schema->dropIfExists(RAC_LOCKS_TABLE);
});

it('serializes concurrent review issuers for the same binding: only the admitted winner attests', function (): void {
    $conn = app(DatabaseManager::class)->connection();
    $binding = hash('sha256', 'binding-concurrent');
    $base = ['connection' => racConnectionConfig(), 'capability' => 'orders.cancel', 'binding_fingerprint' => $binding, 'at' => '2026-09-01 12:00:00'];

    $workers = [];
    try {
        // A: holds inside its admission hook (lock acquired, before insert).
        [$aProc, $aPipes] = racSpawn($base + ['id' => hash('sha256', 'A'), 'hold' => true]);
        $workers[] = [$aProc, $aPipes];
        expect(racNextEvent($aPipes, 10.0)['event'] ?? null)->toBe('pid');
        $aHook = racNextEvent($aPipes, 10.0);
        expect($aHook['event'] ?? null)->toBe('hook'); // A is now inside its hook, holding the lock
        $aPid = $aHook['pid'];

        // B: contends for the SAME binding with a distinct id, does not hold.
        [$bProc, $bPipes] = racSpawn($base + ['id' => hash('sha256', 'B'), 'hold' => false]);
        $workers[] = [$bProc, $bPipes];
        $bPidEvent = racNextEvent($bPipes, 10.0);
        expect($bPidEvent['event'] ?? null)->toBe('pid');
        $bPid = $bPidEvent['pid'];

        // Observer: B must become BLOCKED by A's lock, never run its own hook.
        $bAttested = false;
        $bBlockedByA = false;
        $end = microtime(true) + 10.0;
        while (microtime(true) < $end) {
            $ev = racNextEvent($bPipes, 0.1);
            if (($ev['event'] ?? null) === 'hook') {
                $bAttested = true;
                break;
            }
            if ((bool) $conn->selectOne('select (? = ANY(pg_blocking_pids(?))) as blocked', [$aPid, $bPid])->blocked) {
                $bBlockedByA = true;
                break;
            }
        }

        expect($bAttested)->toBeFalse()   // the losing contender must NOT attest
            ->and($bBlockedByA)->toBeTrue(); // it is serialized out, blocked on A's admission lock

        // Release A -> it inserts, commits, and wins.
        fclose($aPipes[4]);
        $aDone = racNextEvent($aPipes, 10.0);
        $bDone = racNextEvent($bPipes, 10.0);

        expect($aDone['ok'] ?? null)->toBeTrue()
            ->and($aDone['outcome'] ?? null)->toBe('issued')
            ->and($bDone['ok'] ?? null)->toBeTrue()
            ->and($bDone['outcome'] ?? null)->toBe('existing')      // B loses cleanly
            ->and($bDone['hook_ran'] ?? null)->toBeFalse()          // and never attested
            ->and($bDone['resolved_id'] ?? null)->toBe(hash('sha256', 'A')); // referencing the winner

        // Exactly one request persisted — the winner's.
        $rows = $conn->table(RAC_REVIEW_TABLE)->pluck('id')->all();
        expect($rows)->toBe([hash('sha256', 'A')]);
    } finally {
        racCleanup($workers);
    }
});

it('does not over-serialize: a different binding is not blocked while another is held', function (string $vary): void {
    // Rejects a global lock, and a key that omits either component: while A holds binding B1/cap C1
    // inside its hook, a contender on a DIFFERENT (capability, binding_fingerprint) must proceed
    // freely — acquire its own distinct lock, run its hook, and mint its own request.
    $conn = app(DatabaseManager::class)->connection();
    $heldBinding = hash('sha256', 'held-binding');
    $base = ['connection' => racConnectionConfig(), 'at' => '2026-09-01 12:00:00'];

    $otherCapability = $vary === 'capability' ? 'reports.export' : 'orders.cancel';
    $otherBinding = $vary === 'fingerprint' ? hash('sha256', 'other-binding') : $heldBinding;

    $workers = [];
    try {
        [$aProc, $aPipes] = racSpawn($base + ['id' => hash('sha256', 'A'), 'capability' => 'orders.cancel', 'binding_fingerprint' => $heldBinding, 'hold' => true]);
        $workers[] = [$aProc, $aPipes];
        expect(racNextEvent($aPipes, 10.0)['event'] ?? null)->toBe('pid');
        expect(racNextEvent($aPipes, 10.0)['event'] ?? null)->toBe('hook'); // A holds B1/C1

        // The contender on a different key must NOT block on A — it reaches its hook and completes.
        [$bProc, $bPipes] = racSpawn($base + ['id' => hash('sha256', 'B'), 'capability' => $otherCapability, 'binding_fingerprint' => $otherBinding, 'hold' => false]);
        $workers[] = [$bProc, $bPipes];
        expect(racNextEvent($bPipes, 10.0)['event'] ?? null)->toBe('pid');
        expect(racNextEvent($bPipes, 10.0)['event'] ?? null)->toBe('hook');  // ran its hook while A still holds
        $bDone = racNextEvent($bPipes, 10.0);

        expect($bDone['ok'] ?? null)->toBeTrue()
            ->and($bDone['outcome'] ?? null)->toBe('issued'); // minted its own distinct binding

        fclose($aPipes[4]); // release A
        expect(racNextEvent($aPipes, 10.0)['outcome'] ?? null)->toBe('issued');
    } finally {
        racCleanup($workers);
    }
})->with(['fingerprint' => 'fingerprint', 'capability' => 'capability']);
