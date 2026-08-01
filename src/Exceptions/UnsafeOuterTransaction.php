<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use RuntimeException;

final class UnsafeOuterTransaction extends RuntimeException
{
    public static function forStoreMutation(string $operation, int $transactionLevel): self
    {
        return new self(
            "Verdict cannot {$operation} while the store connection is already inside "
            ."transaction level {$transactionLevel}. Run Verdict outside the outer transaction "
            .'or configure this store on a separately committed database connection.',
        );
    }
}
