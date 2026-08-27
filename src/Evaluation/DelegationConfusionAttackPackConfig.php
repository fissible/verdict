<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use InvalidArgumentException;

final readonly class DelegationConfusionAttackPackConfig
{
    use AttackPackConfigValidation;

    public function __construct(
        public string $delegatedCapability,
        public string $escalatedCapability,
        public string $utilityCapability,
        public string|int $actorId,
        public string|int $subjectId,
        public string|int $substitutedSubjectId,
        public string|int $resourceId,
        public string $forbiddenMarker,
        public string $correlationId,
        public string $subAgentSourceName,
    ) {
        $this->requireNonEmptyString(
            $this->delegatedCapability,
            'A delegation-confusion attack pack must name a delegated capability.',
        );
        $this->requireNonEmptyString(
            $this->escalatedCapability,
            'A delegation-confusion attack pack must name an escalated capability.',
        );
        $this->requireNonEmptyString(
            $this->utilityCapability,
            'A delegation-confusion attack pack must name a utility capability.',
        );
        $this->requireNonEmptyString(
            $this->forbiddenMarker,
            'A delegation-confusion attack pack must supply a synthetic forbidden marker.',
        );
        $this->requireNonEmptyString(
            $this->correlationId,
            'A delegation-confusion attack pack must supply a provenance correlation ID.',
        );
        $this->requireNonEmptyString(
            $this->subAgentSourceName,
            'A delegation-confusion attack pack must name a sub-agent provenance source.',
        );
        $this->requireIdentifier(
            $this->actorId,
            'A delegation-confusion attack pack actor ID must be a non-empty string or integer.',
        );
        $this->requireIdentifier(
            $this->subjectId,
            'A delegation-confusion attack pack subject ID must be a non-empty string or integer.',
        );
        $this->requireIdentifier(
            $this->substitutedSubjectId,
            'A delegation-confusion attack pack substituted subject ID must be a non-empty string or integer.',
        );
        $this->requireIdentifier(
            $this->resourceId,
            'A delegation-confusion attack pack resource ID must be a non-empty string or integer.',
        );

        if ($this->delegatedCapability === $this->escalatedCapability) {
            throw new InvalidArgumentException(
                'A delegation-confusion attack pack delegated capability and escalated capability must be different.',
            );
        }

        // The whole pack is about the distinction ADR 0015 draws, so a fixture whose actor is its
        // own subject cannot express it: every case would collapse into the baseline.
        if ((string) $this->actorId === (string) $this->subjectId) {
            throw new InvalidArgumentException(
                'A delegation-confusion attack pack actor and subject must be different principals.',
            );
        }

        if ((string) $this->subjectId === (string) $this->substitutedSubjectId) {
            throw new InvalidArgumentException(
                'A delegation-confusion attack pack subject and substituted subject must be different principals.',
            );
        }
    }
}
