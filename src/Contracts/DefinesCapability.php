<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Capabilities\Capability;

/**
 * Marks a class as a finished capability definition, discoverable without provider registration.
 *
 * Implementing this is an **affirmation, never a proof**. Verdict cannot see inside the closures the
 * returned Capability carries (ADR 0017), so it cannot check that the generator's TODOs were replaced,
 * and it does not pretend to. A false affirmation still fails closed — at boot when construction
 * throws, at first invocation otherwise.
 *
 * Static by decision rather than convenience: a definition is a declaration, not a service, so
 * discovery never resolves one from the container and never front-loads a collaborator into boot that
 * would outlive the scope it belongs to. See docs/adr/0027-a-capability-definition-is-a-declaration.md.
 */
interface DefinesCapability
{
    public static function make(): Capability;
}
