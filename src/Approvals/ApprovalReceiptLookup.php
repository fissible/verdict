<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use InvalidArgumentException;

/**
 * The result of looking up receipts by a provider-supplied tool-call identifier (#425).
 *
 * Multiple matches deliberately carry no receipt: selecting a canonical receipt would conceal a
 * cross-capability collision, or a proposal that changed while its receipt remained open, from
 * the reviewer queue that must resolve it.
 */
final readonly class ApprovalReceiptLookup
{
    /** @param  list<string>  $receiptIds */
    private function __construct(
        public ApprovalLookupOutcome $outcome,
        public ?ApprovalReceipt $receipt,
        public array $receiptIds,
    ) {}

    public static function absent(): self
    {
        return new self(ApprovalLookupOutcome::Absent, null, []);
    }

    public static function single(ApprovalReceipt $receipt): self
    {
        return new self(ApprovalLookupOutcome::Single, $receipt, [$receipt->id]);
    }

    /**
     * Fewer than two ids and duplicate ids are refused because they describe a false collision:
     * two entries naming one receipt are the mirror image of the ambiguity this result protects.
     *
     * @param  array<string>  $receiptIds
     */
    public static function multiple(array $receiptIds): self
    {
        if (count($receiptIds) < 2 || count($receiptIds) !== count(array_unique($receiptIds))) {
            throw new InvalidArgumentException('A multiple receipt lookup must name at least two distinct receipt ids.');
        }

        return new self(ApprovalLookupOutcome::Multiple, null, array_values($receiptIds));
    }

    public function count(): int
    {
        return count($this->receiptIds);
    }
}
