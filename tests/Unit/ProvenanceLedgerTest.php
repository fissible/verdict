<?php

declare(strict_types=1);

use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\ContentFingerprint;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\DeclaredUpstream;
use Fissible\Verdict\Evidence\DerivationKind;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\Evidence\ProvenanceLedger;

final readonly class ProvenanceTestClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-03 12:00:00', new DateTimeZone('UTC'));
    }
}

function provenanceLedger(?EvidenceRecorder $recorder = null): ProvenanceLedger
{
    $recorder ??= new InMemoryEvidenceRecorder;

    return new ProvenanceLedger($recorder, $recorder, new ProvenanceTestClock);
}

it('fingerprints scalar and structured content canonically', function (): void {
    expect(ContentFingerprint::make([
        'query' => 'red shoes',
        'filters' => ['stock' => true, 'price' => 10.0],
    ]))->toBe(ContentFingerprint::make([
        'filters' => ['price' => 10.0, 'stock' => true],
        'query' => 'red shoes',
    ]))->and(ContentFingerprint::make(['first', 'second']))
        ->not->toBe(ContentFingerprint::make(['second', 'first']))
        ->and(ContentFingerprint::make(10))
        ->not->toBe(ContentFingerprint::make(10.0));
});

it('rejects content that cannot be canonically represented', function (): void {
    expect(fn (): string => ContentFingerprint::make(new stdClass))
        ->toThrow(InvalidArgumentException::class);
});

it('records a typed content-addressed derivation edge', function (): void {
    $derivation = new ProvenanceDerivation(
        correlationId: 'invocation-123',
        childContentFingerprint: str_repeat('a', 64),
        parentContentFingerprint: str_repeat('b', 64),
        kind: DerivationKind::Summarized,
        recordedAt: new DateTimeImmutable('2026-08-03 12:00:00', new DateTimeZone('UTC')),
    );

    expect($derivation->kind)->toBe(DerivationKind::Summarized)
        ->and($derivation->parentContentFingerprint)->toBe(str_repeat('b', 64));
});

it('rejects a derivation edge that loops directly to its own content', function (): void {
    expect(fn (): ProvenanceDerivation => new ProvenanceDerivation(
        correlationId: 'invocation-123',
        childContentFingerprint: str_repeat('a', 64),
        parentContentFingerprint: str_repeat('a', 64),
        kind: DerivationKind::Transformed,
        recordedAt: new DateTimeImmutable,
    ))->toThrow(InvalidArgumentException::class);
});

it('records every context channel without retaining raw content', function (ContextChannel $channel): void {
    $rawContent = "private-{$channel->value}-content";
    $entry = provenanceLedger()->record(
        correlationId: 'invocation-123',
        source: Source::external('catalog-service'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        channel: $channel,
        content: ['body' => $rawContent, 'rank' => 1],
        componentLabel: 'retriever',
        componentVersion: 'v2.1.0',
    );

    expect($entry->channel)->toBe($channel)
        ->and($entry->componentLabel)->toBe('retriever')
        ->and($entry->componentFingerprint)->toBe(ContentFingerprint::make('v2.1.0'))
        ->and($entry->recordedAt->format(DATE_ATOM))->toBe('2026-08-03T12:00:00+00:00')
        ->and(json_encode($entry, JSON_THROW_ON_ERROR))->not->toContain($rawContent)
        ->and(json_encode($entry, JSON_THROW_ON_ERROR))->not->toContain('v2.1.0');
})->with(ContextChannel::cases());

it('rejects ambiguous correlation and component labels before recording', function (
    string $correlationId,
    ?string $componentLabel,
    ?string $componentVersion,
): void {
    $recorder = new InMemoryEvidenceRecorder;

    expect(fn (): ProvenanceEntry => provenanceLedger($recorder)->record(
        correlationId: $correlationId,
        source: Source::user('customer'),
        trust: Trust::Trusted,
        dataClass: DataClass::Public,
        channel: ContextChannel::UserInput,
        content: 'hello',
        componentLabel: $componentLabel,
        componentVersion: $componentVersion,
    ))->toThrow(InvalidArgumentException::class)
        ->and($recorder->provenanceFor('invocation-123'))->toBe([]);
})->with([
    'empty correlation' => ['', null, null],
    'correlation separator' => ['invocation:123', null, null],
    'component separator' => ['invocation-123', 'retriever:v2', null],
    'version separator' => ['invocation-123', 'retriever', 'release:v2'],
    'version without component' => ['invocation-123', null, 'v2'],
]);

it('retrieves only provenance for the requested correlation', function (): void {
    $recorder = new InMemoryEvidenceRecorder;
    $ledger = provenanceLedger($recorder);

    foreach (['invocation-1', 'invocation-2', 'invocation-1'] as $correlationId) {
        $ledger->record(
            correlationId: $correlationId,
            source: Source::application('checkout'),
            trust: Trust::Trusted,
            dataClass: DataClass::Internal,
            channel: ContextChannel::ApplicationContext,
            content: ['tenant_id' => 91],
        );
    }

    $entries = $ledger->forCorrelation('invocation-1');

    expect($entries)->toHaveCount(2)
        ->and(array_column($entries, 'correlationId'))->each->toBe('invocation-1');
});

it('records multiple typed derivation parents for one child', function (): void {
    $recorder = new InMemoryEvidenceRecorder;
    $child = str_repeat('c', 64);
    $parents = [str_repeat('a', 64), str_repeat('b', 64)];

    $derivations = provenanceLedger($recorder)->declareDerivation(
        correlationId: 'invocation-123',
        childContentFingerprint: $child,
        parentContentFingerprints: $parents,
        kind: DerivationKind::Summarized,
    );

    expect($derivations)->toHaveCount(2)
        ->and(array_column($derivations, 'parentContentFingerprint'))->toBe($parents)
        ->and($recorder->derivationsFor('invocation-123', $child))->toEqual($derivations);
});

it('rejects a transitive derivation cycle', function (): void {
    $ledger = provenanceLedger(new InMemoryEvidenceRecorder);
    $first = str_repeat('a', 64);
    $second = str_repeat('b', 64);
    $third = str_repeat('c', 64);

    $ledger->declareDerivation('invocation-123', $second, [$first], DerivationKind::Transformed);
    $ledger->declareDerivation('invocation-123', $third, [$second], DerivationKind::Summarized);

    expect(fn (): array => $ledger->declareDerivation(
        'invocation-123',
        $first,
        [$third],
        DerivationKind::ToolResult,
    ))->toThrow(LogicException::class, 'cannot create a cycle');
});

it('returns every transitively contributing content fingerprint', function (): void {
    $ledger = provenanceLedger(new InMemoryEvidenceRecorder);
    $first = str_repeat('a', 64);
    $second = str_repeat('b', 64);
    $third = str_repeat('c', 64);

    $ledger->declareDerivation('invocation-123', $second, [$first], DerivationKind::Transformed);
    $ledger->declareDerivation('invocation-123', $third, [$second], DerivationKind::Summarized);

    expect($ledger->backwardReachableContentFingerprints('invocation-123', $third))
        ->toBe([$second, $first]);
});

it('propagates recorder failures instead of reporting provenance success', function (): void {
    $recorder = new class implements EvidenceRecorder
    {
        public function record(DecisionEvidence $evidence): void {}

        public function recordRelease(ContextReleaseEvidence $evidence): void {}

        public function recordProvenance(ProvenanceEntry $entry): void
        {
            throw new RuntimeException('Recorder unavailable.');
        }

        public function recordDerivation(ProvenanceDerivation $derivation): void {}

        public function provenanceFor(string $correlationId): array
        {
            return [];
        }

        public function derivationsFor(string $correlationId, string $childContentFingerprint): array
        {
            return [];
        }
    };

    expect(fn (): ProvenanceEntry => provenanceLedger($recorder)->record(
        correlationId: 'invocation-123',
        source: Source::application('checkout'),
        trust: Trust::Trusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::ApplicationContext,
        content: 'trusted context',
    ))->toThrow(RuntimeException::class, 'Recorder unavailable.');
});

it('supports the explicit null recorder contract', function (): void {
    $ledger = provenanceLedger(new NullEvidenceRecorder);

    $ledger->record(
        correlationId: 'invocation-123',
        source: Source::user('customer'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Public,
        channel: ContextChannel::UserInput,
        content: 'hello',
    );

    expect($ledger->forCorrelation('invocation-123'))->toBe([]);
});

it('refuses to report declared upstream provenance that describes nothing', function (): void {
    expect(fn (): DeclaredUpstream => DeclaredUpstream::declared([], []))
        ->toThrow(InvalidArgumentException::class, 'requires an entry or an unresolved content fingerprint');
});

it('summarises declared upstream entries for a proposal, nearest first', function (): void {
    $ledger = provenanceLedger(new InMemoryEvidenceRecorder);
    $retrieved = $ledger->record(
        correlationId: 'invocation-123',
        source: Source::external('knowledge-base'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::RetrievedDocument,
        content: 'untrusted retrieved document',
    );
    $summary = $ledger->record(
        correlationId: 'invocation-123',
        source: Source::application('rag-pipeline'),
        trust: Trust::Trusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::ApplicationContext,
        content: 'summary of the retrieved document',
    );
    $proposal = $ledger->record(
        correlationId: 'invocation-123',
        source: Source::application('assistant'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::ApplicationContext,
        content: ['recipient' => 'attacker', 'amount' => 500],
    );
    $ledger->declareDerivation('invocation-123', $summary->contentFingerprint, [$retrieved->contentFingerprint], DerivationKind::Summarized);
    $ledger->declareDerivation('invocation-123', $proposal->contentFingerprint, [$summary->contentFingerprint], DerivationKind::Transformed);

    $upstream = $ledger->declaredUpstreamOf('invocation-123', $proposal->contentFingerprint);

    expect($upstream->isDeclared())->toBeTrue()
        ->and($upstream->entries)->toBe([$summary, $retrieved])
        ->and($upstream->unresolvedContentFingerprints)->toBe([]);
});

it('reports an undeclared proposal as undeclared rather than as empty upstream', function (): void {
    $ledger = provenanceLedger(new InMemoryEvidenceRecorder);
    $proposal = $ledger->record(
        correlationId: 'invocation-123',
        source: Source::application('assistant'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::ApplicationContext,
        content: ['recipient' => 'attacker', 'amount' => 500],
    );

    $upstream = $ledger->declaredUpstreamOf('invocation-123', $proposal->contentFingerprint);

    expect($upstream->isDeclared())->toBeFalse()
        ->and($upstream->entries)->toBe([])
        ->and($upstream->unresolvedContentFingerprints)->toBe([]);
});

it('omits invocation-correlated entries that were never declared upstream', function (): void {
    $ledger = provenanceLedger(new InMemoryEvidenceRecorder);
    $declared = $ledger->record(
        correlationId: 'invocation-123',
        source: Source::external('knowledge-base'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::RetrievedDocument,
        content: 'declared upstream document',
    );
    $ledger->record(
        correlationId: 'invocation-123',
        source: Source::external('knowledge-base'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::RetrievedDocument,
        content: 'merely correlated document',
    );
    $proposal = $ledger->record(
        correlationId: 'invocation-123',
        source: Source::application('assistant'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::ApplicationContext,
        content: ['recipient' => 'attacker', 'amount' => 500],
    );
    $ledger->declareDerivation('invocation-123', $proposal->contentFingerprint, [$declared->contentFingerprint], DerivationKind::Summarized);

    expect($ledger->declaredUpstreamOf('invocation-123', $proposal->contentFingerprint)->entries)
        ->toBe([$declared]);
});

it('reports a declared parent with no recorded entry as unresolved', function (): void {
    $ledger = provenanceLedger(new InMemoryEvidenceRecorder);
    $proposal = $ledger->record(
        correlationId: 'invocation-123',
        source: Source::application('assistant'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::ApplicationContext,
        content: ['recipient' => 'attacker', 'amount' => 500],
    );
    $unrecorded = ContentFingerprint::make('a document the application never recorded');
    $ledger->declareDerivation('invocation-123', $proposal->contentFingerprint, [$unrecorded], DerivationKind::ToolResult);

    $upstream = $ledger->declaredUpstreamOf('invocation-123', $proposal->contentFingerprint);

    expect($upstream->isDeclared())->toBeTrue()
        ->and($upstream->entries)->toBe([])
        ->and($upstream->unresolvedContentFingerprints)->toBe([$unrecorded]);
});
