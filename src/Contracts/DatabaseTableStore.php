<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

interface DatabaseTableStore
{
    public function hasTable(): bool;

    public function table(): string;
}
