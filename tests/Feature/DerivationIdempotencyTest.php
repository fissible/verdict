<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\DerivationKind;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Fissible\Verdict\Tests\Support\EvidenceTableSchema;
use Illuminate\Database\DatabaseManager;

/**
 * #311 item 3 — a re-declared derivation edge (same correlation, child, parent, kind) is intended to
 * be idempotent: ProvenanceLedger::declareDerivation returns it and the cycle guard treats repeated
 * declaration as a no-op. But the two recorders disagreed: the database recorder's raw insert hit the
 * composite primary key and threw UniqueConstraintViolationException, while the in-memory recorder
 * silently appended a duplicate. Neither is idempotent. These tests pin idempotent-on-full-identity
 * for both, keep genuinely distinct edges distinct, and keep the (best-effort) cycle guard intact.
 */
beforeEach(function (): void {
    EvidenceTableSchema::createDerivations();
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('derivations'));
});

dataset('derivationRecorders', [
    'in-memory' => [fn (): InMemoryEvidenceRecorder => new InMemoryEvidenceRecorder],
    'database' => [fn (): DatabaseEvidenceRecorder => new DatabaseEvidenceRecorder(
        app(DatabaseManager::class)->connection(),
        verdictTable('evidence'),
        verdictTable('derivations'),
    )],
]);

function derivationEdge(
    string $child,
    string $parent,
    DerivationKind $kind = DerivationKind::Transformed,
    string $correlationId = 'invocation-1',
    string $recordedAt = '2026-08-03 12:00:00',
): ProvenanceDerivation {
    return new ProvenanceDerivation(
        correlationId: $correlationId,
        childContentFingerprint: $child,
        parentContentFingerprint: $parent,
        kind: $kind,
        recordedAt: new DateTimeImmutable($recordedAt, new DateTimeZone('UTC')),
    );
}

$child = str_repeat('c', 64);
$parent = str_repeat('e', 64);

it('records a duplicate derivation edge idempotently — exactly one edge survives', function (Closure $make) use ($child, $parent): void {
    $recorder = $make();
    $edge = derivationEdge($child, $parent);

    $recorder->recordDerivation($edge);
    $recorder->recordDerivation($edge);

    expect($recorder->derivationsFor('invocation-1', $child))->toHaveCount(1);
})->with('derivationRecorders');

it('keeps edges that differ only by kind as two distinct edges', function (Closure $make) use ($child, $parent): void {
    $recorder = $make();
    $recorder->recordDerivation(derivationEdge($child, $parent, DerivationKind::Transformed));
    $recorder->recordDerivation(derivationEdge($child, $parent, DerivationKind::Summarized));

    // Idempotency is keyed on the FULL identity; a different kind is a different edge.
    expect($recorder->derivationsFor('invocation-1', $child))->toHaveCount(2);
})->with('derivationRecorders');

it('keeps edges that differ by parent as two distinct edges', function (Closure $make) use ($child, $parent): void {
    $recorder = $make();
    $otherParent = str_repeat('f', 64);
    $recorder->recordDerivation(derivationEdge($child, $parent));
    $recorder->recordDerivation(derivationEdge($child, $otherParent));

    expect($recorder->derivationsFor('invocation-1', $child))->toHaveCount(2);
})->with('derivationRecorders');

it('makes ProvenanceLedger::declareDerivation idempotent across repeated identical declarations', function (Closure $make) use ($child, $parent): void {
    $recorder = $make();
    $ledger = new ProvenanceLedger($recorder, $recorder, new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-08-03 12:00:00', new DateTimeZone('UTC'));
        }
    });

    $ledger->declareDerivation('invocation-1', $child, [$parent], DerivationKind::Transformed);
    $ledger->declareDerivation('invocation-1', $child, [$parent], DerivationKind::Transformed);

    expect($recorder->derivationsFor('invocation-1', $child))->toHaveCount(1);
})->with('derivationRecorders');

it('treats the same edge under a different correlation id as a distinct edge', function (Closure $make) use ($child, $parent): void {
    $recorder = $make();
    $recorder->recordDerivation(derivationEdge($child, $parent, correlationId: 'invocation-1'));
    $recorder->recordDerivation(derivationEdge($child, $parent, correlationId: 'invocation-2'));

    // A dedupe key that omitted correlationId would drop the second — each correlation keeps its own.
    expect($recorder->derivationsFor('invocation-1', $child))->toHaveCount(1)
        ->and($recorder->derivationsFor('invocation-2', $child))->toHaveCount(1);
})->with('derivationRecorders');

it('treats the same parent under a different child as a distinct edge', function (Closure $make) use ($parent): void {
    $recorder = $make();
    $childA = str_repeat('1', 64);
    $childB = str_repeat('2', 64);
    $recorder->recordDerivation(derivationEdge($childA, $parent));
    $recorder->recordDerivation(derivationEdge($childB, $parent));

    // A dedupe key that omitted the child would drop the second.
    expect($recorder->derivationsFor('invocation-1', $childA))->toHaveCount(1)
        ->and($recorder->derivationsFor('invocation-1', $childB))->toHaveCount(1);
})->with('derivationRecorders');

it('keeps the first edge on a duplicate, preserving the original recorded_at', function (Closure $make) use ($child, $parent): void {
    $recorder = $make();
    $recorder->recordDerivation(derivationEdge($child, $parent, recordedAt: '2026-08-03 12:00:00'));
    // Same identity, later timestamp — must be ignored, not overwrite the first.
    $recorder->recordDerivation(derivationEdge($child, $parent, recordedAt: '2026-08-03 13:30:00'));

    $stored = $recorder->derivationsFor('invocation-1', $child);

    expect($stored)->toHaveCount(1)
        ->and($stored[0]->recordedAt->format('Y-m-d H:i:s'))->toBe('2026-08-03 12:00:00');
})->with('derivationRecorders');

it('returns the declared edge from declareDerivation on both the first and the idempotent repeat', function (Closure $make) use ($child, $parent): void {
    $recorder = $make();
    $ledger = new ProvenanceLedger($recorder, $recorder, new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-08-03 12:00:00', new DateTimeZone('UTC'));
        }
    });

    $first = $ledger->declareDerivation('invocation-1', $child, [$parent], DerivationKind::Transformed);
    $second = $ledger->declareDerivation('invocation-1', $child, [$parent], DerivationKind::Transformed);

    expect($first)->toHaveCount(1)
        ->and($first[0]->childContentFingerprint)->toBe($child)
        ->and($first[0]->parentContentFingerprint)->toBe($parent)
        ->and($second)->toHaveCount(1)
        ->and($second[0]->childContentFingerprint)->toBe($child)
        ->and($second[0]->parentContentFingerprint)->toBe($parent);
})->with('derivationRecorders');

it('still rejects a derivation cycle after duplicates are made idempotent (best-effort guard intact)', function (Closure $make): void {
    $recorder = $make();
    $ledger = new ProvenanceLedger($recorder, $recorder, new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-08-03 12:00:00', new DateTimeZone('UTC'));
        }
    });
    $first = str_repeat('a', 64);
    $second = str_repeat('b', 64);
    $third = str_repeat('d', 64);

    $ledger->declareDerivation('invocation-1', $second, [$first], DerivationKind::Transformed);
    $ledger->declareDerivation('invocation-1', $third, [$second], DerivationKind::Summarized);

    expect(fn (): array => $ledger->declareDerivation('invocation-1', $first, [$third], DerivationKind::ToolResult))
        ->toThrow(LogicException::class, 'cannot create a cycle');
})->with('derivationRecorders');
