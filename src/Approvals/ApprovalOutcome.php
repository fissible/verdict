<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

enum ApprovalOutcome: string
{
    case Issued = 'issued';
    case Existing = 'existing';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Consumed = 'consumed';
    case IssuanceRefused = 'issuance_refused';
    case NotFound = 'not_found';
    case Mismatch = 'mismatch';
    case Expired = 'expired';
    case InvalidState = 'invalid_state';
    case Unauthorized = 'unauthorized';

    public function succeeded(): bool
    {
        return match ($this) {
            self::Issued, self::Existing, self::Approved, self::Rejected, self::Consumed => true,
            self::IssuanceRefused, self::NotFound, self::Mismatch, self::Expired, self::InvalidState, self::Unauthorized => false,
        };
    }
}
