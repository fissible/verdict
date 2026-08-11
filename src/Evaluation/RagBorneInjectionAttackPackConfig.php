<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

final readonly class RagBorneInjectionAttackPackConfig
{
    use AttackPackConfigValidation;

    public function __construct(
        public string $consequentialCapability,
        public string|int $actorId,
        public string|int $unauthorizedActorId,
        public string $safeRecipient,
        public string $manipulatedRecipient,
        public string|int $safeAmount,
        public string|int $manipulatedAmount,
        public string $forbiddenMarker,
        public string $externalSourceName,
        public string $correlationId,
    ) {
        $this->requireNonEmptyString(
            $this->consequentialCapability,
            'A RAG-borne injection attack pack must name a consequential capability.',
        );
        $this->requireNonEmptyString(
            $this->safeRecipient,
            'A RAG-borne injection attack pack must supply a safe recipient.',
        );
        $this->requireNonEmptyString(
            $this->manipulatedRecipient,
            'A RAG-borne injection attack pack must supply a manipulated recipient.',
        );
        $this->requireNonEmptyString(
            $this->forbiddenMarker,
            'A RAG-borne injection attack pack must supply a synthetic forbidden marker.',
        );
        $this->requireNonEmptyString(
            $this->externalSourceName,
            'A RAG-borne injection attack pack must name an external provenance source.',
        );
        $this->requireNonEmptyString(
            $this->correlationId,
            'A RAG-borne injection attack pack must supply a provenance correlation ID.',
        );
        $this->requireIdentifier(
            $this->actorId,
            'A RAG-borne injection attack pack actor ID must be a non-empty string or integer.',
        );
        $this->requireIdentifier(
            $this->unauthorizedActorId,
            'A RAG-borne injection attack pack unauthorized actor ID must be a non-empty string or integer.',
        );
        $this->requireIdentifier(
            $this->safeAmount,
            'A RAG-borne injection attack pack safe amount must be a non-empty string or integer.',
        );
        $this->requireIdentifier(
            $this->manipulatedAmount,
            'A RAG-borne injection attack pack manipulated amount must be a non-empty string or integer.',
        );
    }
}
