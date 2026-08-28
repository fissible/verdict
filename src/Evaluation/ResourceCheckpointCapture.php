<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use Throwable;

/**
 * Captures a projection of the refreshed target just before its executor runs.
 *
 * It is intentionally not an ExecutionWindow: that seam only sees an envelope, while this capture
 * must derive identity from the policy and the target Verdict has refreshed and will execute.
 *
 * The projection is currently INFERRED from the target's shape — Arrayable, then a `disclosure()`
 * method, then public properties — rather than declared by the capability, so a target that gains
 * an unrelated public property changes its digest. A declared projection is the intended contract,
 * and a pack case must not depend on the inferred one.
 *
 * Any identity, projection, digest, or sink failure records nothing and never prevents the
 * executor, which makes the comparison unmeasured rather than making the run fail. An evaluation
 * instrument must not break the thing it measures.
 *
 * @experimental Part of the evaluation surface; may change before Verdict 1.0.
 */
final class ResourceCheckpointCapture
{
    /** @var array<string, int> */
    private array $occurrences = [];

    public function __construct(
        private readonly LiveToolCapture $sink,
        private readonly string $checkpoint,
    ) {
        if (trim($this->checkpoint) === '') {
            throw new InvalidArgumentException('A resource checkpoint capture must name a checkpoint.');
        }
    }

    public function capture(ActionEnvelope $envelope, ExecutionTargetPolicy $policy, mixed $target): void
    {
        try {
            // This is an evaluation instrument, never an execution gate. Form every value before
            // emitting either observation: a target the instrument cannot describe is unmeasured,
            // not a partially observed execution and never a reason to refuse the executor.
            $identity = ResourceIdentity::for($policy->identity($envelope, $target));
            $digest = ResourceDigest::for($this->projection($target));
            $key = $this->checkpoint."\0".$identity;
            $occurrence = ($this->occurrences[$key] ?? 0) + 1;
            $sequence = $this->sink->recordExecution($envelope->proposal->capability, $envelope->proposal->arguments);

            $this->sink->recordResource(new ResourceObservation(
                checkpoint: $this->checkpoint,
                resourceIdentity: $identity,
                digest: $digest,
                occurrence: $occurrence,
                executionSequence: $sequence,
            ));

            $this->occurrences[$key] = $occurrence;
        } catch (Throwable) {
            // An observer that cannot form a safe measurement contributes no endpoint. The
            // comparison will consequently report unmeasured; the capability still executes.
        }
    }

    /** @return array<string, mixed> */
    private function projection(mixed $target): array
    {
        if ($target instanceof Arrayable) {
            $projection = $target->toArray();
        } elseif (is_object($target) && method_exists($target, 'disclosure')) {
            $projection = $target->disclosure();
        } elseif (is_object($target)) {
            $projection = get_object_vars($target);
        } else {
            $projection = $target;
        }

        if (! is_array($projection) || array_is_list($projection)) {
            throw new InvalidArgumentException('A resource checkpoint target must declare an associative array projection.');
        }

        return $projection;
    }
}
