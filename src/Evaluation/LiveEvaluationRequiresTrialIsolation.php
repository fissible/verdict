<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Contracts\LiveEvaluationTrialFactory;
use LogicException;

/**
 * A run asked for more than one trial through a factory that cannot isolate one trial from the next.
 *
 * Thrown before any model is invoked. The alternative — warning and continuing — leaves an invalid
 * percentage available to be published, which is how this defect reached a published number.
 *
 * See [ADR 0020](../../docs/adr/0020-live-trial-isolation-is-application-owned.md).
 */
final class LiveEvaluationRequiresTrialIsolation extends LogicException
{
    public static function forTrials(int $trials, string $factory): self
    {
        return new self(
            "Live evaluation was asked for {$trials} trials, but [{$factory}] does not implement ".
            LiveEvaluationTrialFactory::class.'. Without it, a trial observes the state its '.
            'predecessor left behind and the resulting pass rate reports fixture residue as model '.
            'behaviour. Implement makeForTrial() to reset application-owned state before each trial, '.
            'or run a single trial.'
        );
    }
}
