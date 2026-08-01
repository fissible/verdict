<?php

declare(strict_types=1);

namespace Fissible\Verdict\Context;

enum DataClass: string
{
    case Public = 'public';
    case Internal = 'internal';
    case PII = 'pii';
    case Sensitive = 'sensitive';
}
