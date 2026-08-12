<?php

declare(strict_types=1);

namespace Fissible\Verdict\Actions;

use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Decisions\EvaluationStage;
use LogicException;

final readonly class AuthorizedAction
{
    private function __construct(
        public ActionEnvelope $envelope,
        public Capability $capability,
        public mixed $target,
        private ?string $executionIdentity,
    ) {}

    public static function fromExecutionEvaluation(Evaluation $evaluation, ?string $executionIdentity = null): self
    {
        if ($evaluation->stage !== EvaluationStage::Execution) {
            throw new LogicException('An authorized action requires an execution-stage evaluation.');
        }

        if (! $evaluation->decision->permitsExecution() || $evaluation->capability === null) {
            throw new LogicException('An authorized action requires a permitted capability evaluation.');
        }

        return new self(
            envelope: $evaluation->envelope,
            capability: $evaluation->capability,
            target: $evaluation->target,
            executionIdentity: $executionIdentity,
        );
    }

    /**
     * Returns the opaque identity of the admitted execution claim, when configured.
     *
     * The value remains stable when an operator releases the claim for an explicit retry.
     */
    public function executionIdentity(): ?string
    {
        return $this->executionIdentity;
    }
}
