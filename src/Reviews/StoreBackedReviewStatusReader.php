<?php

declare(strict_types=1);

namespace Fissible\Verdict\Reviews;

use Fissible\Verdict\Contracts\ReviewRequestStore;
use Fissible\Verdict\Contracts\ReviewStatusReader;
use LogicException;

/**
 * The status reads over any ReviewRequestStore implementation, with zero new store methods:
 * statusFor() rides the store's find(). Enumeration cannot ride the store — it has no
 * enumeration method — so pendingWithin() refuses rather than pretend. A custom-store owner who
 * wants enumeration implements ReviewStatusReader for their store and binds it in the container.
 */
final readonly class StoreBackedReviewStatusReader implements ReviewStatusReader
{
    public function __construct(
        private ReviewRequestStore $store,
    ) {}

    public function statusFor(string $requestId): ?ReviewStatusView
    {
        return ReviewStatusView::fromNullableRequest($this->store->find($requestId));
    }

    public function pendingWithin(array $scope): array
    {
        ReviewScopeMatch::assertScope($scope);

        throw new LogicException(
            'The ['.$this->store::class.'] review request store has no paired status reader, so pending reviews cannot be enumerated. '
            .'Implement '.ReviewStatusReader::class.' for this store and bind it in the container.'
        );
    }
}
