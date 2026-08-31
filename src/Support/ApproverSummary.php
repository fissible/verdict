<?php

declare(strict_types=1);

namespace Fissible\Verdict\Support;

final readonly class ApproverSummary
{
    /**
     * The fingerprint is hash('sha256', content): the identity of this exact plain-text snapshot,
     * distinct from any binding fingerprint. It is never canonical JSON or the binding.
     */
    public function __construct(
        public string $content,
        public string $fingerprint,
    ) {}

    /** @return array{content: string, fingerprint: string} */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'fingerprint' => $this->fingerprint,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data['content'], $data['fingerprint']);
    }
}
