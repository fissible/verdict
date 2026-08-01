<?php

declare(strict_types=1);

namespace Fissible\Verdict\Decisions;

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;

final readonly class Evaluation
{
    public function __construct(
        public ActionEnvelope $envelope,
        public ?Capability $capability,
        public mixed $target,
        public Decision $decision,
        public EvaluationStage $stage,
    ) {}
}
