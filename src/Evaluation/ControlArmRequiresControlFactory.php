<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Contracts\LiveEvaluationControlArmFactory;
use LogicException;

/**
 * A control-arm run was requested through a factory that can only build the guarded arm.
 *
 * Thrown before any model is invoked, in the refuse-don't-warn posture of ADR 0020 and 0023: the
 * alternative — running the guarded arm alone and reporting it as a comparison — is the vacuous
 * result the control arm exists to prevent.
 */
final class ControlArmRequiresControlFactory extends LogicException
{
    public static function forFactory(string $factory): self
    {
        return new self(
            "A control-arm run was requested, but [{$factory}] does not implement ".
            LiveEvaluationControlArmFactory::class.'. Implement makeControlForTrial() to build the '.
            'same suite unguarded (and samplingMode() to declare its decoding), or run without --control.'
        );
    }
}
