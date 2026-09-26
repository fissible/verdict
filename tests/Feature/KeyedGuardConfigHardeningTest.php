<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ConsumedBindingGuard;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Exceptions\InvalidConsumedBindingGuardConfig;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

// Hardening of the keyed-digest opt-in (ADR 0039), end to end. The service provider builds the guard
// scheme through ConsumedBindingGuardScheme::fromConfig(), which fails closed rather than degrading to
// keyless — so a scalar active_key is honoured (not silently keyless), a misconfiguration refuses at
// store resolution, and verdict:validate surfaces it at deploy time. Self-contained: Pest.php globals.

const KH_TC = 'call-1';
const KH_CAP = 'orders.cancel';
const KH_FP = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const KH_GUARD_TABLE = 'verdict_consumed_binding_guards';
const KH_SECRET = 'a-container-secret-of-at-least-thirty-two-chars'; // >= 32

function khConnection(): ConnectionInterface
{
    return app(DatabaseManager::class)->connection();
}

/** Raw digest bytes of every guard row (Postgres bytea comes back as a resource). @return list<string> */
function khGuardDigests(): array
{
    return khConnection()->table(KH_GUARD_TABLE)->get()
        ->map(fn (object $r): string => is_resource($r->digest) ? (string) stream_get_contents($r->digest) : (string) $r->digest)
        ->all();
}

function khConsume(ApprovalReceiptStore $store): void
{
    $receipt = new ApprovalReceipt(
        id: 'receipt-a', toolCallId: KH_TC, capability: KH_CAP, bindingFingerprint: KH_FP,
        provenance: null, approvalContext: null, status: ApprovalReceiptStatus::Pending, reason: 'Confirm.',
        expiresAt: new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('UTC')),
        approvedBy: null, approvedAt: null, rejectedBy: null, rejectedAt: null, consumedAt: null,
        createdAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
        updatedAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
    );
    $at = new DateTimeImmutable('2026-09-01 12:01:00', new DateTimeZone('UTC'));
    expect($store->issue($receipt)->outcome)->toBe(ApprovalOutcome::Issued);
    expect($store->approve($receipt->id, KH_TC, 'human', new DateTimeImmutable('2026-09-01 12:00:30', new DateTimeZone('UTC')))->outcome)->toBe(ApprovalOutcome::Approved);
    expect($store->consume(KH_TC, KH_FP, $at)->outcome)->toBe(ApprovalOutcome::Consumed);
}

beforeEach(function (): void {
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    config()->set('verdict.approvals.consumed_binding_guard', null);
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    foreach ([verdictTable('approvals'), KH_GUARD_TABLE, 'verdict_binding_admission_locks'] as $t) {
        $schema->dropIfExists($t);
    }
    foreach ([
        'create_verdict_approval_receipts_table.php.stub',
        'add_proposal_provenance_to_verdict_approval_receipts_table.php.stub',
        'add_approval_context_to_verdict_approval_receipts_table.php.stub',
        'create_verdict_consumed_binding_guards_table.php.stub',
        'add_scheme_to_verdict_consumed_binding_guards_table.php.stub',
        'create_verdict_binding_admission_locks_table.php.stub',
    ] as $stub) {
        (require __DIR__.'/../../database/migrations/'.$stub)->up();
    }
});

afterEach(function (): void {
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    foreach ([verdictTable('approvals'), KH_GUARD_TABLE, 'verdict_binding_admission_locks'] as $t) {
        $schema->dropIfExists($t);
    }
});

it('honours an integer active_key from config: the container store writes a KEYED guard, not a silent keyless one', function (): void {
    config()->set('verdict.approvals.consumed_binding_guard.active_key', 1);
    config()->set('verdict.approvals.consumed_binding_guard.keys', [1 => KH_SECRET]);
    app()->forgetInstance(ApprovalReceiptStore::class);

    khConsume(app(ApprovalReceiptStore::class));

    // The regression: before the fix this config resolved to keyless and wrote the unsalted digest.
    expect(khGuardDigests())->toBe([ConsumedBindingGuard::keyed(KH_TC, KH_CAP, KH_FP, KH_SECRET)])
        ->and(khGuardDigests())->not->toContain(ConsumedBindingGuard::digest(KH_TC, KH_CAP, KH_FP));

    // The full scheme is persisted, and the integer version was coerced to '1' end to end.
    $row = khConnection()->table(KH_GUARD_TABLE)->first();
    expect($row->algorithm)->toBe('hmac-sha256')
        ->and($row->key_version)->toBe('1');
});

it('fails closed at store resolution when the keyed-guard config is invalid', function (): void {
    config()->set('verdict.approvals.consumed_binding_guard.active_key', 'v9'); // not in keys
    config()->set('verdict.approvals.consumed_binding_guard.keys', ['v1' => KH_SECRET]);
    app()->forgetInstance(ApprovalReceiptStore::class);

    expect(fn () => app(ApprovalReceiptStore::class))->toThrow(InvalidConsumedBindingGuardConfig::class);
});

it('verdict:validate reports an invalid keyed-guard config as an error at deploy time', function (): void {
    config()->set('verdict.approvals.consumed_binding_guard.active_key', 'v9');
    config()->set('verdict.approvals.consumed_binding_guard.keys', ['v1' => KH_SECRET]);

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('consumed_binding_guard')
        ->assertExitCode(1);
});

it('verdict:validate passes a keyless (unset) keyed-guard config', function (): void {
    config()->set('verdict.approvals.consumed_binding_guard', null);

    $this->artisan('verdict:validate')->assertExitCode(0);
});

it('rolls back the scheme migration idempotently when the columns are already absent', function (): void {
    // up() guards each column with hasColumn; down() must be symmetric, so a rollback after a partial
    // schema change (columns not present) is a no-op rather than a "no such column" error.
    $migration = require __DIR__.'/../../database/migrations/add_scheme_to_verdict_consumed_binding_guards_table.php.stub';
    $schema = khConnection()->getSchemaBuilder();

    $migration->down(); // columns were added in beforeEach; drop them
    expect($schema->hasColumn(KH_GUARD_TABLE, 'algorithm'))->toBeFalse();

    $migration->down(); // second rollback with the columns already gone must not throw

    expect($schema->hasTable(KH_GUARD_TABLE))->toBeTrue()
        ->and($schema->hasColumn(KH_GUARD_TABLE, 'algorithm'))->toBeFalse()
        ->and($schema->hasColumn(KH_GUARD_TABLE, 'key_version'))->toBeFalse();
});
