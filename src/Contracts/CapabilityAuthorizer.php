<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Decisions\Decision;

interface CapabilityAuthorizer
{
    public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision;
}
