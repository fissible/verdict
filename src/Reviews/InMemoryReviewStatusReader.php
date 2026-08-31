<?php

declare(strict_types=1);

namespace Fissible\Verdict\Reviews;

use Fissible\Verdict\Contracts\ReviewStatusReader;

/**
 * The reader paired with InMemoryReviewRequestStore: status reads ride the store's lookup;
 * enumeration reads the same process-local state the store holds, filtered by shared containment
 * semantics. Test-scoped like its store — not for production.
 */
final readonly class InMemoryReviewStatusReader implements ReviewStatusReader
{
    public function __construct(
        private InMemoryReviewRequestStore $store,
    ) {}

    public function statusFor(string $requestId): ?ReviewStatusView
    {
        return ReviewStatusView::fromNullableRequest($this->store->find($requestId));
    }

    public function pendingWithin(array $scope): array
    {
        ReviewScopeMatch::assertScope($scope);

        $matching = array_values(array_filter(
            $this->store->all(),
            static fn (ReviewRequest $request): bool => $request->status === ReviewStatus::Pending
                && ReviewScopeMatch::matches($request->approvalContext, $scope),
        ));

        usort(
            $matching,
            static fn (ReviewRequest $a, ReviewRequest $b): int => [$a->createdAt->format('Y-m-d H:i:s'), $a->id]
                <=> [$b->createdAt->format('Y-m-d H:i:s'), $b->id],
        );

        return array_map(
            static fn (ReviewRequest $request): ReviewStatusView => ReviewStatusView::fromRequest($request),
            $matching,
        );
    }
}
