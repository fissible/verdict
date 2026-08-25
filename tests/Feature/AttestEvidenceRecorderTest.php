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
use Fissible\Verdict\Evidence\RecordDigest;
use Fissible\Verdict\Exceptions\EvidenceChainWriteFailed;
use Fissible\Verdict\Tests\Support\AttestFixture;
use Fissible\Verdict\Tests\Support\FlakyChainStore;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;

function attestChainGapRows(): array
{
    return app(DatabaseManager::class)->connection()
        ->table(verdictTable('evidence'))
        ->where('record_type', 'chain_gap')
        ->get()
        ->all();
}

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('evidence'));
    $schema->dropIfExists(verdictTable('derivations'));
    $schema->create(verdictTable('evidence'), function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('record_type', 32);
        $table->string('correlation_id')->nullable();
        $table->string('invocation_id')->nullable();
        $table->string('intent_id', 64)->nullable();
        $table->string('capability')->nullable();
        $table->string('stage', 32);
        $table->string('disposition', 32);
        $table->text('reason')->nullable();
        $table->string('source')->nullable();
        $table->string('destination')->nullable();
        $table->string('trust_zone')->nullable();
        $table->string('trust', 32)->nullable();
        $table->string('data_class', 32)->nullable();
        $table->char('argument_fingerprint', 64)->nullable();
        $table->char('idempotency_key_fingerprint', 64)->nullable();
        $table->char('approval_receipt_fingerprint', 64)->nullable();
        $table->string('approval_phase', 32)->nullable();
        $table->string('approval_outcome', 32)->nullable();
        $table->string('target_policy')->nullable();
        $table->string('target_strategy', 32)->nullable();
        $table->char('proposal_target_identity_fingerprint', 64)->nullable();
        $table->char('execution_target_identity_fingerprint', 64)->nullable();
        $table->boolean('target_identity_matched')->nullable();
        $table->char('rate_limit_key_fingerprint', 64)->nullable();
        $table->string('rate_limit_policy')->nullable();
        $table->unsignedInteger('rate_limit_limit')->nullable();
        $table->unsignedInteger('rate_limit_remaining')->nullable();
        $table->timestamp('rate_limit_reset_at')->nullable();
        $table->char('execution_claim_fingerprint', 64)->nullable();
        $table->char('execution_claim_binding_fingerprint', 64)->nullable();
        $table->string('execution_claim_policy')->nullable();
        $table->string('execution_claim_status', 24)->nullable();
        $table->unsignedInteger('execution_claim_attempt')->nullable();
        $table->json('requested_path_fingerprints')->nullable();
        $table->json('released_path_fingerprints')->nullable();
        $table->json('transform_fingerprints')->nullable();
        $table->json('transformed_path_fingerprints')->nullable();
        $table->unsignedInteger('transformation_count')->default(0);
        $table->char('payload_fingerprint', 64)->nullable();
        $table->string('channel', 32)->nullable();
        $table->string('component_label')->nullable();
        $table->char('component_fingerprint', 64)->nullable();
        $table->char('content_fingerprint', 64)->nullable();
        $table->timestamp('recorded_at');
    });
    $schema->create(verdictTable('derivations'), function (Blueprint $table): void {
        $table->string('correlation_id');
        $table->char('child_content_fingerprint', 64);
        $table->char('parent_content_fingerprint', 64);
        $table->string('kind', 32);
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
        actorFingerprint: hash('sha256', 'support-agent:17'),
        subjectFingerprint: hash('sha256', 'customer:72'),
    );
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('evidence'));
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists(verdictTable('derivations'));
});

function makeAttestEvidenceRecorder(ChainStore $store, string $onFailure = 'alert'): AttestEvidenceRecorder
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
    $recorder = makeAttestEvidenceRecorder($store);

    $recorder->record($this->decision);

    $tail = $store->tail('verdict');

    expect($tail)->not->toBeNull()
        ->and($tail->envelope->type)->toBe('verdict.decision')
        ->and($tail->envelope->correlation)->toBe('env-1')
        ->and($tail->envelope->payload['capability'])->toBe('orders.refund')
        ->and($tail->envelope->payload['actor_fingerprint'])->toBe(hash('sha256', 'support-agent:17'))
        ->and($tail->envelope->payload['subject_fingerprint'])->toBe(hash('sha256', 'customer:72'))
        ->and($tail->envelope->payload['disposition'])->toBe('permit')
        // Attest signs its own envelope, not a Verdict-computed hash — so the only way its
        // signature can cover Verdict's identity for this record is for the identity to travel
        // inside the payload. See RecordDigest.
        ->and($tail->envelope->payload['record_digest'])->toBe($this->decision->recordDigest)
        ->and($tail->envelope->payload['record_digest'])->toStartWith(RecordDigest::SCHEME.':')
        ->and($tail->envelope->payload['claim_type'])->toBe($this->decision->claimType?->value);

    expect(attestChainGapRows())->toBeEmpty();
});

it('writes a context release to the attest chain keyed by invocation id', function (): void {
    $store = AttestFixture::store();
    $recorder = makeAttestEvidenceRecorder($store);

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
    $recorder = makeAttestEvidenceRecorder($store);

    $recorder->record($this->decision);

    expect($store->counter()->appends)->toBe(3)
        ->and(attestChainGapRows())->toBeEmpty();
});

it('records a chain gap marker and dispatches an event when retries are exhausted, but does not throw', function (): void {
    Event::fake([ChainWriteFailed::class]);

    $store = new FlakyChainStore(AttestFixture::store(), failures: 99);
    $recorder = makeAttestEvidenceRecorder($store);

    $recorder->record($this->decision);

    $rows = attestChainGapRows();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->correlation_id)->toBe('env-1')
        ->and($rows[0]->stage)->toBe('decision')
        ->and($rows[0]->disposition)->toBe('gap');

    $reason = json_decode((string) $rows[0]->reason, true, flags: JSON_THROW_ON_ERROR);
    expect($reason['chain'])->toBe('verdict')
        ->and($reason['attempts'])->toBe(3)
        ->and($reason['phase'])->toBe('append');

    Event::assertDispatched(ChainWriteFailed::class, fn (ChainWriteFailed $e): bool => $e->correlationId === 'env-1'
        && $e->recordType === 'decision'
        && $e->phase === 'append'
        && $e->attempts === 3);
});

it('records a chain gap marker for an exhausted context release too, without throwing', function (): void {
    Event::fake([ChainWriteFailed::class]);

    $store = new FlakyChainStore(AttestFixture::store(), failures: 99);
    $recorder = makeAttestEvidenceRecorder($store);

    $recorder->recordRelease(ContextReleaseEvidence::make(
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
        invocationId: 'inv-gap-1',
    ));

    $rows = attestChainGapRows();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->correlation_id)->toBe('inv-gap-1')
        ->and($rows[0]->stage)->toBe('context_release')
        ->and($rows[0]->disposition)->toBe('gap');

    $reason = json_decode((string) $rows[0]->reason, true, flags: JSON_THROW_ON_ERROR);
    expect($reason['chain'])->toBe('verdict')
        ->and($reason['attempts'])->toBe(3)
        ->and($reason['phase'])->toBe('append');

    Event::assertDispatched(ChainWriteFailed::class, fn (ChainWriteFailed $e): bool => $e->correlationId === 'inv-gap-1'
        && $e->recordType === 'context_release'
        && $e->phase === 'append'
        && $e->attempts === 3);
});

it('does not throw when the gap-marker DB write itself fails in alert mode', function (): void {
    Event::fake([ChainWriteFailed::class]);

    // Drop the evidence table so the fallback insert() inside recordGap() genuinely
    // throws a QueryException, simulating the correlated-failure case where whatever
    // broke the chain write also broke the DB write.
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->drop(verdictTable('evidence'));

    $store = new FlakyChainStore(AttestFixture::store(), failures: 99);
    $recorder = makeAttestEvidenceRecorder($store);

    $recorder->record($this->decision);

    Event::assertDispatched(ChainWriteFailed::class, fn (ChainWriteFailed $e): bool => $e->correlationId === 'env-1'
        && $e->recordType === 'decision'
        && $e->phase === 'append'
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

    $rows = attestChainGapRows();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->correlation_id)->toBe('env-1');

    $reason = json_decode((string) $rows[0]->reason, true, flags: JSON_THROW_ON_ERROR);
    expect($reason['attempts'])->toBe(0)
        ->and($reason['phase'])->toBe('resolve_chain_id');

    Event::assertDispatched(ChainWriteFailed::class, fn (ChainWriteFailed $e): bool => $e->phase === 'resolve_chain_id'
        && $e->attempts === 0);
});

it('throws instead of swallowing when on_failure is throw', function (): void {
    $store = new FlakyChainStore(AttestFixture::store(), failures: 99);
    $recorder = makeAttestEvidenceRecorder($store, onFailure: 'throw');

    expect(fn () => $recorder->record($this->decision))
        ->toThrow(EvidenceChainWriteFailed::class, 'Failed to write [decision] evidence to attest chain [verdict] after 3 attempt(s).');

    // The gap marker is still written even in throw mode — decision 5 in issue #11
    // separates "record the gap" from "how to react," and both apply together.
    expect(attestChainGapRows())->toHaveCount(1);
});

it('rejects a non-positive max attempts configuration', function (): void {
    expect(fn () => new AttestEvidenceRecorder(
        attest: AttestFixture::registry(AttestFixture::store()),
        fallback: new InMemoryEvidenceRecorder,
        connection: app(DatabaseManager::class)->connection(),
        events: app(Dispatcher::class),
        chainIdUsing: fn (): string => 'verdict',
        maxAttempts: 0,
    ))->toThrow(InvalidArgumentException::class, 'The maximum attempts must be at least 1, got [0].');
});

it('rejects a negative base delay configuration', function (): void {
    expect(fn () => new AttestEvidenceRecorder(
        attest: AttestFixture::registry(AttestFixture::store()),
        fallback: new InMemoryEvidenceRecorder,
        connection: app(DatabaseManager::class)->connection(),
        events: app(Dispatcher::class),
        chainIdUsing: fn (): string => 'verdict',
        baseDelayMs: -1,
    ))->toThrow(InvalidArgumentException::class, 'The base delay in milliseconds must not be negative, got [-1].');
});

/** @verdict-claim limitation.tamper-evidence */
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
