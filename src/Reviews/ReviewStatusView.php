<?php

declare(strict_types=1);

namespace Fissible\Verdict\Reviews;

use DateTimeImmutable;

/**
 * The observational read of one review request (ADR 0035 §4). A DTO, never a row and never a
 * live model: it exposes only opaque identifiers, timestamps, application-supplied scalar
 * context, and the approver summary fingerprint — never the summary content, binding fingerprint,
 * provenance, or consumption details. Expiry has no transition moment, so the view reports the
 * persisted status plus expiresAt and the consumer compares clocks.
 */
final readonly class ReviewStatusView
{
    /** @param  ?array<string, string|int>  $approvalContext */
    public function __construct(
        public string $requestId,
        public string $capability,
        public ReviewStatus $status,
        public ?string $reason,
        public ?string $summaryFingerprint,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?string $resolvedBy,
        public ?DateTimeImmutable $resolvedAt,
        public ?array $approvalContext,
    ) {}

    /** The shared null-collapse every reader's status reads use: no request, no view. */
    public static function fromNullableRequest(?ReviewRequest $request): ?self
    {
        return $request === null ? null : self::fromRequest($request);
    }

    public static function fromRequest(ReviewRequest $request): self
    {
        return new self(
            requestId: $request->id,
            capability: $request->capability,
            status: $request->status,
            reason: $request->reason,
            summaryFingerprint: $request->approverSummary?->fingerprint,
            createdAt: $request->createdAt,
            expiresAt: $request->expiresAt,
            resolvedBy: $request->resolvedBy,
            resolvedAt: $request->resolvedAt,
            approvalContext: $request->approvalContext,
        );
    }
}
