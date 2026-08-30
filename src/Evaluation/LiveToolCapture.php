<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use LogicException;

final class LiveToolCapture
{
    /** @var list<ToolObservation> */
    private array $calls = [];

    /** @var list<ToolObservation> */
    private array $preflightAttempts = [];

    /** @var list<string> */
    private array $sideEffects = [];

    /** @var list<ChallengeObservation> */
    private array $challenges = [];

    /** @var list<PredicateObservation> */
    private array $predicates = [];

    /** @var list<ResourceObservation> */
    private array $resources = [];

    /** @var array<string, array<string, array<string, int>>> */
    private array $resourceOccurrences = [];

    private int $executionSequence = 0;

    /** @var array<int, true> */
    private array $reservedExecutionSequences = [];

    /** @var array<int, true> */
    private array $committedExecutionSequences = [];

    /**
     * `CapturingTool::handle()` is synchronous and nested handles return in LIFO order. A
     * checkpoint commit therefore belongs to the innermost active tool and its observation is
     * consumed before an outer handle can record. This counter is not safe for concurrent or
     * deferred tool execution; such a path needs an execution-scoped correlation token.
     */
    private int $capturingToolDepth = 0;

    private ?string $invocationId = null;

    /**
     * A tool call that reached `handle()`. Recorded in the order the tools actually ran.
     */
    /**
     * @param  list<string>  $matchedRegisteredSecrets
     * @param  list<string>  $registeredSecretLabels
     */
    public function record(
        string $capability,
        string $argumentFingerprint,
        ?Disposition $disposition,
        bool $executed,
        array $matchedRegisteredSecrets = [],
        array $registeredSecretLabels = [],
    ): void {
        $committedExecution = $this->capturingToolDepth > 0
            ? $this->consumeCommittedExecution($capability, $argumentFingerprint)
            : null;

        if ($committedExecution !== null) {
            $checkpoint = $this->calls[$committedExecution];
            array_splice($this->calls, $committedExecution, 1, [new ToolObservation(
                capability: $checkpoint->capability,
                argumentFingerprint: $checkpoint->argumentFingerprint,
                disposition: $checkpoint->disposition,
                executed: $checkpoint->executed,
                matchedRegisteredSecrets: $matchedRegisteredSecrets,
                registeredSecretLabels: $registeredSecretLabels,
                executionSequence: $checkpoint->executionSequence,
            )]);

            return;
        }

        $this->calls[] = new ToolObservation(
            $capability,
            $argumentFingerprint,
            $disposition,
            $executed,
            $matchedRegisteredSecrets,
            $registeredSecretLabels,
            ++$this->executionSequence,
        );
    }

    /**
     * A tool call observed at the approval preflight — it never reached `handle()`, because the
     * challenge it issued paused the run.
     *
     * Kept in its own list rather than appended to the handle-path records because the two are
     * written in the opposite order to the one they happen in.
     * `TextGenerationLoop::approvalAwareToolResults()` runs `approvalForTool()` — and therefore
     * this preflight — for EVERY tool call in a step before executing any of the step's non-gated
     * tools. So a gated attempt is always captured before the same step's executions, even though
     * the pause it produced is what ends the step and, in a single-shot trial, the run.
     * {@see toolObservations()} restores execution order.
     */
    /**
     * @param  list<string>  $matchedRegisteredSecrets
     * @param  list<string>  $registeredSecretLabels
     */
    public function recordPreflightAttempt(
        string $capability,
        string $argumentFingerprint,
        ?Disposition $disposition,
        bool $executed,
        array $matchedRegisteredSecrets = [],
        array $registeredSecretLabels = [],
    ): void {
        $this->preflightAttempts[] = new ToolObservation(
            $capability,
            $argumentFingerprint,
            $disposition,
            $executed,
            $matchedRegisteredSecrets,
            $registeredSecretLabels,
            ++$this->executionSequence,
        );
    }

    public function recordSideEffect(string $effect): void
    {
        $this->sideEffects[] = $effect;
    }

    public function reset(): void
    {
        $this->calls = [];
        $this->preflightAttempts = [];
        $this->sideEffects = [];
        $this->challenges = [];
        $this->predicates = [];
        $this->resources = [];
        $this->resourceOccurrences = [];
        $this->executionSequence = 0;
        $this->reservedExecutionSequences = [];
        $this->committedExecutionSequences = [];
        $this->capturingToolDepth = 0;
        $this->invocationId = null;
    }

    /**
     * Both lists count. A run that paused before any tool could execute captured an attempt, not
     * nothing — reading it as empty would report a gate that fired as `ModelDeclinedToAct`.
     *
     * Predicate observations deliberately do not count: a captured statement with no captured
     * tool call means some query ran inside a window, not that the model invoked a bound tool.
     */
    public function isEmpty(): bool
    {
        return $this->calls === [] && $this->preflightAttempts === [];
    }

    /**
     * Every observed tool call, in execution order: the handle-path records first, then the
     * preflight attempts. A challenge-backed attempt is terminal in execution order even though
     * it was captured first — see {@see recordPreflightAttempt()}.
     *
     * @return list<ToolObservation>
     */
    public function toolObservations(): array
    {
        return [...$this->calls, ...$this->preflightAttempts];
    }

    /** @return list<string> */
    public function sideEffects(): array
    {
        return $this->sideEffects;
    }

    public function recordChallenge(ChallengeObservation $challenge): void
    {
        $this->challenges[] = $challenge;
    }

    /** @return list<ChallengeObservation> */
    public function challenges(): array
    {
        return $this->challenges;
    }

    /**
     * A statement the connection listener observed during a tool's execution window.
     */
    public function recordPredicate(PredicateObservation $predicate): void
    {
        $this->predicates[] = $predicate;
    }

    /** @return list<PredicateObservation> */
    public function predicates(): array
    {
        return $this->predicates;
    }

    /**
     * Reserve the sequence a resource observation will use before its executor begins.
     *
     * The reservation is intentionally not itself an execution observation: a thrown executor
     * must leave its pre-execution resource digest unpaired rather than looking completed.
     */
    public function reserveExecution(): int
    {
        $sequence = ++$this->executionSequence;
        $this->reservedExecutionSequences[$sequence] = true;

        return $sequence;
    }

    /**
     * Commit a previously reserved execution after its executor returned successfully.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function commitExecution(int $sequence, string $capability, array $arguments): void
    {
        if (! isset($this->reservedExecutionSequences[$sequence])) {
            throw new LogicException("Execution sequence [{$sequence}] was not reserved.");
        }

        $this->calls[] = new ToolObservation(
            capability: $capability,
            argumentFingerprint: ArgumentFingerprint::make($arguments),
            disposition: Disposition::Permit,
            executed: true,
            executionSequence: $sequence,
        );
        if ($this->capturingToolDepth > 0) {
            $this->committedExecutionSequences[$sequence] = true;
        }
    }

    /** @param callable(): mixed $callback */
    public function whileCapturingTool(callable $callback): mixed
    {
        $this->capturingToolDepth++;

        try {
            return $callback();
        } finally {
            $this->capturingToolDepth--;
        }
    }

    private function consumeCommittedExecution(string $capability, string $argumentFingerprint): ?int
    {
        foreach (array_reverse($this->calls, true) as $callIndex => $call) {
            if (! isset($this->committedExecutionSequences[$call->executionSequence])
                || $call->capability !== $capability
                || $call->argumentFingerprint !== $argumentFingerprint) {
                continue;
            }

            unset($this->committedExecutionSequences[$call->executionSequence]);

            return $callIndex;
        }

        return null;
    }

    public function recordResource(ResourceObservation $resource): void
    {
        $this->resources[] = $resource;
    }

    public function nextResourceOccurrence(string $checkpoint, string $resourceIdentity, string $projection): int
    {
        $occurrence = ($this->resourceOccurrences[$checkpoint][$resourceIdentity][$projection] ?? 0) + 1;
        $this->resourceOccurrences[$checkpoint][$resourceIdentity][$projection] = $occurrence;

        return $occurrence;
    }

    /** @return list<ResourceObservation> */
    public function resources(): array
    {
        return $this->resources;
    }

    public function recordInvocationId(string $invocationId): void
    {
        $this->invocationId = $invocationId;
    }

    public function invocationId(): ?string
    {
        return $this->invocationId;
    }
}
