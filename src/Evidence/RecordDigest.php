<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * A decision-evidence record's content-derived identity, computed by Verdict alone.
 *
 * Before this, a record's only cryptographic identity was Attest's — which coupled "can another
 * system reference this specific decision" to "did this deployment adopt Attest." The digest
 * decouples *identity* (semantic, Verdict's) from *integrity* (cryptographic, Attest's): Verdict
 * mints the identity from data it already fingerprints, and Attest, when enabled, carries that
 * value inside its signed payload and so *protects* it.
 *
 * **Attest does not, and cannot, sign this exact value.** `AttestEvidenceRecorder` hands Attest a
 * payload array; Attest then signs its own envelope — `v`, `id`, `chain`, `seq`, `ts`, `type`,
 * `payload`, `prev_hash`, `key_id`, `sig_alg` — over its own RFC 8785 encoder. Verdict never
 * computes those bytes. What is achievable, and what is done, is that `record_digest` travels in
 * the payload, so the signature covers it.
 *
 * **Canonicalization.** {@see CanonicalJson}, the one canonicalization every Verdict fingerprint
 * already uses — not a second scheme in core. The value is stored scheme-tagged, so a consumer
 * re-deriving it cannot silently apply the wrong rule and a future scheme would be additive rather
 * than a re-identity of every record already published.
 *
 * **Privacy.** Every input is an existing fingerprint, enum, or scalar; the digest adds no raw or
 * sensitive value. A content digest is exactly as guessable as its inputs — correlation, not
 * anonymization, as ADR 0008 already states for every fingerprint.
 */
final class RecordDigest
{
    /**
     * The canonicalization and hash this digest is computed with, carried in the value itself.
     *
     * A change to the canonicalization *or* to the stable field set requires a new scheme, not a
     * silent recomputation: records already published under this one keep their identity.
     */
    public const string SCHEME = 'canonicaljson-sha256';

    public static function for(DecisionEvidence $evidence): string
    {
        return self::SCHEME.':'.hash('sha256', CanonicalJson::encode(
            self::stableFields($evidence),
            'record digest',
        ));
    }

    /**
     * The fields the digest is defined over — the record's stable content.
     *
     * Public because reproducibility is the point: a third party holding this list and
     * `CanonicalJson` re-derives the digest offline, without Attest and without Verdict's recorder.
     *
     * Three deliberate exclusions:
     *
     * - **`reason`** is operator-facing and application-controllable, the record's one free-text
     *   field. Folding it in would let an application change a record's identity by rewording a
     *   message.
     * - **`claimType`** is derived from fields already in this list, so including it would be
     *   redundant — and would make a correction to the vocabulary change the identity of records
     *   whose content never changed.
     * - **`intentId`** (#160) is a correlation pointer into the operational layer, not part of
     *   what this record claims — and the field set is frozen under this scheme, so admitting it
     *   would silently change the identity of every record already published. The consequence is
     *   stated rather than implied away: the intent reference is outside the attested content,
     *   and tamper-evidence over the intent row itself lives in the operational layer that gates
     *   on it, not in this digest.
     *
     * The idempotency key enters as its fingerprint, never raw: that is what the row persists, so a
     * digest over the raw value could not be re-derived from a stored record.
     *
     * @return array<string, bool|int|string|null>
     */
    public static function stableFields(DecisionEvidence $evidence): array
    {
        $fields = [
            'envelope_id' => $evidence->envelopeId,
            'capability' => $evidence->capability,
            'stage' => $evidence->stage,
            'disposition' => $evidence->disposition,
            'recorded_at' => self::timestamp($evidence->recordedAt),
            'invocation_id' => $evidence->invocationId,
            'tool_kind' => $evidence->toolKind,
            'target_source' => $evidence->targetSource,
            'configuration_fingerprint' => $evidence->configurationFingerprint,
            'actor_fingerprint' => $evidence->actorFingerprint,
            'subject_fingerprint' => $evidence->subjectFingerprint,
            'argument_fingerprint' => $evidence->argumentFingerprint,
            'idempotency_key_fingerprint' => $evidence->idempotencyKey === null
                ? null
                : hash('sha256', $evidence->idempotencyKey),
            'approval_receipt_fingerprint' => $evidence->approvalReceiptFingerprint,
            'review_request_fingerprint' => $evidence->reviewRequestFingerprint,
            'approval_phase' => $evidence->approvalPhase,
            'approval_outcome' => $evidence->approvalOutcome,
            'target_policy' => $evidence->targetPolicy,
            'target_strategy' => $evidence->targetStrategy,
            'proposal_target_identity_fingerprint' => $evidence->proposalTargetIdentityFingerprint,
            'execution_target_identity_fingerprint' => $evidence->executionTargetIdentityFingerprint,
            'target_identity_matched' => $evidence->targetIdentityMatched,
            'rate_limit_key_fingerprint' => $evidence->rateLimitKeyFingerprint,
            'rate_limit_policy' => $evidence->rateLimitPolicy,
            'rate_limit_limit' => $evidence->rateLimitLimit,
            'rate_limit_remaining' => $evidence->rateLimitRemaining,
            'rate_limit_reset_at' => self::timestamp($evidence->rateLimitResetAt),
            'execution_claim_fingerprint' => $evidence->executionClaimFingerprint,
            'execution_claim_binding_fingerprint' => $evidence->executionClaimBindingFingerprint,
            'execution_claim_policy' => $evidence->executionClaimPolicy,
            'execution_claim_status' => $evidence->executionClaimStatus,
            'execution_claim_attempt' => $evidence->executionClaimAttempt,
            'tool_description_fingerprint' => $evidence->toolDescriptionFingerprint,
            'invocation_tool_description_fingerprint' => $evidence->invocationToolDescriptionFingerprint,
            'tool_description_matched' => $evidence->toolDescriptionMatched,
        ];

        if ($evidence->stage === 'review' && $evidence->reviewOutcome !== null) {
            $fields['review_outcome'] = $evidence->reviewOutcome;
        }

        return $fields;
    }

    /**
     * UTC at second precision.
     *
     * The evidence table stores `recorded_at` as a `timestamp`, whose sub-second precision differs
     * across the databases Verdict supports — so a digest computed over microseconds could not be
     * re-derived from the row Verdict itself wrote, breaking the offline-reproducibility guarantee.
     * Normalizing to UTC keeps the same instant recorded in two zones one record.
     *
     * The consequence is deliberate and matches the acceptance criterion: two records identical in
     * every stable field, written within the same second, share a digest. They are the same claim.
     * The digest is a content identity, not a row identity — the table's UUID primary key remains
     * what distinguishes rows.
     */
    private static function timestamp(?DateTimeInterface $at): ?string
    {
        if ($at === null) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($at)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
