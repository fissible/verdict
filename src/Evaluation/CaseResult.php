<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use JsonSerializable;

final readonly class CaseResult implements JsonSerializable
{
    /**
     * @param  list<AssertionResult>  $assertions
     * @param  ?Observation  $rawObservation  Assertion-only; excluded from serialization — see
     *                                        __sleep()/jsonSerialize().
     */
    public function __construct(
        public string $id,
        public string $version,
        public CasePurpose $purpose,
        public CaseStatus $status,
        public string $trustedSetupFingerprint,
        public string $untrustedInputFingerprint,
        public array $assertions,
        public ?ObservationEvidence $observation,
        public ?string $errorClass = null,
        public ?string $blockedBy = null,
        public ?Observation $rawObservation = null,
    ) {
        if ($this->status === CaseStatus::Pending && ($this->blockedBy === null || trim($this->blockedBy) === '')) {
            throw new \InvalidArgumentException('A pending case result must name what blocks it.');
        }
    }

    /**
     * Exclude the raw observation from serialization; it contains untranslated sensitive data
     * (output, provenance, challenges) that should never leak into reports or baselines.
     * The rawObservation property serves control-arm assertions only.
     *
     * @return list<string>
     */
    public function __sleep(): array
    {
        return [
            'id',
            'version',
            'purpose',
            'status',
            'trustedSetupFingerprint',
            'untrustedInputFingerprint',
            'assertions',
            'observation',
            'errorClass',
            'blockedBy',
        ];
    }

    /**
     * Mirrors __sleep()'s field list: everything except rawObservation. Turns the latent
     * direct-json_encode($caseResult) leak (untranslated output, provenance, and challenge
     * content reaching a report or baseline) from "no such call exists today" into a structural
     * impossibility — the sensitive field simply cannot serialize out, via json_encode() any more
     * than via serialize().
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'purpose' => $this->purpose,
            'status' => $this->status,
            'trustedSetupFingerprint' => $this->trustedSetupFingerprint,
            'untrustedInputFingerprint' => $this->untrustedInputFingerprint,
            'assertions' => $this->assertions,
            'observation' => $this->observation,
            'errorClass' => $this->errorClass,
            'blockedBy' => $this->blockedBy,
        ];
    }
}
