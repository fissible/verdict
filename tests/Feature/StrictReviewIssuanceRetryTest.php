<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\AttestsIssuance;
use Fissible\Verdict\Evidence\ApprovalLane;
use Fissible\Verdict\Exceptions\AttestedIssuanceAppendFailed;
use Fissible\Verdict\Reviews\DatabaseReviewRequestStore;
use Fissible\Verdict\Reviews\ReviewOutcome;
use Fissible\Verdict\Reviews\ReviewRequest;
use Fissible\Verdict\Reviews\ReviewStatus;
use Fissible\Verdict\Support\ApproverSummary;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;

// ADR 0039 (a)/(b), review-lane analog of #513: the strict-issuance attest runs inside the admission-locked
// SecurityStateTransaction, at the clear-to-mint point. A transaction retry re-runs the whole closure —
// including the attest hook — so the attest must be idempotent per sha256($id): the id is fixed on the request
// (never re-randomized on retry), so every re-run anchors the identical fingerprint, and a retry must not
// double-attest. This proves that end to end against the DATABASE review store, forcing a genuine retry.

beforeEach(function (): void {
    (require __DIR__.'/../../database/migrations/create_verdict_binding_admission_locks_table.php.stub')->up();

    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists('verdict_review_requests');
    $schema->create('verdict_review_requests', function (Blueprint $table): void {
        $table->string('id', 64)->primary();
        $table->string('capability');
        $table->char('binding_fingerprint', 64);
        $table->string('status', 24);
        $table->text('reason')->nullable();
        $table->timestamp('expires_at');
        $table->string('resolved_by')->nullable();
        $table->timestamp('resolved_at')->nullable();
        $table->timestamp('consumed_at')->nullable();
        $table->text('provenance')->nullable();
        $table->text('approval_context')->nullable();
        $table->text('approver_summary')->nullable();
        $table->timestamps();
        $table->unique(['capability', 'binding_fingerprint'], 'verdict_review_requests_binding_unique');
    });
});

afterEach(function (): void {
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists('verdict_binding_admission_locks');
    app(DatabaseManager::class)->connection()->getSchemaBuilder()->dropIfExists('verdict_review_requests');
});

/**
 * Idempotent by design: records a distinct attestation per identity fingerprint. A re-run of the
 * admission closure on a transaction retry invokes it again with the SAME fingerprint, and the
 * dedup absorbs it — modelling the real anchor, which is content-addressed on sha256($id) and
 * appends the same summary at most once. rawInvocations exposes the raw call count so the test can
 * assert the retry genuinely re-ran the hook that idempotency then collapsed.
 */
final class IdempotentRecordingReviewAttester implements AttestsIssuance
{
    /** @var list<string> distinct identity fingerprints anchored, in first-seen order */
    public array $calls = [];

    public int $rawInvocations = 0;

    public function attestIssuedSummary(ApprovalLane $lane, string $identityFingerprint, ApproverSummary $summary): void
    {
        $this->rawInvocations++;

        if (! in_array($identityFingerprint, $this->calls, true)) {
            $this->calls[] = $identityFingerprint;
        }
    }
}

it('re-runs the clear-to-mint review attest on a transaction retry without double-attesting, minting one request', function (): void {
    $real = app(DatabaseManager::class)->connection();

    // A connection whose FIRST transaction runs the closure (so the clear-to-mint attest fires) and
    // then fails as a concurrency victim, rolling back — nothing persists. The second delegates to
    // the parent and commits. No in-process fake can raise a real serialization failure, so this
    // reproduces the retry deterministically, exactly as StrictIssuanceRetryTest does.
    $connection = new class($real->getPdo(), $real->getDatabaseName(), $real->getTablePrefix(), $real->getConfig()) extends Connection
    {
        public int $attempts = 0;

        public function transaction(Closure $callback, $attempts = 1)
        {
            $this->attempts++;

            if ($this->attempts === 1) {
                $this->beginTransaction();

                try {
                    $callback();
                } finally {
                    $this->rollBack();
                }

                throw new PDOException('SQLSTATE[40001]: Serialization failure: deadlock detected', 40001);
            }

            return parent::transaction($callback, $attempts);
        }
    };

    // The base Connection ships no grammar; borrow the real SQLite connection's so schema
    // inspection and query building behave identically to the store's normal connection.
    $connection->setQueryGrammar($real->getQueryGrammar());
    $connection->setSchemaGrammar($real->getSchemaGrammar());
    $connection->setPostProcessor($real->getPostProcessor());

    $store = new DatabaseReviewRequestStore($connection);

    $now = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));
    $request = ReviewRequest::pending(
        id: 'retry-review-id-'.str_repeat('z', 48),
        capability: 'orders.cancel',
        bindingFingerprint: str_repeat('a', 64),
        approvalContext: ['tenant_id' => 'store-1'],
        createdAt: $now,
        expiresAt: $now->modify('+15 minutes'),
        reason: 'A human must review this cancellation.',
        provenance: null,
        approverSummary: new ApproverSummary('Cancel order #9001', hash('sha256', 'Cancel order #9001')),
    );

    $attest = new IdempotentRecordingReviewAttester;
    $summary = $request->approverSummary;

    // The exact hook ReviewManager::issue() installs: attest the fixed sha256($id), wrapping a
    // failure so a genuine DB error is not misclassified as an attest failure.
    $onAdmitted = function () use ($attest, $request, $summary): void {
        try {
            $attest->attestIssuedSummary(ApprovalLane::Review, hash('sha256', $request->id), $summary);
        } catch (Throwable $e) {
            throw AttestedIssuanceAppendFailed::from($e);
        }
    };

    $transition = $store->issueAdmitted($request, $onAdmitted);

    $persisted = $connection->table('verdict_review_requests')->where('id', $request->id)->count();

    expect($transition->outcome)->toBe(ReviewOutcome::Issued)
        ->and($connection->attempts)->toBe(2)              // a genuine retry occurred
        ->and($attest->rawInvocations)->toBe(2)            // the hook re-ran on the retry
        ->and($attest->calls)->toHaveCount(1)              // but attested EXACTLY ONCE — idempotent
        ->and($attest->calls[0])->toBe(hash('sha256', $request->id)) // on the stable request id
        ->and($persisted)->toBe(1)                         // exactly one request persisted
        ->and($store->find($request->id)?->status)->toBe(ReviewStatus::Pending);
});
