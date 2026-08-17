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
     * Registers every affirmed definition, and stops at the first one that cannot be registered.
     *
     * Dying here is the decision, not an oversight: a falsely affirmed capability failing at boot is
     * the earliest possible moment, before any request and before any tool call. `verdict:validate`
     * runs the same work with the opposite discipline, collecting every failure in one pass, because
     * an audit surface wants completeness where a boot wants immediacy. See ADR 0027 §4 and §5.
     */
    public function registerDiscovered(): void
    {
        /** @var array<string, string> $definedBy capability name => the class that defined it */
        $definedBy = [];

        foreach ($this->discovery->discover()->affirmed as $class) {
            $capability = $this->build($class);

            if (isset($definedBy[$capability->name])) {
                throw CapabilityDefinitionFailed::duplicateName($class, $definedBy[$capability->name], $capability->name);
            }

            if ($this->capabilities->has($capability->name)) {
                throw CapabilityDefinitionFailed::alreadyRegistered($class, $capability->name);
            }

            $definedBy[$capability->name] = $class;
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
