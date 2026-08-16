<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ProposalProvenance;
use Fissible\Verdict\Approvals\ProvenanceDisclosure;
use Fissible\Verdict\Approvals\UpstreamSource;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Evidence\ContentFingerprint;
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

function retrievedDocumentEntry(): ProvenanceEntry
{
    return upstreamEntry(
        Source::external('knowledge-base'),
        Trust::Untrusted,
        DataClass::Internal,
        ContextChannel::RetrievedDocument,
        'untrusted retrieved document',
    );
}

it('summarises a declared upstream entry as source identity and kind', function (): void {
    $entry = retrievedDocumentEntry();

    $provenance = ProposalProvenance::declared([UpstreamSource::fromEntry($entry)]);

    expect($provenance->disclosure)->toBe(ProvenanceDisclosure::Declared)
        ->and($provenance->sources)->toHaveCount(1)
        ->and($provenance->sources[0]->source->identity())->toBe('external:knowledge-base')
        ->and($provenance->sources[0]->trust)->toBe(Trust::Untrusted)
        ->and($provenance->sources[0]->dataClass)->toBe(DataClass::Internal)
        ->and($provenance->sources[0]->channel)->toBe(ContextChannel::RetrievedDocument)
        ->and($provenance->undescribedSourceCount)->toBe(0)
        ->and($provenance->withheldSourceCount)->toBe(0);
});

it('carries no content fingerprint into the approver payload', function (): void {
    $entry = retrievedDocumentEntry();

    $provenance = ProposalProvenance::declared([UpstreamSource::fromEntry($entry)]);

    expect(json_encode($provenance, JSON_THROW_ON_ERROR))
        ->not->toContain($entry->contentFingerprint);
});

it('renders undeclared upstream as an unknown disclosure, not an empty source list', function (): void {
    $provenance = ProposalProvenance::unknown();

    expect($provenance->disclosure)->toBe(ProvenanceDisclosure::Unknown)
        ->and($provenance->sources)->toBe([]);
});

it('separates an unreleased payload from an unknown one', function (): void {
    $provenance = ProposalProvenance::unreleased();

    expect($provenance->disclosure)->toBe(ProvenanceDisclosure::Unreleased)
        ->and($provenance->sources)->toBe([]);
});

it('counts declared upstream sources it cannot describe', function (): void {
    $provenance = ProposalProvenance::declared([], undescribedSourceCount: 1);

    expect($provenance->disclosure)->toBe(ProvenanceDisclosure::Declared)
        ->and($provenance->sources)->toBe([])
        ->and($provenance->undescribedSourceCount)->toBe(1);
});

it('counts declared upstream sources the release policy withheld', function (): void {
    $provenance = ProposalProvenance::declared([], withheldSourceCount: 2);

    expect($provenance->disclosure)->toBe(ProvenanceDisclosure::Declared)
        ->and($provenance->sources)->toBe([])
        ->and($provenance->withheldSourceCount)->toBe(2);
});

it('refuses to report a declared disclosure that describes nothing', function (): void {
    expect(fn (): ProposalProvenance => ProposalProvenance::declared([]))
        ->toThrow(InvalidArgumentException::class, 'describes at least one upstream source');
});
