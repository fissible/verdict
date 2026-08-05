<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;

interface ClassifiesToolResult
{
    public function provenanceSource(): Source;

    public function provenanceTrust(): Trust;

    public function provenanceDataClass(): DataClass;
}
