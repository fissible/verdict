<?php

declare(strict_types=1);

namespace Fissible\Verdict\Intents;

use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\ActionIntentStore;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Decisions\Evaluation;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Evidence\IdentityFingerprint;
use Fissible\Verdict\Exceptions\UnsafeOuterTransaction;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Writes the pre-mutation intent record for capabilities whose effective posture requires one
 * (#160): the global `verdict.intents.required` lever, overridable per capability in either
 * direction by {@see Capability::requiresIntentRecord()}.
 */
final readonly class ActionIntentManager
{
    public function __construct(
        private ActionIntentStore $store,
        private Clock $clock,
        private bool $globallyRequired,
    ) {}

    public function required(Capability $capability): bool
    {
        return $capability->intentRecordRequirement() ?? $this->globallyRequired;
    }

    /**
     * Commit one write-ahead intent, or refuse with a policy-shaped denial.
     *
     * An {@see UnsafeOuterTransaction} propagates rather than converting to a denial: it is
     * caller misconfiguration, not a store outage, and every other security-state gate reports
     * it the same way.
     */
    public function record(
        Evaluation $evaluation,
        ?string $executionTargetIdentityFingerprint,
        ?string $invocationId,
    ): ActionIntentAdmission {
        $capability = $evaluation->capability;

        if ($capability === null) {
            throw new LogicException('An intent record requires a resolved capability.');
        }

        $intent = new ActionIntent(
            id: Str::random(64),
            capability: $capability->name,
            configurationFingerprint: $capability->configurationFingerprint(),
            actorFingerprint: IdentityFingerprint::for($evaluation->envelope->context->actor),
            subjectFingerprint: IdentityFingerprint::for($evaluation->envelope->context->subject),
            executionTargetIdentityFingerprint: $executionTargetIdentityFingerprint,
            argumentFingerprint: ArgumentFingerprint::make($evaluation->envelope->proposal->arguments),
            invocationId: $invocationId,
            recordedAt: $this->clock->now(),
        );

        try {
            $this->store->record($intent);
        } catch (UnsafeOuterTransaction $unsafe) {
            throw $unsafe;
        } catch (Throwable $failure) {
            return ActionIntentAdmission::refused($failure->getMessage());
        }

        return ActionIntentAdmission::recorded($intent);
    }
}
