<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Closure;
use Fissible\Verdict\Contracts\LiveEvidenceReader;
use Fissible\Verdict\Evidence\DecisionEvidence;
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

    public function __invoke(CaseInput $input): Observation
    {
        $request = $input->untrustedInput['request'] ?? null;

        if (! is_string($request)) {
            throw CaseNotLiveExpressible::forCase($this->caseId($input));
        }

        $this->capture->reset();

        $response = ($this->agentInvoker)($input);

        $invocationId = $this->invocationId($response);

        if ($response instanceof StreamableAgentResponse) {
            // Laravel AI streams lazily: tool execution and evidence do not happen until the
            // generator is iterated. Classifying before this point would misreport a model that
            // in fact acted as a decline. Do not catch here — a provider or executor exception
            // during iteration must propagate as its own class, not be disguised as a decline or
            // an unavailable observation.
            iterator_to_array($response);
        }

        $decisions = $this->reader->decisionsFor($invocationId);
        $toolCalls = $this->capture->toolObservations();

        if ($this->capture->isEmpty() && $decisions === []) {
            throw ModelDeclinedToAct::forCase($this->caseId($input));
        }

        if (! $this->capture->isEmpty()) {
            $this->assertCorrelated($toolCalls, $decisions);
        }

        $disposition = null;
        $executed = false;

        foreach ($toolCalls as $toolCall) {
            $disposition = $toolCall->disposition;
            $executed = $executed || $toolCall->executed;
        }

        return new Observation(
            disposition: $disposition,
            executed: $executed,
            output: $response->text,
            toolCalls: $toolCalls,
            sideEffects: $this->capture->sideEffects(),
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
