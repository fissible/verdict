<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use DateTimeImmutable;
use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptLookup;
use Fissible\Verdict\Approvals\ApprovalScopeMatch;
use Fissible\Verdict\Approvals\ApprovalStatusLookup;
use Fissible\Verdict\Approvals\ApprovalStatusView;
use Fissible\Verdict\Approvals\ApprovalTransition;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalStatusReader;

/**
 * A receipt store that is its own status reader, proving the service provider honors a store's
 * own ApprovalStatusReader implementation instead of wrapping it in the store-backed default
 * (PR #329 review, finding 2).
 */
final class SelfPairingStatusReaderTestStore implements ApprovalReceiptStore, ApprovalStatusReader
{
    public function issue(ApprovalReceipt $receipt): ApprovalTransition
    {
        return ApprovalTransition::to(ApprovalOutcome::Issued, $receipt);
    }

    public function findForToolCall(string $toolCallId): ApprovalReceiptLookup
    {
        return ApprovalReceiptLookup::absent();
    }

    public function find(string $receiptId): ?ApprovalReceipt
    {
        return null;
    }

    public function approve(string $receiptId, string $toolCallId, string $approvedBy, DateTimeImmutable $at): ApprovalTransition
    {
        return ApprovalTransition::to(ApprovalOutcome::NotFound);
    }

    public function reject(string $receiptId, string $toolCallId, string $rejectedBy, DateTimeImmutable $at): ApprovalTransition
    {
        return ApprovalTransition::to(ApprovalOutcome::NotFound);
    }

    public function validate(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
    {
        return ApprovalTransition::to(ApprovalOutcome::NotFound);
    }

    public function consume(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
    {
        return ApprovalTransition::to(ApprovalOutcome::NotFound);
    }

    public function statusFor(string $receiptId): ?ApprovalStatusView
    {
        return null;
    }

    public function statusForToolCall(string $toolCallId): ApprovalStatusLookup
    {
        return ApprovalStatusLookup::absent();
    }

    public function pendingWithin(array $scope): array
    {
        ApprovalScopeMatch::assertScope($scope);

        return [];
    }
}
