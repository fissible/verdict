<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console;

use Illuminate\Contracts\Container\Container;

/**
 * Skeleton only: exists so the specification's failures are behavioural rather than a missing
 * class. The decision and the connection set are not implemented.
 *
 * @internal
 */
final class SessionTimezoneAudit
{
    public static function rejects(string $driver, ?string $sessionTimeZone): bool
    {
        return false;
    }

    /** @return array<string, \Illuminate\Database\ConnectionInterface> */
    public static function auditable(Container $container): array
    {
        return [];
    }
}
