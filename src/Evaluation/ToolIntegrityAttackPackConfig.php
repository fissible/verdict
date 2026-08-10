<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use InvalidArgumentException;

final readonly class ToolIntegrityAttackPackConfig
{
    public function __construct(
        public string $legitimateCapability,
        public string $adversarialCapability,
        public string $cleanDescription,
        public string $poisonedDescription,
        public string|int $actorId,
        public string $safeRecipient,
        public string $injectedRecipient,
        public string|int $amount,
        public string $forbiddenMarker,
    ) {
        $this->requireNonEmptyString(
            $this->legitimateCapability,
            'A tool-integrity attack pack must name a legitimate capability.',
        );
        $this->requireNonEmptyString(
            $this->adversarialCapability,
            'A tool-integrity attack pack must name an adversarial capability.',
        );
        $this->requireNonEmptyString(
            $this->cleanDescription,
            'A tool-integrity attack pack must supply a clean tool description.',
        );
        $this->requireNonEmptyString(
            $this->poisonedDescription,
            'A tool-integrity attack pack must supply a poisoned tool description.',
        );
        $this->requireNonEmptyString(
            $this->safeRecipient,
            'A tool-integrity attack pack must supply a safe recipient.',
        );
        $this->requireNonEmptyString(
            $this->injectedRecipient,
            'A tool-integrity attack pack must supply an injected recipient.',
        );
        $this->requireNonEmptyString(
            $this->forbiddenMarker,
            'A tool-integrity attack pack must supply a synthetic forbidden marker.',
        );
        $this->requireIdentifier(
            $this->actorId,
            'A tool-integrity attack pack actor ID must be a non-empty string or integer.',
        );
        $this->requireIdentifier(
            $this->amount,
            'A tool-integrity attack pack amount must be a non-empty string or integer.',
        );

        if ($this->legitimateCapability === $this->adversarialCapability) {
            throw new InvalidArgumentException(
                'A tool-integrity attack pack legitimate capability and adversarial capability must be different.',
            );
        }

        if ($this->safeRecipient === $this->injectedRecipient) {
            throw new InvalidArgumentException(
                'A tool-integrity attack pack safe recipient and injected recipient must be different.',
            );
        }
    }

    private function requireNonEmptyString(string $value, string $message): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException($message);
        }
    }

    private function requireIdentifier(string|int $value, string $message): void
    {
        if (is_string($value) && trim($value) === '') {
            throw new InvalidArgumentException($message);
        }
    }
}
