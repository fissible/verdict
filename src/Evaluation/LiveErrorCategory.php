<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

enum LiveErrorCategory: string
{
    case Declined = 'declined';
    case NotAttempted = 'not_attempted';
    case NotExpressible = 'not_expressible';
    case Unavailable = 'unavailable';
    case Uncategorized = 'uncategorized';
    case AwaitingApproval = 'awaiting_approval';

    public static function fromErrorClass(?string $class): ?self
    {
        return match ($class) {
            null => null,
            ModelDeclinedToAct::class => self::Declined,
            CapabilityNotAttempted::class => self::NotAttempted,
            CaseNotLiveExpressible::class => self::NotExpressible,
            LiveObservationUnavailable::class => self::Unavailable,
            ExecutionAwaitsApproval::class => self::AwaitingApproval,
            default => self::Uncategorized,
        };
    }
}
