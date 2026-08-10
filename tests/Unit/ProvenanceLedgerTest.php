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
    return new ProvenanceLedger($recorder ?? new InMemoryEvidenceRecorder, new ProvenanceTestClock);
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

it('propagates recorder failures instead of reporting provenance success', function (): void {
    $recorder = new class implements EvidenceRecorder
    {
        public function record(DecisionEvidence $evidence): void {}

        public function recordRelease(ContextReleaseEvidence $evidence): void {}

        public function recordProvenance(ProvenanceEntry $entry): void
        {
            throw new RuntimeException('Recorder unavailable.');
        }

        public function provenanceFor(string $correlationId): array
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
