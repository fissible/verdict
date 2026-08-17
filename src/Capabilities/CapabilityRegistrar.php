<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities;

use Fissible\Verdict\Contracts\DefinesCapability;
use Fissible\Verdict\Exceptions\CapabilityDefinitionFailed;
use Throwable;

/**
 * Builds affirmed definitions and registers them the way a provider would.
 *
 * Registration goes through `CapabilityRegistry::register()` — the same method
 * `VerdictManager::capability()` calls — so a discovered capability and a hand-registered one are the
 * same object downstream. Nothing in Verdict may learn to tell them apart.
 *
 * @internal Resolve CapabilityRegistrar from the container. This constructor is not part of the
 *           supported surface and may gain required parameters in any release.
 *           See docs/adr/0019-verdict-services-are-container-resolved.md.
 */
final readonly class CapabilityRegistrar
{
    public function __construct(
        private CapabilityDiscovery $discovery,
        private CapabilityRegistry $capabilities,
    ) {}

    /**
     * Builds every affirmed definition, then registers them only if all of them built.
     *
     * Continuing past a failure to collect the rest is safe because of what a definition is: ADR 0027
     * settles that it is a *declaration*, so `make()` composes closures and must not have side
     * effects. A definition that threw poisons nothing that follows it. That framing is now a
     * behavioural dependency, not just vocabulary.
     *
     * Dying here is the decision. A falsely affirmed capability failing at boot is the earliest
     * possible moment, before any request and before any tool call — and boot is the only place it
     * *can* be reported, because `Illuminate\Foundation\Console\Kernel::handle()` bootstraps the
     * application before dispatching a command. `verdict:validate` cannot report a broken definition;
     * its own bootstrap throws first. See ADR 0027 §4.
     */
    public function registerDiscovered(): void
    {
        /** @var array<string, string> $definedBy capability name => the class that defined it */
        $definedBy = [];
        /** @var list<Capability> $built */
        $built = [];
        /** @var list<CapabilityDefinitionFailed> $failures */
        $failures = [];

        foreach ($this->discovery->discover()->affirmed as $class) {
            try {
                $capability = $this->build($class);
            } catch (CapabilityDefinitionFailed $failure) {
                $failures[] = $failure;

                continue;
            }

            if (isset($definedBy[$capability->name])) {
                $failures[] = CapabilityDefinitionFailed::duplicateName($class, $definedBy[$capability->name], $capability->name);

                continue;
            }

            if ($this->capabilities->has($capability->name)) {
                $failures[] = CapabilityDefinitionFailed::alreadyRegistered($class, $capability->name);

                continue;
            }

            $definedBy[$capability->name] = $class;
            $built[] = $capability;
        }

        // All or nothing: a boot that is going to die must not leave half a security surface behind.
        if ($failures !== []) {
            throw count($failures) === 1 ? $failures[0] : CapabilityDefinitionFailed::aggregate($failures);
        }

        foreach ($built as $capability) {
            $this->capabilities->register($capability);
        }
    }

    /**
     * The catch exists only to name the class and keep the cause — never to continue past a failure.
     * The developer's own TODO text is what a chained cause makes the diagnosis.
     *
     * @param  class-string<DefinesCapability>  $class
     */
    private function build(string $class): Capability
    {
        try {
            return $class::make();
        } catch (Throwable $cause) {
            throw CapabilityDefinitionFailed::forClass($class, $cause);
        }
    }
}
