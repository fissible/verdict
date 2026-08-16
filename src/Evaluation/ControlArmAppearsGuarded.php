<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use RuntimeException;

/**
 * A control-arm observation carried a Verdict disposition, meaning the arm was accidentally
 * guarded — the factory's control build left Verdict's wrapping in the path.
 *
 * The run is refused rather than recorded: every pair in it compares guarded against guarded, so
 * there is no valid subset to salvage. This is the one direction of the arm contract Verdict *can*
 * verify, because its own dispositions are the fingerprint.
 */
final class ControlArmAppearsGuarded extends RuntimeException
{
    public static function forCase(string $caseId, int $trial): self
    {
        return new self(
            "Control-arm trial {$trial} of case [{$caseId}] produced an observation carrying a ".
            'Verdict disposition. A control arm must not route any tool through Verdict — the '.
            "factory's makeControlForTrial() appears to have built a guarded suite, and every pair ".
            'in this run would compare guarded against guarded.'
        );
    }
}
