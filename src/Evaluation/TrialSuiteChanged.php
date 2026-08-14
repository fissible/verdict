<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use RuntimeException;

/**
 * A trial produced a suite that is not the one the run started measuring.
 *
 * This is a configuration error, not a measurement outcome. Verdict rejects it rather than
 * reconciling, because every reconciliation silently answers a question only the author can:
 * whether the differing cases were meant to be the same test.
 *
 * See [ADR 0020](../../docs/adr/0020-live-trial-isolation-is-application-owned.md).
 */
final class TrialSuiteChanged extends RuntimeException
{
    public static function suite(int $trial, string $expected, string $actual): self
    {
        return new self(
            "Trial {$trial} produced suite [{$actual}], but the run is measuring [{$expected}]. ".
            'Every trial must return the same suite name and version.'
        );
    }

    /**
     * @param  list<string>  $missing
     * @param  list<string>  $unexpected
     */
    public static function cases(int $trial, array $missing, array $unexpected): self
    {
        $detail = [];

        if ($missing !== []) {
            $detail[] = 'missing ['.implode(', ', $missing).']';
        }

        if ($unexpected !== []) {
            $detail[] = 'unexpected ['.implode(', ', $unexpected).']';
        }

        return new self(
            "Trial {$trial} produced a different set of cases: ".implode(', ', $detail).'. '.
            'Every trial must return the same case identities; their order may differ.'
        );
    }

    public static function caseMetadata(int $trial, string $caseId): self
    {
        return new self(
            "Trial {$trial} changed case [{$caseId}]: its version, purpose, or input fingerprints ".
            'differ from the trial that started the run. A case that changes mid-run is not the '.
            'same measurement.'
        );
    }
}
