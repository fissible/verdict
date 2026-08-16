<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ProposalProvenance;
use Fissible\Verdict\Approvals\ProvenanceDisclosure;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Evidence\ContentFingerprint;
use Fissible\Verdict\Evidence\DeclaredUpstream;
use Fissible\Verdict\Evidence\ProvenanceEntry;

function upstreamEntry(
    Source $source,
    Trust $trust,
    DataClass $dataClass,
    ContextChannel $channel,
    string $content,
): ProvenanceEntry {
    return new ProvenanceEntry(
        correlationId: 'invocation-123',
        source: $source,
        trust: $trust,
        dataClass: $dataClass,
        channel: $channel,
        contentFingerprint: ContentFingerprint::make($content),
        componentLabel: null,
        componentFingerprint: null,
        recordedAt: new DateTimeImmutable('2026-08-03 12:00:00', new DateTimeZone('UTC')),
    );
}

it('summarises a declared upstream entry as source identity and kind', function (): void {
    $entry = upstreamEntry(
        Source::external('knowledge-base'),
        Trust::Untrusted,
        DataClass::Internal,
        ContextChannel::RetrievedDocument,
        'untrusted retrieved document',
    );

    $provenance = ProposalProvenance::fromDeclaredUpstream(DeclaredUpstream::declared([$entry]));

    expect($provenance->disclosure)->toBe(ProvenanceDisclosure::Declared)
        ->and($provenance->sources)->toHaveCount(1)
        ->and($provenance->sources[0]->source->identity())->toBe('external:knowledge-base')
        ->and($provenance->sources[0]->trust)->toBe(Trust::Untrusted)
        ->and($provenance->sources[0]->dataClass)->toBe(DataClass::Internal)
        ->and($provenance->sources[0]->channel)->toBe(ContextChannel::RetrievedDocument)
        ->and($provenance->undescribedSourceCount)->toBe(0);
});

it('carries no content fingerprint into the approver payload', function (): void {
    $entry = upstreamEntry(
        Source::external('knowledge-base'),
        Trust::Untrusted,
        DataClass::Internal,
        ContextChannel::RetrievedDocument,
        'untrusted retrieved document',
    );

    $provenance = ProposalProvenance::fromDeclaredUpstream(DeclaredUpstream::declared([$entry]));

    expect(json_encode($provenance, JSON_THROW_ON_ERROR))
        ->not->toContain($entry->contentFingerprint);
});

it('renders undeclared upstream as an unknown disclosure, not an empty source list', function (): void {
    $provenance = ProposalProvenance::fromDeclaredUpstream(DeclaredUpstream::undeclared());

    expect($provenance->disclosure)->toBe(ProvenanceDisclosure::Unknown)
        ->and($provenance->sources)->toBe([]);
});

it('counts declared upstream sources it cannot describe', function (): void {
    $upstream = DeclaredUpstream::declared([], [ContentFingerprint::make('never recorded')]);

    $provenance = ProposalProvenance::fromDeclaredUpstream($upstream);

    expect($provenance->disclosure)->toBe(ProvenanceDisclosure::Declared)
        ->and($provenance->sources)->toBe([])
        ->and($provenance->undescribedSourceCount)->toBe(1);
});
