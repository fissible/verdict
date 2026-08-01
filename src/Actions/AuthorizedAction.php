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
    ) {}

    public static function fromExecutionEvaluation(Evaluation $evaluation): self
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
        );
    }
}
