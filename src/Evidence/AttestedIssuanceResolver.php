<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use Fissible\Verdict\Contracts\AttestsIssuance;
use Fissible\Verdict\Contracts\EvidenceWriter;

final class AttestedIssuanceResolver
{
    public function __construct(private EvidenceWriter $writer) {}

    public function resolve(): ?AttestsIssuance
    {
        return $this->writer instanceof AttestsIssuance ? $this->writer : null;
    }
}
