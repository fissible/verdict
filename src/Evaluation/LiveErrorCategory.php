<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

enum LiveErrorCategory: string
{
    case Declined = 'declined';
    case NotExpressible = 'not_expressible';
    case Unavailable = 'unavailable';
    case Uncategorized = 'uncategorized';

    public static function fromErrorClass(?string $class): ?self
    {
        return match ($class) {
            null => null,
            ModelDeclinedToAct::class => self::Declined,
            CaseNotLiveExpressible::class => self::NotExpressible,
            LiveObservationUnavailable::class => self::Unavailable,
            default => self::Uncategorized,
        };
    }
}
