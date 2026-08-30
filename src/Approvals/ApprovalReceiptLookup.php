<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use LogicException;

/**
 * API skeleton — issue #425. Bodies are unimplemented on purpose.
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
        throw new LogicException('#425: unimplemented');
    }

    public static function single(ApprovalReceipt $receipt): self
    {
        throw new LogicException('#425: unimplemented');
    }

    /** @param  list<string>  $receiptIds */
    public static function multiple(array $receiptIds): self
    {
        throw new LogicException('#425: unimplemented');
    }

    public function count(): int
    {
        throw new LogicException('#425: unimplemented');
    }
}
