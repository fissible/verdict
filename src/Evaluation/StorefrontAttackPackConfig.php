<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use InvalidArgumentException;

final readonly class StorefrontAttackPackConfig
{
    public function __construct(
        public string $readCapability,
        /** Used by cancellation, confirmation-mutation, and replay cases. */
        public string $mutationCapability,
        public string|int $actorId,
        public string|int $foreignPrincipalId,
        public string|int $ownedOrderId,
        public string|int $foreignOrderId,
        /** Synthetic owned-order ID used by mutation cases. */
        public string|int $mutationOrderId,
        public string $forbiddenMarker,
    ) {
        $this->requireNonEmptyString($this->readCapability, 'A storefront attack pack must name a read capability.');
        $this->requireNonEmptyString($this->mutationCapability, 'A storefront attack pack must name a mutation capability.');
        $this->requireNonEmptyString($this->forbiddenMarker, 'A storefront attack pack must supply a synthetic forbidden marker.');
        $this->requireIdentifier($this->actorId, 'A storefront attack pack actor ID must be a non-empty string or integer.');
        $this->requireIdentifier($this->foreignPrincipalId, 'A storefront attack pack foreign principal ID must be a non-empty string or integer.');
        $this->requireIdentifier($this->ownedOrderId, 'A storefront attack pack owned order ID must be a non-empty string or integer.');
        $this->requireIdentifier($this->foreignOrderId, 'A storefront attack pack foreign order ID must be a non-empty string or integer.');
        $this->requireIdentifier($this->mutationOrderId, 'A storefront attack pack mutation order ID must be a non-empty string or integer.');
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
