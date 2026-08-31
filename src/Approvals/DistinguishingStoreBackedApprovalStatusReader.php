<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalStatusReader;
use Fissible\Verdict\Contracts\DistinguishesReceiptCollisions;
use Fissible\Verdict\Contracts\DistinguishesStatusCollisions;

/**
 * The store-backed reader for a custom store that has adopted #425's collision seam.
 *
 * This exists as a separate class rather than a branch inside StoreBackedApprovalStatusReader
 * because `instanceof DistinguishesStatusCollisions` is the discovery mechanism: a single class
 * that implements the interface and then throws for stores that cannot answer would advertise a
 * capability half its instances do not have, which is a false positive on the exact probe a
 * consumer is told to trust. A reader either can distinguish a collision or is not this class.
 *
 * Everything except the collision read is the plain store-backed behaviour, delegated unchanged —
 * including pendingWithin()'s refusal, which is a separate capability question (#106).
 */
final readonly class DistinguishingStoreBackedApprovalStatusReader implements ApprovalStatusReader, DistinguishesStatusCollisions
{
    private StoreBackedApprovalStatusReader $inner;

    public function __construct(
        private ApprovalReceiptStore&DistinguishesReceiptCollisions $store,
    ) {
        $this->inner = new StoreBackedApprovalStatusReader($store);
    }

    public function statusFor(string $receiptId): ?ApprovalStatusView
    {
        return $this->inner->statusFor($receiptId);
    }

    public function statusForToolCall(string $toolCallId): ?ApprovalStatusView
    {
        return $this->inner->statusForToolCall($toolCallId);
    }

    public function statusLookupForToolCall(string $toolCallId): ApprovalStatusLookup
    {
        return ApprovalStatusLookup::fromReceiptLookup($this->store->lookupForToolCall($toolCallId));
    }

    public function pendingWithin(array $scope): array
    {
        return $this->inner->pendingWithin($scope);
    }
}
