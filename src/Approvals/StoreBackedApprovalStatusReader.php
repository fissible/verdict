<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalStatusReader;
use LogicException;

/**
 * The status reads over any ApprovalReceiptStore implementation, with zero new store methods:
 * statusFor() and statusForToolCall() ride the store's own find()/findForToolCall() (ADR 0031
 * §2). Enumeration cannot ride the store — it has no enumeration method, by #106's design — so
 * pendingWithin() here refuses rather than pretend: a custom-store owner who wants enumeration
 * implements ApprovalStatusReader for their store, the way the shipped database and in-memory
 * readers pair with theirs.
 */
final readonly class StoreBackedApprovalStatusReader implements ApprovalStatusReader
{
    public function __construct(
        private ApprovalReceiptStore $store,
    ) {}

    public function statusFor(string $receiptId): ?ApprovalStatusView
    {
        return ApprovalStatusView::fromNullableReceipt($this->store->find($receiptId));
    }

    public function statusForToolCall(string $toolCallId): ApprovalStatusLookup
    {
        throw new LogicException('#425: unimplemented');
    }

    public function pendingWithin(array $scope): array
    {
        ApprovalScopeMatch::assertScope($scope);

        throw new LogicException(
            'The ['.$this->store::class.'] approval receipt store has no paired status reader, so pending approvals cannot be enumerated. '
            .'Implement '.ApprovalStatusReader::class.' for this store and bind it in the container, or use the application-owned join path (#106).'
        );
    }
}
