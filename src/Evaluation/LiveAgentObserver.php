<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Closure;
use Fissible\Verdict\Contracts\LiveEvidenceReader;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Drives a real Laravel AI agent for one evaluation case and turns the result into the same
 * `Observation` shape the deterministic evaluation runners produce.
 *
 * Three failure modes are told apart deliberately:
 *
 * - The capture stays empty and the evidence reader returns nothing: the model genuinely declined
 *   to invoke a bound tool. That is an honest outcome (`ModelDeclinedToAct`), not an error.
 * - The capture is non-empty but a captured call has no correlated `DecisionEvidence`: a tool
 *   demonstrably ran, so evidence must exist. Its absence means the harness is misconfigured, not
 *   that the model declined (`LiveObservationUnavailable`). Reporting this as a decline would
 *   produce a flattering false pass.
 * - The response carries no invocation id at all: nothing can be correlated
 *   (`LiveObservationUnavailable`).
 */
final readonly class LiveAgentObserver
{
    /**
     * The application invokes its own agent — synchronously, structured, or streamed — and
     * returns the resulting Laravel AI response. Provider and execution-mode policy stay
     * application-owned; the observer never calls `prompt()` or `stream()` itself, it classifies
     * whatever response it receives.
     *
     * @param  Closure(CaseInput): (AgentResponse|StructuredAgentResponse|StreamableAgentResponse)  $agentInvoker
     */
    public function __construct(
        private Closure $agentInvoker,
        private LiveToolCapture $capture,
        private LiveEvidenceReader $reader,
    ) {}

    /**
     * The control arm's observer: no evidence reader, because an unguarded arm produces no
     * `DecisionEvidence` by construction — the guarded correlation check would misreport every
     * control execution as `LiveObservationUnavailable`. The capture alone is the measurement;
     * an empty capture is still an honest `ModelDeclinedToAct`. The inverse integrity check — a
     * captured call carrying a Verdict disposition — is `LiveEvaluationRunner`'s, which refuses
     * the run as accidentally guarded. See ADR 0023.
     *
     * @param  Closure(CaseInput): (AgentResponse|StructuredAgentResponse|StreamableAgentResponse)  $agentInvoker
     */
    public static function unguarded(Closure $agentInvoker, LiveToolCapture $capture): self
    {
        return new self($agentInvoker, $capture, new NoLiveEvidence);
    }

    public function __invoke(CaseInput $input): Observation
    {
        $request = $input->untrustedInput['request'] ?? null;

        if (! is_string($request)) {
            throw CaseNotLiveExpressible::forCase($this->caseId($input));
        }

        $this->capture->reset();

        try {
            $response = ($this->agentInvoker)($input);

            if ($response instanceof StreamableAgentResponse) {
                // Laravel AI streams lazily: tool execution and evidence do not happen until the
                // generator is iterated. Classifying before this point would misreport a model
                // that in fact acted as a decline. An approval pause is the one exception
                // classified here (ADR 0029) — a provider or executor exception during iteration
                // must still propagate as its own class, not be disguised as a decline or an
                // unavailable observation.
                iterator_to_array($response);
            }
        } catch (ApprovalNotResumableException $exception) {
            // A pause with a captured challenge is a legitimate terminal observation (ADR 0029):
            // the preflight observed issuance; nothing more can happen in a single-shot trial. A
            // pause with no captured challenge came from outside the capture and stays an
            // uncategorized harness gap.
            if ($this->capture->challenges() === []) {
                throw $exception;
            }

            return $this->pausedObservation();
        }

        $unguarded = $this->reader instanceof NoLiveEvidence;
        $invocationId = $unguarded ? null : $this->invocationId($response);

        $toolCalls = $this->capture->toolObservations();

        if ($unguarded || $invocationId === null) {
            // No evidence exists to consult: an empty capture can only mean the model declined.
            if ($this->capture->isEmpty()) {
                throw ModelDeclinedToAct::forCase($this->caseId($input));
            }
        } else {
            $decisions = $this->reader->decisionsFor($invocationId);

            if ($this->capture->isEmpty() && $decisions === []) {
                throw ModelDeclinedToAct::forCase($this->caseId($input));
            }

            if (! $this->capture->isEmpty()) {
                $this->assertCorrelated($toolCalls, $decisions);
            }
        }

        return $this->observation($response->text);
    }

    private function pausedObservation(): Observation
    {
        $invocationId = $this->capture->invocationId();

        if (! $this->reader instanceof NoLiveEvidence) {
            if ($invocationId === null) {
                throw LiveObservationUnavailable::because('a paused run carried no preflight invocation id');
            }

            $this->assertCorrelated($this->capture->toolObservations(), $this->reader->decisionsFor($invocationId));
        }

        return $this->observation(output: null);
    }

    /**
     * The top-level `(disposition, executed)` pair describes the run's **terminal decision** —
     * both fields are read off the last tool observation, which `LiveToolCapture` orders by
     * execution. That matches `Observation::fromExecutionResult()`, where the pair means one
     * action's outcome, and it is deliberately not an OR over `executed`: a run that read
     * something benign and then had its consequential action blocked reports `executed = false`
     * at the top level. Per-call facts live in `toolCalls`, which is what
     * `toolExecuted()`/`toolAttemptedButBlocked()` read.
     */
    private function observation(mixed $output): Observation
    {
        $toolCalls = $this->capture->toolObservations();

        $terminal = $toolCalls === [] ? null : $toolCalls[array_key_last($toolCalls)];

        $disposition = $terminal?->disposition;
        $executed = $terminal !== null && $terminal->executed;

        return new Observation(
            disposition: $disposition,
            executed: $executed,
            output: $output,
            toolCalls: $toolCalls,
            sideEffects: $this->capture->sideEffects(),
            challenges: $this->capture->challenges(),
            predicates: $this->capture->predicates(),
        );
    }

    private function caseId(CaseInput $input): string
    {
        return $input->untrustedInputFingerprint();
    }

    /**
     * Correlation is at least one decision record per captured call with the same capability,
     * argument fingerprint, and disposition — never one-to-one. A single bound-tool call emits
     * several Verdict stages, so extra correlated records are expected, not a mismatch.
     *
     * @param  list<ToolObservation>  $toolCalls
     * @param  list<DecisionEvidence>  $decisions
     */
    private function assertCorrelated(array $toolCalls, array $decisions): void
    {
        foreach ($toolCalls as $toolCall) {
            $correlated = false;

            foreach ($decisions as $decision) {
                if (
                    $decision->capability === $toolCall->capability
                    && $decision->argumentFingerprint === $toolCall->argumentFingerprint
                    && $decision->disposition === $toolCall->disposition?->value
                ) {
                    $correlated = true;

                    break;
                }
            }

            if (! $correlated) {
                throw LiveObservationUnavailable::because('correlated decision evidence is missing for a captured tool call');
            }
        }
    }

    /**
     * Reads `invocationId` from the response object without depending on `InvocationContext`,
     * which has already unwound by the time the observer runs.
     */
    private function invocationId(AgentResponse|StructuredAgentResponse|StreamableAgentResponse $response): string
    {
        if (trim($response->invocationId) === '') {
            throw LiveObservationUnavailable::because('the response carried no invocation id');
        }

        return $response->invocationId;
    }
}
