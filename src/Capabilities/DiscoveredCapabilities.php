<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities;

use Fissible\Verdict\Contracts\DefinesCapability;

/**
 * What a discovery pass found, classified and unbuilt.
 *
 * Nothing here holds a Capability. Classification and construction are separate so that
 * `verdict:validate` can report on definition classes without registering them into a booting
 * application — the same check the provider runs, with a different reporting discipline. See
 * ADR 0027 §3 and §5.
 */
final readonly class DiscoveredCapabilities
{
    /**
     * @param  list<class-string<DefinesCapability>>  $affirmed
     * @param  list<UnaffirmedDefinition>  $unaffirmed
     */
    public function __construct(
        public array $affirmed = [],
        public array $unaffirmed = [],
    ) {}
}
