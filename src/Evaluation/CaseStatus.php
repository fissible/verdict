<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

enum CaseStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Error = 'error';
}
