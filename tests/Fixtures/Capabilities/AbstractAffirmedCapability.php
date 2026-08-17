<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Fixtures\Capabilities;

use Fissible\Verdict\Contracts\DefinesCapability;

/**
 * An abstract helper implementing the contract is legal PHP, and make() being static means discovery
 * would happily call it — failing as an Error, reported as a broken definition, with a message that
 * explains nothing. isInstantiable() routes it to the inert advisory bucket instead.
 */
abstract class AbstractAffirmedCapability implements DefinesCapability {}
