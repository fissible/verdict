<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Evidence\ArgumentFingerprint;

final readonly class CaseInput
{
    private string $trustedFingerprint;

    private string $untrustedFingerprint;

    /**
     * @param  array<string, mixed>  $trustedSetup
     * @param  array<string, mixed>  $untrustedInput
     */
    public function __construct(
        public array $trustedSetup,
        public array $untrustedInput,
    ) {
        $this->trustedFingerprint = ArgumentFingerprint::make($this->trustedSetup);
        $this->untrustedFingerprint = ArgumentFingerprint::make($this->untrustedInput);
    }

    public function trustedSetupFingerprint(): string
    {
        return $this->trustedFingerprint;
    }

    public function untrustedInputFingerprint(): string
    {
        return $this->untrustedFingerprint;
    }
}
