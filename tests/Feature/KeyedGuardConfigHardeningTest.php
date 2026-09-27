<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ConsumedBindingGuard;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Exceptions\ConsumedBindingGuardSchemeDowngraded;
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

it('fails closed at issue() after a keyed -> keyless downgrade leaves an orphaned keyed guard', function (): void {
    // Consume under keyed v1 (writes a keyed guard), then remove the config (keyed -> keyless). A
    // fresh, unrelated binding — which would otherwise mint — must now be refused, because the store
    // can no longer re-derive the orphaned keyed guard and cannot guarantee replay defence.
    config()->set('verdict.approvals.consumed_binding_guard.active_key', 'v1');
    config()->set('verdict.approvals.consumed_binding_guard.keys', ['v1' => KH_SECRET]);
    app()->forgetInstance(ApprovalReceiptStore::class);
    khConsume(app(ApprovalReceiptStore::class));
    expect(khGuardDigests())->toBe([ConsumedBindingGuard::keyed(KH_TC, KH_CAP, KH_FP, KH_SECRET)]);

    config()->set('verdict.approvals.consumed_binding_guard', null); // the downgrade
    app()->forgetInstance(ApprovalReceiptStore::class);

    $fresh = new ApprovalReceipt(
        id: 'receipt-fresh', toolCallId: 'call-fresh', capability: KH_CAP, bindingFingerprint: hash('sha256', 'fresh'),
        provenance: null, approvalContext: null, status: ApprovalReceiptStatus::Pending, reason: 'Confirm.',
        expiresAt: new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('UTC')),
        approvedBy: null, approvedAt: null, rejectedBy: null, rejectedAt: null, consumedAt: null,
        createdAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
        updatedAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
    );

    expect(fn () => app(ApprovalReceiptStore::class)->issue($fresh))
        ->toThrow(ConsumedBindingGuardSchemeDowngraded::class);
});

it('verdict:validate reports an orphaned keyed guard under a keyless config as an error at deploy time', function (): void {
    // A keyed guard row is present, but the resolved scheme is keyless — the downgrade that silently
    // reopens the replay window. verdict:validate must catch it before it reaches production.
    config()->set('verdict.approvals.consumed_binding_guard.active_key', 'v1');
    config()->set('verdict.approvals.consumed_binding_guard.keys', ['v1' => KH_SECRET]);
    app()->forgetInstance(ApprovalReceiptStore::class);
    khConsume(app(ApprovalReceiptStore::class));

    config()->set('verdict.approvals.consumed_binding_guard', null); // the downgrade
    app()->forgetInstance(ApprovalReceiptStore::class);

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('consumed_binding_guard')
        ->assertExitCode(1);
});

it('verdict:validate passes a keyless config when no keyed guard has ever been written', function (): void {
    // The legitimate keyless deployment: keyless guards may exist, but none carries a scheme, so there
    // is nothing to orphan and validate must not false-positive.
    config()->set('verdict.approvals.consumed_binding_guard', null);
    app()->forgetInstance(ApprovalReceiptStore::class);
    khConsume(app(ApprovalReceiptStore::class)); // writes a keyless guard
    expect(khGuardDigests())->toBe([ConsumedBindingGuard::digest(KH_TC, KH_CAP, KH_FP)]);

    $this->artisan('verdict:validate')->assertExitCode(0);
});

it('fails closed at consume() after a downgrade, for an approved receipt that predates the config change', function (): void {
    // The consume-path downgrade is cross-instance: under keyed v1, consume binding A (writes a keyed
    // guard) AND leave binding B approved-but-not-consumed. Both persist in the DB. After the config is
    // removed, a fresh keyless-scheme store must refuse to consume B — the orphaned keyed guard (A's)
    // means it can no longer guarantee replay defence.
    config()->set('verdict.approvals.consumed_binding_guard.active_key', 'v1');
    config()->set('verdict.approvals.consumed_binding_guard.keys', ['v1' => KH_SECRET]);
    app()->forgetInstance(ApprovalReceiptStore::class);
    $keyed = app(ApprovalReceiptStore::class);
    khConsume($keyed); // binding A (KH_TC / KH_FP) consumed -> keyed guard row for A

    $bFingerprint = hash('sha256', 'binding-b');
    $b = new ApprovalReceipt(
        id: 'receipt-b', toolCallId: 'call-b', capability: KH_CAP, bindingFingerprint: $bFingerprint,
        provenance: null, approvalContext: null, status: ApprovalReceiptStatus::Pending, reason: 'Confirm.',
        expiresAt: new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('UTC')),
        approvedBy: null, approvedAt: null, rejectedBy: null, rejectedAt: null, consumedAt: null,
        createdAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
        updatedAt: new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
    );
    expect($keyed->issue($b)->outcome)->toBe(ApprovalOutcome::Issued);
    expect($keyed->approve('receipt-b', 'call-b', 'human', new DateTimeImmutable('2026-09-01 12:00:30', new DateTimeZone('UTC')))->outcome)
        ->toBe(ApprovalOutcome::Approved);

    config()->set('verdict.approvals.consumed_binding_guard', null); // the downgrade
    app()->forgetInstance(ApprovalReceiptStore::class);

    expect(fn () => app(ApprovalReceiptStore::class)->consume('call-b', $bFingerprint, new DateTimeImmutable('2026-09-01 12:02:00', new DateTimeZone('UTC'))))
        ->toThrow(ConsumedBindingGuardSchemeDowngraded::class);
});
