<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Illuminate\Database\Events\QueryExecuted;

/**
 * Captures the statements a database connection executes during an explicit window, each as a
 * {@see PredicateObservation}.
 *
 * The capture point is the connection, deliberately (#251 round 4). The where-tree a builder holds
 * sits above the last place the predicate can still change: global scopes and soft-delete
 * constraints are injected at query compilation, raw fragments can be appended after the tree was
 * inspected, and a second builder path may never have learned about a capture hook — a path like
 * that produces *no* digest, silence indistinguishable from nothing having run. Everything that
 * reaches the database goes through a connection, so a listener here is structural rather than
 * per-path opt-in, and {@see Assertions::executedPredicateObserved()} can treat a digest-less
 * execution as a failing case.
 *
 * Register it on the application's event dispatcher —
 * `$events->listen(QueryExecuted::class, $capture)` — which observes every connection, not one.
 * Statements outside a window are ignored, so registration can be process-long while capture stays
 * scoped to the execution under measurement. Within the window everything is captured, writes
 * included: assertions pick the statement they care about by digest, and filtering here would be a
 * normalization-adjacent judgment this instrument refuses on the same
 * prefer-false-failure grounds as {@see PredicateDigest}.
 *
 * Windows do not nest; this instrument shares `CapturingTool`'s single-shot assumption.
 */
final class ConnectionPredicateCapture
{
    /** @var list<PredicateObservation> */
    private array $observations = [];

    private bool $armed = false;

    public function __invoke(QueryExecuted $event): void
    {
        if (! $this->armed) {
            return;
        }

        $this->observations[] = PredicateObservation::fromQuery($event->sql, $event->bindings);
    }

    /**
     * Runs `$execution` with capture armed and returns its result. Disarms on the way out even
     * when the execution throws — a failed executor must not leave the instrument recording
     * unrelated statements as if they were the execution's.
     */
    public function window(callable $execution): mixed
    {
        $this->armed = true;

        try {
            return $execution();
        } finally {
            $this->armed = false;
        }
    }

    /** @return list<PredicateObservation> */
    public function observations(): array
    {
        return $this->observations;
    }

    /**
     * Returns the captured observations and leaves the capture empty. The live decorator drains
     * after each tool-call window so one call's statements are recorded against that call exactly
     * once, while the listener registration itself stays process-long.
     *
     * @return list<PredicateObservation>
     */
    public function drain(): array
    {
        $drained = $this->observations;
        $this->observations = [];

        return $drained;
    }

    public function reset(): void
    {
        $this->observations = [];
    }
}
