<?php

declare(strict_types=1);

namespace Fissible\Verdict\Decisions;

final readonly class Decision
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        public Disposition $disposition,
        public ?string $reason = null,
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function permit(?string $reason = null, array $metadata = []): self
    {
        return new self(Disposition::Permit, $reason, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function deny(?string $reason = null, array $metadata = []): self
    {
        return new self(Disposition::Deny, $reason, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function requireConfirmation(?string $reason = null, array $metadata = []): self
    {
        return new self(Disposition::RequireConfirmation, $reason, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function requireReview(?string $reason = null, array $metadata = []): self
    {
        return new self(Disposition::RequireReview, $reason, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function throttle(?string $reason = null, array $metadata = []): self
    {
        return new self(Disposition::Throttle, $reason, $metadata);
    }

    public function permitsExecution(): bool
    {
        return $this->disposition === Disposition::Permit;
    }
}
