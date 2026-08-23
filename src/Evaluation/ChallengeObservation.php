<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\Verdict\Approvals\ProposalProvenance;
use InvalidArgumentException;

/**
 * One approval challenge as the live harness observed it at issuance: the payload the
 * approver was shown (ADR 0026), and — once answer-and-resume exists — how it was answered.
 * Assertion-only; never projected into reports or baselines. See ADR 0029.
 */
final readonly class ChallengeObservation
{
    public function __construct(
        public string $receiptId,
        public string $toolCallId,
        public string $capability,
        public ?string $reason,
        public ProposalProvenance $provenance,
        public ?ChallengeDecision $decision = null,
    ) {
        foreach (['receipt id' => $receiptId, 'tool call id' => $toolCallId, 'capability' => $capability] as $label => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("An observed challenge requires a non-empty {$label}.");
            }
        }
    }

    public static function fromChallenge(ApprovalChallenge $challenge): self
    {
        if ($challenge->provenance === null) {
            throw new InvalidArgumentException('A freshly issued challenge must carry its materialised payload.');
        }

        return new self(
            receiptId: $challenge->receiptId,
            toolCallId: $challenge->toolCallId,
            capability: $challenge->capability,
            reason: $challenge->reason,
            provenance: $challenge->provenance,
        );
    }
}
