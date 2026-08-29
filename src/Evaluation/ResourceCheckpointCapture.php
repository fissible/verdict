<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Illuminate\Container\Container;
use InvalidArgumentException;
use Throwable;

/**
 * Captures a capability-declared projection of the refreshed target just before its executor runs.
 *
 * It is intentionally not an ExecutionWindow: that seam only sees an envelope, while this capture
 * must derive identity and the declared projection from the capability and the target Verdict has
 * refreshed and will execute.
 *
 * An undeclared capability is not observed. The declaration names the bytes this evaluation
 * instrument measures, so unrelated target shape changes do not change its digest.
 *
 * Any identity, projection, digest, or sink failure records nothing and never prevents the
 * executor, which makes the comparison unmeasured rather than making the run fail. An evaluation
 * instrument must not break the thing it measures.
 *
 * @experimental Part of the evaluation surface; may change before Verdict 1.0.
 */
final class ResourceCheckpointCapture
{
    private readonly EvaluationReadPredicateSuppression $evaluationReadSuppression;

    public function __construct(
        private readonly LiveToolCapture $sink,
        private readonly string $checkpoint,
        ?EvaluationReadPredicateSuppression $evaluationReadSuppression = null,
    ) {
        $this->evaluationReadSuppression = $evaluationReadSuppression
            ?? Container::getInstance()->make(EvaluationReadPredicateSuppression::class);

        if (trim($this->checkpoint) === '') {
            throw new InvalidArgumentException('A resource checkpoint capture must name a checkpoint.');
        }
    }

    public function capture(ActionEnvelope $envelope, Capability $capability, mixed $target): void
    {
        try {
            $this->evaluationReadSuppression->whileActive(function () use ($envelope, $capability, $target): void {
                $projection = $capability->declaredResourceProjection();

                if ($projection === null) {
                    return;
                }

                $policy = $capability->executionTargetPolicy();

                if ($policy === null) {
                    return;
                }

                // This is an evaluation instrument, never an execution gate. Form every value before
                // emitting either observation: a target the instrument cannot describe is unmeasured,
                // not a partially observed execution and never a reason to refuse the executor.
                $identity = ResourceIdentity::for($policy->identity($envelope, $target));
                $digest = ResourceDigest::for($projection->project($envelope, $target));
                $sequence = $this->sink->recordExecution($envelope->proposal->capability, $envelope->proposal->arguments);

                // recordExecution() can throw while canonicalizing proposal arguments. Advance the
                // occurrence only after it succeeds, so a failed capture cannot consume an endpoint.
                $occurrence = $this->sink->nextResourceOccurrence($this->checkpoint, $identity, $projection->contract);

                $this->sink->recordResource(new ResourceObservation(
                    checkpoint: $this->checkpoint,
                    resourceIdentity: $identity,
                    projection: $projection->contract,
                    digest: $digest,
                    occurrence: $occurrence,
                    executionSequence: $sequence,
                ));
            });
        } catch (Throwable) {
            // An observer that cannot form a safe measurement contributes no endpoint. The
            // comparison will consequently report unmeasured; the capability still executes.
        }
    }
}
