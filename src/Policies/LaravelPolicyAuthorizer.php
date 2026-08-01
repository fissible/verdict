<?php

declare(strict_types=1);

namespace Fissible\Verdict\Policies;

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Illuminate\Contracts\Auth\Access\Gate;

final readonly class LaravelPolicyAuthorizer implements CapabilityAuthorizer
{
    public function __construct(private Gate $gate) {}

    public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
    {
        $response = $this->gate
            ->forUser($envelope->context->actor)
            ->inspect($capability->ability, $target);

        if ($response->allowed()) {
            return Decision::permit($response->message() ?? 'Allowed by Laravel authorization.');
        }

        return Decision::deny($response->message() ?? 'Denied by Laravel authorization.');
    }
}
