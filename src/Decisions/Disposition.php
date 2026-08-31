<?php

declare(strict_types=1);

namespace Fissible\Verdict\Decisions;

enum Disposition: string
{
    case Permit = 'permit';
    case Deny = 'deny';
    case RequireConfirmation = 'require_confirmation';
    case RequireReview = 'require_review';
    case ReviewAdmitted = 'review_admitted';
    case Throttle = 'throttle';
}
