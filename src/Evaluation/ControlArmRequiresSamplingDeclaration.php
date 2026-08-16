<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use LogicException;

/**
 * A control-arm run's reproduction metadata does not say how the model was decoded.
 *
 * Whether the 2×2's cells are joint observations or marginals depends entirely on the decoding
 * mode, so a control result without its parameters (temperature, seed) recorded is not
 * reproducible enough to publish. Thrown before any model is invoked. The declaration is
 * application-attested — Verdict requires it and refuses its absence, but cannot verify its truth.
 *
 * See [ADR 0023](../../docs/adr/0023-unguarded-control-arm-pairing-and-opt-in.md).
 */
final class ControlArmRequiresSamplingDeclaration extends LogicException
{
    public static function forSuite(string $suite): self
    {
        return new self(
            "A control-arm run of [{$suite}] requires a 'sampling' component in its ".
            "ReproductionMetadata recording the decoding parameters (e.g. 'greedy temperature=0 ".
            "seed=42'), so the pairing claim a report makes can be tied to the mode that produced it."
        );
    }
}
