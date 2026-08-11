<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console;

/**
 * Marker for Verdict's built-in database stores so the deployment audit can
 * inspect their tables. It is not an application extension contract.
 *
 * @internal
 */
interface DatabaseTableStore
{
    public function hasTable(): bool;

    public function table(): string;
}
