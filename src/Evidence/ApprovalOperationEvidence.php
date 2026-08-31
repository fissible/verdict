<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;

final readonly class ApprovalOperationEvidence implements JsonSerializable
{
    public function __construct(
        public ApprovalLane $lane,
        public ApprovalOperation $operation,
        public string $capability,
        public string $identityFingerprint,
        public ?string $summaryFingerprint,
        public DateTimeImmutable $occurredAt,
        public ?string $invocationId = null,
    ) {
        foreach ([$this->identityFingerprint, $this->summaryFingerprint] as $fingerprint) {
            if ($fingerprint !== null && preg_match('/^[0-9a-f]{64}$/', $fingerprint) !== 1) {
                throw new InvalidArgumentException('Approval operation fingerprints must be lowercase SHA-256 hex digests.');
            }
        }
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'lane' => $this->lane->value,
            'operation' => $this->operation->value,
            'capability' => $this->capability,
            'identity_fingerprint' => $this->identityFingerprint,
            'summary_fingerprint' => $this->summaryFingerprint,
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
