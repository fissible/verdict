<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A class affirmed itself as a finished capability definition and then could not be registered.
 *
 * Every message names both legitimate exits — finish the work, or withdraw the affirmation — because
 * un-affirming is the honest way to ship a deploy with a capability mid-work, and an error that omits
 * it pushes a developer toward deleting the file or hacking out the TODO instead.
 */
final class CapabilityDefinitionFailed extends RuntimeException
{
    public static function forClass(string $class, Throwable $cause): self
    {
        return new self(
            "[{$class}] affirms DefinesCapability but could not be built: {$cause->getMessage()}\n\n"
            ."Finish the TODOs in {$class}, or remove `implements DefinesCapability` until it is finished.",
            previous: $cause,
        );
    }

    /**
     * Every failure from one discovery pass, listed under a count.
     *
     * Boot is the only place these can be reported: `php artisan verdict:validate` bootstraps the
     * application before dispatching a command, so a throwing definition kills the command before it
     * can report anything. Fix-all-at-once therefore has to live here or nowhere.
     *
     * Each entry keeps the single-failure contract — class, cause, both exits — and a lone failure is
     * rethrown as itself rather than wrapped, so aggregation costs the common case nothing.
     *
     * @param  list<self>  $failures
     */
    public static function aggregate(array $failures): self
    {
        $count = count($failures);
        $messages = array_map(static fn (self $failure): string => $failure->getMessage(), $failures);

        return new self(
            "{$count} capability definitions could not be registered.\n\n".implode("\n\n", $messages),
            previous: $failures[0] ?? null,
        );
    }

    public static function alreadyRegistered(string $class, string $capability): self
    {
        return new self(
            "[{$class}] discovers capability [{$capability}], which is already registered manually.\n\n"
            .'Remove the provider registration and let discovery own it, or remove '
            ."`implements DefinesCapability` from {$class} and keep registering it by hand.",
        );
    }

    public static function duplicateName(string $class, string $existing, string $capability): self
    {
        return new self(
            "[{$class}] and [{$existing}] both define capability [{$capability}].\n\n"
            .'Two definition classes cannot claim one capability name — rename the capability in one of '
            .'them, or delete whichever was a copy.',
        );
    }
}
