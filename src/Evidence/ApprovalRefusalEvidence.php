<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use DateTimeImmutable;
use Fissible\Verdict\Approvals\IssuanceRefusalReason;
use InvalidArgumentException;
use JsonSerializable;

/**
 * A refused issuance, anchored on the binding's keyless consumed-guard digest (never a receipt id —
 * a refusal mints no receipt). Deduplicated per digest with an attempt count by the recorder, so a
 * replay flood against the same binding cannot grow evidence without bound (ADR 0039, #10/#15).
 */
final readonly class ApprovalRefusalEvidence implements JsonSerializable
{
    public function __construct(
        public ApprovalLane $lane,
        public IssuanceRefusalReason $reason,
        public string $capability,
        public string $bindingDigest,
        public DateTimeImmutable $occurredAt,
        public ?string $invocationId,
    ) {
        if (preg_match('/^[0-9a-f]{64}$/', $this->bindingDigest) !== 1) {
            throw new InvalidArgumentException('An approval refusal binding digest must be a lowercase SHA-256 hex digest.');
        }
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'lane' => $this->lane->value,
            'reason' => $this->reason->value,
            'capability' => $this->capability,
            'binding_digest' => $this->bindingDigest,
            'invocation_id' => $this->invocationId,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }

    /** @return array<string, string|null> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
