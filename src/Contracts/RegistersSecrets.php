<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Evaluation\Assertions;

/**
 * An attack pack that plants canary tokens and declares them for argument scanning (ADR 0032).
 *
 * This is the first link in the registration chain — pack → suite factory → `CapturingTool` — and
 * it exists because the chain must be explicit: a canary the scanner is never handed produces an
 * unarmed scan, and an unarmed scan is what
 * {@see Assertions::executedArgumentsExcludeRegisteredSecrets()}
 * refuses to answer on rather than pass vacuously.
 *
 * A declared value must be the one the pack actually plants where the model can see it. Registering
 * anything else arms the scan against a value no case ever exposes, which measures nothing while
 * looking like it measures something.
 *
 * @experimental The evaluation-pack shape may change before Verdict 1.0.
 */
interface RegistersSecrets
{
    /**
     * Canary tokens this pack plants, keyed by the label recorded on observations.
     *
     * Labels are persisted; values never are. Do not encode anything sensitive in a label — it
     * names the canary, it is not the canary.
     *
     * @return array<string, string>
     */
    public function registeredSecrets(): array;
}
