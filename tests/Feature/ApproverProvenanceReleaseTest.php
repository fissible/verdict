<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApproverAudience;
use Fissible\Verdict\Approvals\ApproverProvenanceRelease;
use Fissible\Verdict\Approvals\ProvenanceDisclosure;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\ReleasePolicy;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\ContentFingerprint;
use Fissible\Verdict\Evidence\DerivationKind;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Fissible\Verdict\Facades\Verdict;

function registerApproverPolicy(DataClass ...$dataClasses): void
{
    Verdict::releasePolicy(
        ReleasePolicy::between(ApproverAudience::source(), ApproverAudience::destination())
            ->allow(...($dataClasses === [] ? [DataClass::Internal] : $dataClasses))
            ->whenTrustIs(Trust::Untrusted, Trust::Trusted),
    );
}

function recordUpstream(
    Trust $trust = Trust::Untrusted,
    DataClass $dataClass = DataClass::Internal,
    string $content = 'untrusted retrieved document',
): ProvenanceEntry {
    return app(ProvenanceLedger::class)->record(
        correlationId: 'invocation-123',
        source: Source::external('knowledge-base'),
        trust: $trust,
        dataClass: $dataClass,
        channel: ContextChannel::RetrievedDocument,
        content: $content,
    );
}

function recordProposalDerivedFrom(string ...$parentContentFingerprints): string
{
    $ledger = app(ProvenanceLedger::class);
    $proposal = $ledger->record(
        correlationId: 'invocation-123',
        source: Source::application('assistant'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::ApplicationContext,
        content: ['recipient' => 'attacker', 'amount' => 500],
    );

    if ($parentContentFingerprints !== []) {
        $ledger->declareDerivation(
            correlationId: 'invocation-123',
            childContentFingerprint: $proposal->contentFingerprint,
            parentContentFingerprints: array_values($parentContentFingerprints),
            kind: DerivationKind::Summarized,
        );
    }

    return $proposal->contentFingerprint;
}

it('discloses a declared upstream source to an approver over a registered policy', function (): void {
    registerApproverPolicy();
    $retrieved = recordUpstream();
    $proposal = recordProposalDerivedFrom($retrieved->contentFingerprint);

    $provenance = app(ApproverProvenanceRelease::class)->disclose('invocation-123', $proposal);

    expect($provenance->disclosure)->toBe(ProvenanceDisclosure::Declared)
        ->and($provenance->sources)->toHaveCount(1)
        ->and($provenance->sources[0]->source->identity())->toBe('external:knowledge-base')
        ->and($provenance->sources[0]->trust)->toBe(Trust::Untrusted)
        ->and($provenance->sources[0]->channel)->toBe(ContextChannel::RetrievedDocument)
        ->and($provenance->withheldSourceCount)->toBe(0);
});

it('records the disclosure to an approver as a context release like any other', function (): void {
    registerApproverPolicy();
    $retrieved = recordUpstream();
    $proposal = recordProposalDerivedFrom($retrieved->contentFingerprint);

    app(ApproverProvenanceRelease::class)->disclose('invocation-123', $proposal);

    $recorder = app(EvidenceRecorder::class);

    expect($recorder)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        return;
    }

    $release = $recorder->releases()[0];

    expect($release->destination)->toBe('human:approver')
        ->and($release->trustZone)->toBe('human')
        ->and($release->source)->toBe('application:verdict-provenance')
        ->and($release->disposition)->toBe('permit')
        ->and($release->releasedPathFingerprints)->toHaveCount(4)
        ->and($release->releasedPathFingerprints)->toBe($release->requestedPathFingerprints);
});

/**
 * The payload is a release to a new audience, not an exemption from one: an upstream source the
 * application's allowlist does not cover does not reach the approver, and what was withheld stays
 * countable rather than vanishing.
 *
 * @verdict-claim approval.provenance-redacted
 */
it('withholds an upstream source the release policy does not permit', function (): void {
    registerApproverPolicy(DataClass::Internal);
    $permitted = recordUpstream(content: 'an internal retrieved document');
    $sensitive = recordUpstream(dataClass: DataClass::Sensitive, content: 'a sensitive retrieved document');
    $proposal = recordProposalDerivedFrom($permitted->contentFingerprint, $sensitive->contentFingerprint);

    $provenance = app(ApproverProvenanceRelease::class)->disclose('invocation-123', $proposal);

    expect($provenance->disclosure)->toBe(ProvenanceDisclosure::Declared)
        ->and($provenance->sources)->toHaveCount(1)
        ->and($provenance->sources[0]->dataClass)->toBe(DataClass::Internal)
        ->and($provenance->withheldSourceCount)->toBe(1);
});

it('reports provenance as unreleased when the application registered no approver policy', function (): void {
    $retrieved = recordUpstream();
    $proposal = recordProposalDerivedFrom($retrieved->contentFingerprint);

    $provenance = app(ApproverProvenanceRelease::class)->disclose('invocation-123', $proposal);

    expect($provenance->disclosure)->toBe(ProvenanceDisclosure::Unreleased)
        ->and($provenance->sources)->toBe([]);
});

/**
 * Absence is reported as absence. An empty source list would read as "no untrusted sources," which
 * is the inference docs/limitations.md forbids.
 *
 * @verdict-claim approval.provenance-absence-visible
 */
it('reports unknown when a registered policy would have released a provenance nobody declared', function (): void {
    registerApproverPolicy();
    $proposal = recordProposalDerivedFrom();

    $provenance = app(ApproverProvenanceRelease::class)->disclose('invocation-123', $proposal);

    expect($provenance->disclosure)->toBe(ProvenanceDisclosure::Unknown)
        ->and($provenance->sources)->toBe([]);
});

it('counts a declared upstream that was never recorded as undescribed', function (): void {
    registerApproverPolicy();
    $proposal = recordProposalDerivedFrom(ContentFingerprint::make('a document the application never recorded'));

    $provenance = app(ApproverProvenanceRelease::class)->disclose('invocation-123', $proposal);

    expect($provenance->disclosure)->toBe(ProvenanceDisclosure::Declared)
        ->and($provenance->sources)->toBe([])
        ->and($provenance->undescribedSourceCount)->toBe(1);
});
