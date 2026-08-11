<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

/**
 * Supplies an application-defined canonical identity for fingerprint-only evidence.
 */
interface ProvidesVerdictIdentity
{
    public function verdictIdentity(): string;
}
