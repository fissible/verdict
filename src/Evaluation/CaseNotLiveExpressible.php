<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use RuntimeException;

final class CaseNotLiveExpressible extends RuntimeException
{
    public static function forCase(string $caseId): self
    {
        return new self("Case [{$caseId}] has no untrustedInput['request'] and cannot be expressed as a live prompt.");
    }
}
