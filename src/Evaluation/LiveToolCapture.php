<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evidence\ArgumentFingerprint;

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

    private int $executionSequence = 0;

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
        $this->executionSequence = 0;
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
     * Record the execution that a resource checkpoint belongs to and return its sequence.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function recordExecution(string $capability, array $arguments): int
    {
        $sequence = ++$this->executionSequence;
        $this->calls[] = new ToolObservation(
            capability: $capability,
            argumentFingerprint: ArgumentFingerprint::make($arguments),
            disposition: Disposition::Permit,
            executed: true,
            executionSequence: $sequence,
        );

        return $sequence;
    }

    public function recordResource(ResourceObservation $resource): void
    {
        $this->resources[] = $resource;
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
