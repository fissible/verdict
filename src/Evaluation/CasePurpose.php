<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

enum CasePurpose: string
{
    case Security = 'security';
    case Utility = 'utility';
}
