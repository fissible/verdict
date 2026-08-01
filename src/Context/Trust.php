<?php

declare(strict_types=1);

namespace Fissible\Verdict\Context;

enum Trust: string
{
    case Trusted = 'trusted';
    case Untrusted = 'untrusted';
}
