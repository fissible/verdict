<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Contracts\ExecutionWindow;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;

/**
 * Captures the statements a database connection executes inside a capability's execution window,
 * each as a {@see PredicateObservation} attributed to the envelope whose executor ran it.
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
 * The window is opened by `VerdictManager` through the {@see ExecutionWindow} seam — around
 * exactly the executor invocation, so Verdict's own store traffic (evidence, receipts, claims,
 * rate limits) runs outside it by construction and can never satisfy the presence assertion.
 * Windows nest: a capability executed from inside another's executor opens an inner frame, each
 * statement belongs to the innermost open frame, and closing a frame never disarms or absorbs the
 * one around it.
 *
 * Two capture rules keep the observation honest:
 *
 * - **Bindings are digested in prepared form** ({@see Connection::prepareBindings()}) —
 *   the form the database actually sees. `QueryExecuted` reports raw bindings, where a
 *   `DateTimeImmutable` would crash canonicalization from inside the event dispatch (after the
 *   statement already ran), and where `true` digests differently from the `1` the driver was
 *   handed. The authorized side of the equality comparison must derive its digest from
 *   prepared-form bindings for the same reason.
 * - **Pretended statements are ignored**: `QueryExecuted` fires under `Connection::pretend()`,
 *   but a pretended statement never executed, and an "executed predicate" observation of it would
 *   be false.
 *
 * Within a window everything is captured, writes included: assertions pick the statement they care
 * about by digest, and filtering here would be a normalization-adjacent judgment this instrument
 * refuses on the same prefer-false-failure grounds as {@see PredicateDigest}.
 *
 * Wiring (both registrations, at harness setup):
 *
 * ```php
 * $events->listen(QueryExecuted::class, $capture);          // observe every connection
 * $app->instance(ExecutionWindow::class, $capture);         // let core open the windows
 * ```
 *
 * Constructed with a {@see LiveToolCapture} sink, closed frames record straight into the run's
 * accumulator (the live path); without one, they collect here for `observations()`/`reset()` (the
 * deterministic path).
 */
final class ConnectionPredicateCapture implements ExecutionWindow
{
    /**
     * One frame per open window, innermost last; each holds the prepared statements observed
     * while it was the innermost.
     *
     * @var list<list<array{sql: string, bindings: array<array-key, mixed>}>>
     */
    private array $frames = [];

    /** @var list<PredicateObservation> */
    private array $observations = [];

    public function __construct(
        private readonly ?LiveToolCapture $sink = null,
    ) {}

    public function __invoke(QueryExecuted $event): void
    {
        if ($this->frames === [] || $event->connection->pretending()) {
            return;
        }

        $this->frames[array_key_last($this->frames)][] = [
            'sql' => $event->sql,
            'bindings' => $event->connection->prepareBindings($event->bindings),
        ];
    }

    /**
     * Closed in a `finally`: the statements that ran before an executor failure are still that
     * execution's, and leaving the frame open would hand them to whatever runs next.
     */
    public function around(ActionEnvelope $envelope, callable $execution): mixed
    {
        $this->frames[] = [];

        try {
            return $execution();
        } finally {
            $frame = array_pop($this->frames);
            $argumentFingerprint = ArgumentFingerprint::make($envelope->proposal->arguments);

            foreach ($frame as $statement) {
                /** @var array<array-key, bool|float|int|string|null> $bindings */
                $bindings = $statement['bindings'];
                $observation = PredicateObservation::fromQuery(
                    $statement['sql'],
                    $bindings,
                    $envelope->proposal->capability,
                    $argumentFingerprint,
                );

                if ($this->sink === null) {
                    $this->observations[] = $observation;
                } else {
                    $this->sink->recordPredicate($observation);
                }
            }
        }
    }

    /** @return list<PredicateObservation> */
    public function observations(): array
    {
        return $this->observations;
    }

    public function reset(): void
    {
        $this->observations = [];
    }
}
