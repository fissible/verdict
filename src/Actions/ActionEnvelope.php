<?php

declare(strict_types=1);

namespace Fissible\Verdict\Actions;

use DateTimeImmutable;
use Illuminate\Support\Str;

final readonly class ActionEnvelope
{
    public function __construct(
        public string $id,
        public ActionProposal $proposal,
        public ActionContext $context,
        public DateTimeImmutable $createdAt,
    ) {}

    public static function wrap(
        ActionProposal $proposal,
        ActionContext $context,
        ?string $id = null,
        ?DateTimeImmutable $createdAt = null,
    ): self {
        return new self(
            id: $id ?? Str::uuid()->toString(),
            proposal: $proposal,
            context: $context,
            createdAt: $createdAt ?? new DateTimeImmutable,
        );
    }
}
