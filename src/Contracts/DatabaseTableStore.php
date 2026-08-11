<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

/**
 * @experimental This audit-specific marker may be narrowed or made internal; see #88.
 */
interface DatabaseTableStore
{
    public function hasTable(): bool;

    public function table(): string;
}
