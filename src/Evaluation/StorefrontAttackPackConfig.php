<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

final readonly class StorefrontAttackPackConfig
{
    use AttackPackConfigValidation;

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
        /** The set-returning capability (#251): the model supplies a filter, never an ID. */
        public string $searchCapability,
        /**
         * The owned order matching the search case's hostile filter — the two-sided oracle's
         * positive identity. The fixture must hold BOTH this and the foreign order under the same
         * filter, or an empty result set aces the case instead of failing it.
         */
        public string|int $ownedSearchOrderId,
        /**
         * The declared ADMISSIBLE predicate shapes for the search case — base scope plus each
         * filter combination — hand-written by the harness (with the active engine's identifier
         * quoting where the runner executes real SQL). The scope clause is present in every shape
         * by construction, so the case's structural oracle tolerates the model's filter choice
         * while any widening fails. This is the manifest declaration; never generate it from the
         * executor's own builder path (#251 rounds 3 and 6).
         *
         * Runtime-validated below: a non-empty list of non-empty strings.
         *
         * @var list<string>
         */
        public array $declaredSearchPredicateShapes,
    ) {
        $this->requireNonEmptyString($this->readCapability, 'A storefront attack pack must name a read capability.');
        $this->requireNonEmptyString($this->mutationCapability, 'A storefront attack pack must name a mutation capability.');
        $this->requireNonEmptyString($this->forbiddenMarker, 'A storefront attack pack must supply a synthetic forbidden marker.');
        $this->requireIdentifier($this->actorId, 'A storefront attack pack actor ID must be a non-empty string or integer.');
        $this->requireIdentifier($this->foreignPrincipalId, 'A storefront attack pack foreign principal ID must be a non-empty string or integer.');
        $this->requireIdentifier($this->ownedOrderId, 'A storefront attack pack owned order ID must be a non-empty string or integer.');
        $this->requireIdentifier($this->foreignOrderId, 'A storefront attack pack foreign order ID must be a non-empty string or integer.');
        $this->requireIdentifier($this->mutationOrderId, 'A storefront attack pack mutation order ID must be a non-empty string or integer.');
        $this->requireNonEmptyString($this->searchCapability, 'A storefront attack pack must name a search capability.');
        $this->requireIdentifier($this->ownedSearchOrderId, 'A storefront attack pack owned search order ID must be a non-empty string or integer.');
        if ($this->declaredSearchPredicateShapes === []) {
            throw new \InvalidArgumentException('A storefront attack pack must declare at least one admissible search predicate shape.');
        }

        foreach ($this->declaredSearchPredicateShapes as $shape) {
            $this->requireNonEmptyString($shape, 'Every declared search predicate shape must be a non-empty string.');
        }
    }
}
