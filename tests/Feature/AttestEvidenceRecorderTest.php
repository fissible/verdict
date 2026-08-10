<?php

declare(strict_types=1);

use Fissible\Attest\Chain\ChainStore;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Destination;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\DerivationKind;
use Fissible\Verdict\Evidence\Events\ChainWriteFailed;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\Exceptions\EvidenceChainWriteFailed;
use Fissible\Verdict\Tests\Support\AttestFixture;
use Fissible\Verdict\Tests\Support\FlakyChainStore;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;

function chainGapRows(): array
{
    return app(DatabaseManager::class)->connection()
        ->table('verdict_evidence')
        ->where('record_type', 'chain_gap')
        ->get()
        ->all();
}

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists('verdict_evidence');
    $schema->create('verdict_evidence', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('record_type', 32);
        $table->string('correlation_id')->nullable();
        $table->string('stage', 32);
        $table->string('disposition', 32);
        $table->text('reason')->nullable();
        $table->timestamp('recorded_at');
    });

    $this->decision = new DecisionEvidence(
        envelopeId: 'env-1',
        capability: 'orders.refund',
        stage: 'authorization',
        disposition: 'permit',
        reason: null,
        argumentFingerprint: hash('sha256', 'args'),
        idempotencyKey: null,
        approvalReceiptFingerprint: null,
        approvalPhase: null,
        approvalOutcome: null,
        targetPolicy: null,
        targetStrategy: null,
        proposalTargetIdentityFingerprint: null,
        executionTargetIdentityFingerprint: null,
        targetIdentityMatched: null,
        rateLimitKeyFingerprint: null,
        rateLimitPolicy: null,
        rateLimitLimit: null,
        rateLimitRemaining: null,
        rateLimitResetAt: null,
        executionClaimFingerprint: null,
        executionClaimBindingFingerprint: null,
        executionClaimPolicy: null,
        executionClaimStatus: null,
        executionClaimAttempt: null,
        recordedAt: new DateTimeImmutable('2026-08-09T00:00:00+00:00'),
    );
});

function makeRecorder(ChainStore $store, string $onFailure = 'alert'): AttestEvidenceRecorder
{
    return new AttestEvidenceRecorder(
        attest: AttestFixture::registry($store),
        fallback: new InMemoryEvidenceRecorder,
        connection: app(DatabaseManager::class)->connection(),
        events: app(Dispatcher::class),
        chainIdUsing: fn (): string => 'verdict',
        onFailure: $onFailure,
        baseDelayMs: 1,
    );
}

it('writes a decision to the attest chain', function (): void {
    $store = AttestFixture::store();
    $recorder = makeRecorder($store);

    $recorder->record($this->decision);

    $tail = $store->tail('verdict');

    expect($tail)->not->toBeNull()
        ->and($tail->envelope->type)->toBe('verdict.decision')
        ->and($tail->envelope->correlation)->toBe('env-1')
        ->and($tail->envelope->payload['capability'])->toBe('orders.refund')
        ->and($tail->envelope->payload['disposition'])->toBe('permit');

    expect(chainGapRows())->toBeEmpty();
});

it('writes a context release to the attest chain keyed by invocation id', function (): void {
    $store = AttestFixture::store();
    $recorder = makeRecorder($store);

    $release = ContextReleaseEvidence::make(
        source: Source::application('order-lookup'),
        destination: Destination::connection('gpt', 'model'),
        trust: Trust::Trusted,
        dataClass: DataClass::Internal,
        permitted: true,
        reason: 'allowed',
        requestedPaths: ['order.id'],
        releasedPaths: ['order.id'],
        payloadFingerprint: null,
        recordedAt: new DateTimeImmutable('2026-08-09T00:00:00+00:00'),
        invocationId: 'inv-1',
    );

    $recorder->recordRelease($release);

    $tail = $store->tail('verdict');

    expect($tail->envelope->type)->toBe('verdict.context_release')
        ->and($tail->envelope->correlation)->toBe('inv-1')
        ->and($tail->envelope->payload['disposition'])->toBe('permit');
});

it('retries a transient chain lock failure and still writes the decision', function (): void {
    $store = new FlakyChainStore(AttestFixture::store(), failures: 2);
    $recorder = makeRecorder($store);

    $recorder->record($this->decision);

    expect($store->counter()->appends)->toBe(3)
        ->and(chainGapRows())->toBeEmpty();
});

it('records a chain gap marker and dispatches an event when retries are exhausted, but does not throw', function (): void {
    Event::fake([ChainWriteFailed::class]);

    $store = new FlakyChainStore(AttestFixture::store(), failures: 99);
    $recorder = makeRecorder($store);

    $recorder->record($this->decision);

    $rows = chainGapRows();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->correlation_id)->toBe('env-1')
        ->and($rows[0]->stage)->toBe('decision')
        ->and($rows[0]->disposition)->toBe('gap');

    $reason = json_decode((string) $rows[0]->reason, true, flags: JSON_THROW_ON_ERROR);
    expect($reason['chain'])->toBe('verdict')
        ->and($reason['attempts'])->toBe(3);

    Event::assertDispatched(ChainWriteFailed::class, fn (ChainWriteFailed $e): bool => $e->correlationId === 'env-1'
        && $e->recordType === 'decision'
        && $e->attempts === 3);
});

it('does not throw when the gap-marker DB write itself fails in alert mode', function (): void {
    Event::fake([ChainWriteFailed::class]);

    // Drop the evidence table so the fallback insert() inside recordGap() genuinely
    // throws a QueryException, simulating the correlated-failure case where whatever
    // broke the chain write also broke the DB write.
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->drop('verdict_evidence');

    $store = new FlakyChainStore(AttestFixture::store(), failures: 99);
    $recorder = makeRecorder($store);

    $recorder->record($this->decision);

    Event::assertDispatched(ChainWriteFailed::class, fn (ChainWriteFailed $e): bool => $e->correlationId === 'env-1'
        && $e->recordType === 'decision'
        && $e->attempts === 3);
});

it('does not throw when the chain id resolver itself throws, even in alert mode', function (): void {
    Event::fake([ChainWriteFailed::class]);

    $store = AttestFixture::store();
    $recorder = new AttestEvidenceRecorder(
        attest: AttestFixture::registry($store),
        fallback: new InMemoryEvidenceRecorder,
        connection: app(DatabaseManager::class)->connection(),
        events: app(Dispatcher::class),
        chainIdUsing: function (): string {
            throw new RuntimeException('tenant resolution failed');
        },
        baseDelayMs: 1,
    );

    $recorder->record($this->decision);

    $rows = chainGapRows();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->correlation_id)->toBe('env-1');

    Event::assertDispatched(ChainWriteFailed::class);
});

it('throws instead of swallowing when on_failure is throw', function (): void {
    $store = new FlakyChainStore(AttestFixture::store(), failures: 99);
    $recorder = makeRecorder($store, onFailure: 'throw');

    expect(fn () => $recorder->record($this->decision))
        ->toThrow(EvidenceChainWriteFailed::class, 'Failed to write [decision] evidence to attest chain [verdict] after 3 attempt(s).');

    // The gap marker is still written even in throw mode — decision 5 in issue #11
    // separates "record the gap" from "how to react," and both apply together.
    expect(chainGapRows())->toHaveCount(1);
});

it('always delegates provenance to the fallback recorder for reads', function (): void {
    $store = AttestFixture::store();
    $fallback = new InMemoryEvidenceRecorder;
    $recorder = new AttestEvidenceRecorder(
        attest: AttestFixture::registry($store),
        fallback: $fallback,
        connection: app(DatabaseManager::class)->connection(),
        events: app(Dispatcher::class),
        chainIdUsing: fn (): string => 'verdict',
        baseDelayMs: 1,
    );

    $entry = new ProvenanceEntry(
        correlationId: 'inv-1',
        source: Source::external('search-api'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::ToolResult,
        contentFingerprint: hash('sha256', 'doc'),
        componentLabel: null,
        componentFingerprint: null,
        recordedAt: new DateTimeImmutable('2026-08-09T00:00:00+00:00'),
    );

    $recorder->recordProvenance($entry);

    expect($recorder->provenanceFor('inv-1'))->toEqual([$entry])
        ->and($store->tail('verdict'))->toBeNull(); // chain_provenance defaults to false: not chained

    $derivation = new ProvenanceDerivation(
        correlationId: 'inv-1',
        childContentFingerprint: hash('sha256', 'child'),
        parentContentFingerprint: hash('sha256', 'doc'),
        kind: DerivationKind::Retrieved,
        recordedAt: new DateTimeImmutable('2026-08-09T00:00:00+00:00'),
    );

    $recorder->recordDerivation($derivation);

    expect($recorder->derivationsFor('inv-1', hash('sha256', 'child')))->toEqual([$derivation]);
});

it('also chains provenance and derivations when chain_provenance is enabled, without losing reads', function (): void {
    $store = AttestFixture::store();
    $fallback = new InMemoryEvidenceRecorder;
    $recorder = new AttestEvidenceRecorder(
        attest: AttestFixture::registry($store),
        fallback: $fallback,
        connection: app(DatabaseManager::class)->connection(),
        events: app(Dispatcher::class),
        chainIdUsing: fn (): string => 'verdict',
        chainProvenance: true,
        baseDelayMs: 1,
    );

    $entry = new ProvenanceEntry(
        correlationId: 'inv-1',
        source: Source::external('search-api'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        channel: ContextChannel::ToolResult,
        contentFingerprint: hash('sha256', 'doc'),
        componentLabel: null,
        componentFingerprint: null,
        recordedAt: new DateTimeImmutable('2026-08-09T00:00:00+00:00'),
    );

    $recorder->recordProvenance($entry);

    expect($recorder->provenanceFor('inv-1'))->toEqual([$entry]);

    $tail = $store->tail('verdict');
    expect($tail->envelope->type)->toBe('verdict.provenance')
        ->and($tail->envelope->correlation)->toBe('inv-1')
        ->and($tail->envelope->payload['content_fingerprint'])->toBe(hash('sha256', 'doc'));
});
