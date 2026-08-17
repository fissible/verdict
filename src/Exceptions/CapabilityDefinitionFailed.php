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
