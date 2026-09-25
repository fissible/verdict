<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use RuntimeException;

/**
 * Fail-closed signal: a retained or active consumed-binding guard key version has no usable secret,
 * so a candidate cannot be re-derived to check it and a keyed guard cannot be minted. The offending
 * version is named so the wiring slice can tell an operator which key to restore (ADR 0039).
 */
final class MissingConsumedBindingGuardKey extends RuntimeException
{
    public function __construct(public readonly string $keyVersion)
    {
        parent::__construct("Missing secret for consumed-binding guard key version '{$keyVersion}'.");
    }
}
