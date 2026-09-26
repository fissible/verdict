<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Contracts\AttestsIssuance;
use Fissible\Verdict\Evidence\ApprovalLane;
use Fissible\Verdict\Exceptions\AttestedIssuanceAppendFailed;
use Fissible\Verdict\Support\ApproverSummary;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;

// ADR 0039 (a)/(b): the strict-issuance attest runs inside the admission-locked SecurityStateTransaction,
// at the clear-to-mint point. A transaction retry re-runs the whole closure — including the attest hook —
// so the attest must be idempotent per sha256($id): the id is fixed on the receipt (never re-randomized
// on retry), so every re-run anchors the identical fingerprint, and a retry must not double-attest.
// This proves that end to end against the DATABASE store, forcing a genuine retry.

beforeEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists('verdict_approval_receipts');
    verdictInstallBindingAdmissionLockTable($schema);
    $schema->create('verdict_approval_receipts', function (Blueprint $table): void {
        $table->string('id', 64)->primary();
        $table->string('tool_call_id');
        $table->string('capability');
        $table->char('binding_fingerprint', 64);
        $table->string('status', 24);
        $table->text('reason')->nullable();
        $table->timestamp('expires_at');
        $table->string('approved_by')->nullable();
        $table->timestamp('approved_at')->nullable();
        $table->string('rejected_by')->nullable();
        $table->timestamp('rejected_at')->nullable();
        $table->timestamp('consumed_at')->nullable();
        $table->text('provenance')->nullable();
        $table->text('approval_context')->nullable();
        $table->timestamps();
        $table->unique(['tool_call_id', 'capability', 'binding_fingerprint'], 'verdict_approval_receipts_binding_unique');
    });
});

afterEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists('verdict_approval_receipts');
    $schema->dropIfExists('verdict_binding_admission_locks');
});

/**
 * Idempotent by design: records a distinct attestation per identity fingerprint. A re-run of the
 * admission closure on a transaction retry invokes it again with the SAME fingerprint, and the
 * dedup absorbs it — modelling the real anchor, which is content-addressed on sha256($id) and
 * appends the same summary at most once. rawInvocations exposes the raw call count so the test can
 * assert the retry genuinely re-ran the hook that idempotency then collapsed.
 */
final class IdempotentRecordingAttester implements AttestsIssuance
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

it('re-runs the clear-to-mint attest on a transaction retry without double-attesting, minting one receipt', function (): void {
    $real = app(DatabaseManager::class)->connection();

    // A connection whose FIRST transaction runs the closure (so the clear-to-mint attest fires) and
    // then fails as a concurrency victim, rolling back — nothing persists. The second delegates to
    // the parent and commits. No in-process fake can raise a real serialization failure, so this
    // reproduces the retry deterministically, exactly as TransactionRetryTest does.
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

    $store = new DatabaseApprovalReceiptStore($connection);

    $now = new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));
    $receipt = new ApprovalReceipt(
        id: 'retry-id-'.str_repeat('z', 54),
        toolCallId: 'call-retry-1',
        capability: 'orders.cancel',
        bindingFingerprint: str_repeat('a', 64),
        provenance: null,
        approvalContext: ['tenant_id' => 'store-1'],
        status: ApprovalReceiptStatus::Pending,
        reason: 'Confirm this cancellation.',
        expiresAt: $now->modify('+15 minutes'),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: $now,
        updatedAt: $now,
    );

    $attest = new IdempotentRecordingAttester;
    $summary = new ApproverSummary('Cancel order #9001', hash('sha256', 'Cancel order #9001'));

    // The exact hook ApprovalManager::issue() installs: attest the fixed sha256($id), wrapping a
    // failure so a genuine DB error is not misclassified as an attest failure.
    $onAdmitted = function () use ($attest, $receipt, $summary): void {
        try {
            $attest->attestIssuedSummary(ApprovalLane::Confirmation, hash('sha256', $receipt->id), $summary);
        } catch (Throwable $e) {
            throw AttestedIssuanceAppendFailed::from($e);
        }
    };

    $transition = $store->issueAdmitted($receipt, $onAdmitted);

    $persisted = $connection->table('verdict_approval_receipts')->where('id', $receipt->id)->count();

    expect($transition->outcome)->toBe(ApprovalOutcome::Issued)
        ->and($connection->attempts)->toBe(2)              // a genuine retry occurred
        ->and($attest->rawInvocations)->toBe(2)            // the hook re-ran on the retry
        ->and($attest->calls)->toHaveCount(1)              // but attested EXACTLY ONCE — idempotent
        ->and($attest->calls[0])->toBe(hash('sha256', $receipt->id)) // on the stable receipt id
        ->and($persisted)->toBe(1)                         // exactly one receipt persisted
        ->and($store->find($receipt->id)?->status)->toBe(ApprovalReceiptStatus::Pending);
});
