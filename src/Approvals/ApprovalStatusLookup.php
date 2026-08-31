<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use InvalidArgumentException;
use LogicException;

/**
 * The result of reading approval status by a provider-supplied tool-call identifier (#425).
 *
 * Multiple matches deliberately carry no status: selecting a canonical receipt would conceal a
 * cross-capability collision, or a proposal that changed while its receipt remained open, from
 * the reviewer queue that must resolve it.
 */
final readonly class ApprovalStatusLookup
{
    /** @param  list<string>  $receiptIds */
    private function __construct(
        public ApprovalLookupOutcome $outcome,
        public ?ApprovalStatusView $status,
        public array $receiptIds,
    ) {}

    public static function absent(): self
    {
        return new self(ApprovalLookupOutcome::Absent, null, []);
    }

    public static function single(ApprovalStatusView $status): self
    {
        return new self(ApprovalLookupOutcome::Single, $status, [$status->receiptId]);
    }

    /**
     * Preserves the receipt lookup's absence/collision distinction rather than collapsing a
     * collision into no status for one reader implementation.
     */
    public static function fromReceiptLookup(ApprovalReceiptLookup $lookup): self
    {
        if ($lookup->outcome === ApprovalLookupOutcome::Absent) {
            return self::absent();
        }

        if ($lookup->outcome === ApprovalLookupOutcome::Multiple) {
            return self::multiple($lookup->receiptIds);
        }

        // Type-narrowing assertion: single() requires a receipt and this value object makes a
        // receipt-less Single unconstructible.
        if ($lookup->receipt === null) {
            throw new LogicException('A single approval receipt lookup must carry a receipt.');
        }

        return self::single(ApprovalStatusView::fromReceipt($lookup->receipt));
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
            throw new InvalidArgumentException('A multiple status lookup must name at least two distinct receipt ids.');
        }

        return new self(ApprovalLookupOutcome::Multiple, null, array_values($receiptIds));
    }

    public function count(): int
    {
        return count($this->receiptIds);
    }
}
