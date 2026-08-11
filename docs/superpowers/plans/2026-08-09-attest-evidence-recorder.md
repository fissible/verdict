# AttestEvidenceRecorder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `AttestEvidenceRecorder`, an `EvidenceRecorder` implementation backed by `fissible/attest` (via `fissible/attest-laravel`), so Verdict decisions and context releases can be written to a signed, hash-chained evidence log instead of (or alongside) the ordinary mutable `verdict_evidence` table. Closes fissible/verdict#11.

**Architecture:** `AttestEvidenceRecorder` implements the existing `Fissible\Verdict\Contracts\EvidenceRecorder` contract. It writes `DecisionEvidence` and `ContextReleaseEvidence` to a per-tenant `attest` chain (retrying transient failures, then recording a "chain gap" marker and raising an event on exhaustion). Provenance entries and derivations always delegate to a `fallback` `EvidenceRecorder` (`DatabaseEvidenceRecorder` in production) for reads and, unless `chain_provenance` is enabled, for writes too. `fissible/attest-laravel` is a `require-dev` + `suggest` dependency, not a hard `require` — this keeps it opt-in, matching how `DatabaseEvidenceRecorder`, `InMemoryEvidenceRecorder`, and `NullEvidenceRecorder` are already alternative, config-selected implementations of the same contract.

**Tech Stack:** PHP 8.3, Laravel 12/13, Pest 4, `fissible/attest` ^1.2 / `fissible/attest-laravel` ^1.0 (both published on Packagist), Orchestra Testbench.

## Global Constraints

- PHP `^8.3`, `declare(strict_types=1)` in every new file, matching every existing file in `src/`.
- 100% type coverage is enforced by `composer test` (`pest --type-coverage --min=100`). Every new property, parameter, and return type must be declared.
- `fissible/attest-laravel` goes in `require-dev` + `suggest`, **not** `require`. Verdict's default install must stay lean; only applications that configure `AttestEvidenceRecorder` need the dependency themselves.
- No new ADR. Per this repo's convention (see `docs/research-log.md` and prior issues), ADRs are reserved for rejections, invariants, and ownership boundaries — the design decisions for this feature already live, settled, in issue #11 itself.
- Do not touch `docs/adr/0007-evidence-layering.md` or `0008-evidence-privacy-model.md` — this feature is additive within the model those ADRs already established (fingerprint-first, evidence is not an authorization gate).
- Follow existing style exactly: `final` (or `final readonly`) classes, constructor property promotion, named arguments at call sites, no doc-block noise beyond `@return`/`@param` for array shapes.
- ADR 0007 invariant: evidence recording must never become an authorization gate. A chain-write failure must never block or fail the protected action itself — this is why the default `on_failure` mode is `alert`, not `throw`.

---

### Task 1: Wire `fissible/attest-laravel` into composer and the test harness

**Files:**
- Modify: `composer.json`
- Create: `tests/AttestTestCase.php`
- Modify: `tests/Pest.php`
- Test: `tests/Integration/AttestPackageWiringTest.php`

**Interfaces:**
- Produces: `Fissible\Verdict\Tests\AttestTestCase` (extends `Fissible\Verdict\Tests\TestCase`), used by every later integration test that needs `Fissible\AttestLaravel\AttestServiceProvider` registered.
- Consumes: nothing from earlier tasks (this is the leaf).

- [ ] **Step 1: Add the dependency**

Edit `composer.json`. Add to `require-dev` (alongside the existing entries):

```json
    "fissible/attest-laravel": "^1.0",
```

Add a new top-level `suggest` section right after `require-dev` closes:

```json
  "suggest": {
    "fissible/attest-laravel": "Enables AttestEvidenceRecorder: a tamper-evident (signed, hash-chained) evidence recorder. See docs/limitations.md."
  },
```

- [ ] **Step 2: Install and confirm autoloading**

Run: `composer update fissible/attest-laravel --with-all-dependencies`
Expected: lock file updates, no conflicts (`fissible/attest-laravel` requires `php ^8.2`, `illuminate/* ^12.0||^13.0` — both satisfied by Verdict's own floor).

- [ ] **Step 3: Write the failing wiring test**

Create `tests/AttestTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests;

use Fissible\AttestLaravel\AttestServiceProvider;
use Illuminate\Foundation\Application;

abstract class AttestTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            AttestServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        putenv('ATTEST_SIGNING_KEY_SEED='.base64_encode(random_bytes(32)));
        putenv('ATTEST_SIGNING_KEY_ID=verdict-test');
    }

    protected function tearDown(): void
    {
        putenv('ATTEST_SIGNING_KEY_SEED');
        putenv('ATTEST_SIGNING_KEY_ID');

        parent::tearDown();
    }
}
```

Add the new test group to `tests/Pest.php` (insert after the existing `uses(...)` lines):

```php
use Fissible\Verdict\Tests\AttestTestCase;

uses(AttestTestCase::class)->in('Integration');
```

Create `tests/Integration/AttestPackageWiringTest.php`:

```php
<?php

declare(strict_types=1);

use Fissible\AttestLaravel\Support\AttestRegistry;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

it('resolves the attest registry once the package is installed', function (): void {
    expect(app(AttestRegistry::class))->toBeInstanceOf(AttestRegistry::class);
});
```

- [ ] **Step 4: Run it to verify it fails for the right reason first, then passes**

Run: `vendor/bin/pest tests/Integration/AttestPackageWiringTest.php`
Expected: PASS. (This step is a smoke test the dependency and provider are wired correctly, not TDD-red-then-green — there is no production code to write yet. If it fails, the error will be either "Class AttestServiceProvider not found" — composer step above did not complete — or a migration error — check `ATTEST_CONNECTION` is unset so it falls back to `database.default`, which Testbench sets to an in-memory sqlite connection by default.)

- [ ] **Step 5: Commit**

```bash
git add composer.json tests/AttestTestCase.php tests/Pest.php tests/Integration/AttestPackageWiringTest.php
git commit -m "test: wire fissible/attest-laravel into the test harness"
```

---

### Task 2: Add the `ChainWriteFailed` event and `EvidenceChainWriteFailed` exception

**Files:**
- Create: `src/Evidence/Events/ChainWriteFailed.php`
- Create: `src/Exceptions/EvidenceChainWriteFailed.php`
- Test: `tests/Unit/ChainWriteFailedTest.php`
- Test: `tests/Unit/EvidenceChainWriteFailedTest.php`

**Interfaces:**
- Produces: `Fissible\Verdict\Evidence\Events\ChainWriteFailed` (readonly, public properties `chainId: string`, `correlationId: ?string`, `recordType: string`, `attempts: int`, `message: string`) — dispatched by `AttestEvidenceRecorder` (Task 4) whenever a chained write exhausts its retries.
- Produces: `Fissible\Verdict\Exceptions\EvidenceChainWriteFailed::fromFailure(string $chainId, string $recordType, int $attempts, ?Throwable $previous): self` — thrown by `AttestEvidenceRecorder` (Task 4) only in `on_failure: 'throw'` mode.
- Consumes: nothing from earlier tasks.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/ChainWriteFailedTest.php`:

```php
<?php

declare(strict_types=1);

use Fissible\Verdict\Evidence\Events\ChainWriteFailed;

it('carries the chain write failure facts', function (): void {
    $event = new ChainWriteFailed(
        chainId: 'verdict',
        correlationId: 'env-1',
        recordType: 'decision',
        attempts: 3,
        message: 'Could not acquire append lock for chain: verdict',
    );

    expect($event->chainId)->toBe('verdict')
        ->and($event->correlationId)->toBe('env-1')
        ->and($event->recordType)->toBe('decision')
        ->and($event->attempts)->toBe(3)
        ->and($event->message)->toBe('Could not acquire append lock for chain: verdict');
});

it('allows a null correlation id', function (): void {
    $event = new ChainWriteFailed(
        chainId: 'verdict',
        correlationId: null,
        recordType: 'context_release',
        attempts: 1,
        message: 'boom',
    );

    expect($event->correlationId)->toBeNull();
});
```

Create `tests/Unit/EvidenceChainWriteFailedTest.php`:

```php
<?php

declare(strict_types=1);

use Fissible\Verdict\Exceptions\EvidenceChainWriteFailed;

it('builds a message naming the chain, record type, and attempt count', function (): void {
    $previous = new RuntimeException('Could not acquire append lock for chain: verdict');

    $exception = EvidenceChainWriteFailed::fromFailure('verdict', 'decision', 3, $previous);

    expect($exception->getMessage())
        ->toBe('Failed to write [decision] evidence to attest chain [verdict] after 3 attempt(s).')
        ->and($exception->getPrevious())->toBe($previous);
});

it('allows a null previous exception', function (): void {
    $exception = EvidenceChainWriteFailed::fromFailure('verdict', 'decision', 1, null);

    expect($exception->getPrevious())->toBeNull();
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/pest tests/Unit/ChainWriteFailedTest.php tests/Unit/EvidenceChainWriteFailedTest.php`
Expected: FAIL — "Class ... not found".

- [ ] **Step 3: Write the implementation**

Create `src/Evidence/Events/ChainWriteFailed.php`:

```php
<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence\Events;

final readonly class ChainWriteFailed
{
    public function __construct(
        public string $chainId,
        public ?string $correlationId,
        public string $recordType,
        public int $attempts,
        public string $message,
    ) {}
}
```

Create `src/Exceptions/EvidenceChainWriteFailed.php`:

```php
<?php

declare(strict_types=1);

namespace Fissible\Verdict\Exceptions;

use RuntimeException;
use Throwable;

final class EvidenceChainWriteFailed extends RuntimeException
{
    private function __construct(string $message, ?Throwable $previous)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function fromFailure(string $chainId, string $recordType, int $attempts, ?Throwable $previous): self
    {
        return new self(
            "Failed to write [{$recordType}] evidence to attest chain [{$chainId}] after {$attempts} attempt(s).",
            $previous,
        );
    }
}
```

- [ ] **Step 4: Run to verify they pass**

Run: `vendor/bin/pest tests/Unit/ChainWriteFailedTest.php tests/Unit/EvidenceChainWriteFailedTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Evidence/Events/ChainWriteFailed.php src/Exceptions/EvidenceChainWriteFailed.php tests/Unit/ChainWriteFailedTest.php tests/Unit/EvidenceChainWriteFailedTest.php
git commit -m "feat: add ChainWriteFailed event and EvidenceChainWriteFailed exception"
```

---

### Task 3: Build a real (non-mocked) attest fixture for tests — `tests/Support/FlakyChainStore.php`

**Files:**
- Create: `tests/Support/FlakyChainStore.php`
- Create: `tests/Support/AttestFixture.php`

**Interfaces:**
- Produces: `Fissible\Verdict\Tests\Support\FlakyChainStore implements Fissible\Attest\Chain\ChainStore` — wraps a real `FileChainStore`, throwing `ChainLockUnavailable` on the first `$failures` calls to `append()`, then delegating for real. Constructor: `(ChainStore $inner, int $failures)`.
- Produces: `Fissible\Verdict\Tests\Support\AttestFixture::registry(?ChainStore $store = null): AttestRegistry` — builds a real `AttestRegistry` (real `Signer`, real `FileAnchorClaimStore`) over a temp-directory `FileChainStore` by default, or over a caller-supplied store (e.g. a `FlakyChainStore`). Also exposes `AttestFixture::store(): FileChainStore` for a plain fixture. Uses `sys_get_temp_dir()` and cleans up nothing itself — tests are responsible for using a fresh subdirectory per test via `uniqid()`.
- Consumes: `Fissible\Attest\Chain\ChainStore`, `Fissible\Attest\Chain\FileChainStore`, `Fissible\Attest\Chain\ChainLockUnavailable`, `Fissible\Attest\Anchor\FileAnchorClaimStore`, `Fissible\Attest\Signing\SodiumSigner`, `Fissible\Attest\Signing\KeyPair` — all from `fissible/attest` (transitive via `fissible/attest-laravel`, wired in Task 1).

This task has no independent "TDD red/green" cycle of its own — it is test infrastructure. Write it directly, then prove it works via the tests in Task 4, which are the actual failing-first tests for this task's payoff.

- [ ] **Step 1: Write `FlakyChainStore`**

Create `tests/Support/FlakyChainStore.php`:

```php
<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use Fissible\Attest\Chain\ChainLockUnavailable;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Envelope\SignedEnvelope;

final class ChainCallCounter
{
    public int $appends = 0;
}

final readonly class FlakyChainStore implements ChainStore
{
    public function __construct(
        private ChainStore $inner,
        private int $failures,
        private ChainCallCounter $counter = new ChainCallCounter,
    ) {}

    public function counter(): ChainCallCounter
    {
        return $this->counter;
    }

    public function append(string $chainId, callable $buildAndSign): SignedEnvelope
    {
        $this->counter->appends++;

        if ($this->counter->appends <= $this->failures) {
            throw new ChainLockUnavailable($chainId);
        }

        return $this->inner->append($chainId, $buildAndSign);
    }

    public function tail(string $chainId): ?SignedEnvelope
    {
        return $this->inner->tail($chainId);
    }

    public function readRange(string $chainId, int $fromSeq, ?int $toSeq = null): iterable
    {
        return $this->inner->readRange($chainId, $fromSeq, $toSeq);
    }

    public function listChains(): iterable
    {
        return $this->inner->listChains();
    }

    public function exists(string $chainId): bool
    {
        return $this->inner->exists($chainId);
    }
}
```

- [ ] **Step 2: Write `AttestFixture`**

Create `tests/Support/AttestFixture.php`:

```php
<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use Fissible\Attest\Anchor\FileAnchorClaimStore;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\FileChainStore;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\AttestLaravel\Support\AttestRegistry;

final class AttestFixture
{
    public static function store(): FileChainStore
    {
        return new FileChainStore(sys_get_temp_dir().'/verdict-attest-test-'.uniqid('', true));
    }

    public static function registry(?ChainStore $store = null): AttestRegistry
    {
        return new AttestRegistry(
            store: $store ?? self::store(),
            claimStore: new FileAnchorClaimStore(sys_get_temp_dir().'/verdict-attest-claims-'.uniqid('', true)),
            signer: new SodiumSigner(KeyPair::generate(), 'verdict-test'),
        );
    }
}
```

- [ ] **Step 3: Confirm it composes cleanly**

Run: `vendor/bin/phpstan analyse tests/Support/FlakyChainStore.php tests/Support/AttestFixture.php --memory-limit=1G`
Expected: no errors. (There is no runtime test for this task alone — Task 4 exercises it.)

- [ ] **Step 4: Commit**

```bash
git add tests/Support/FlakyChainStore.php tests/Support/AttestFixture.php
git commit -m "test: add FlakyChainStore and AttestFixture test doubles for attest integration tests"
```

---

### Task 4: `AttestEvidenceRecorder` — decisions and context releases (chained, with retry/backoff/gap-marker)

**Files:**
- Create: `src/Evidence/AttestEvidenceRecorder.php`
- Test: `tests/Feature/AttestEvidenceRecorderTest.php`

**Interfaces:**
- Consumes: `Fissible\Verdict\Contracts\EvidenceRecorder` (the interface being implemented); `Fissible\Verdict\Evidence\Events\ChainWriteFailed` and `Fissible\Verdict\Exceptions\EvidenceChainWriteFailed` (Task 2); `Fissible\Verdict\Tests\Support\AttestFixture` / `FlakyChainStore` (Task 3, tests only); `Fissible\AttestLaravel\Support\AttestRegistry::chain(string): EvidenceChain`; `EvidenceChain::record(string $type, array $payload, ?string $subject = null, ?string $correlation = null, ?string $tenant = null): SignedEnvelope`.
- Produces: `Fissible\Verdict\Evidence\AttestEvidenceRecorder` with constructor:
  ```php
  public function __construct(
      private readonly AttestRegistry $attest,
      private readonly EvidenceRecorder $fallback,
      private readonly ConnectionInterface $connection,
      private readonly Dispatcher $events,
      private readonly Closure $chainIdUsing,
      private readonly string $table = 'verdict_evidence',
      private readonly bool $chainProvenance = false,
      private readonly string $onFailure = 'alert',
      private readonly int $maxAttempts = 3,
      private readonly int $baseDelayMs = 50,
  )
  ```
  This task implements `record()` and `recordRelease()` plus the shared `writeChained()`/`recordGap()` private machinery. Task 5 adds `recordProvenance()`, `recordDerivation()`, `provenanceFor()`, `derivationsFor()` to the same class.

This is the core of the feature. Follow strict red-green-commit per behavior.

- [ ] **Step 1: Write the failing happy-path test**

Create `tests/Feature/AttestEvidenceRecorderTest.php`:

```php
<?php

declare(strict_types=1);

use Fissible\Attest\Chain\FileChainStore;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\Events\ChainWriteFailed;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Exceptions\EvidenceChainWriteFailed;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Trust;
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

function makeRecorder(\Fissible\Attest\Chain\ChainStore $store, string $onFailure = 'alert'): AttestEvidenceRecorder
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
        source: \Fissible\Verdict\Context\Source::application('order-lookup'),
        destination: \Fissible\Verdict\Context\Destination::connection('gpt', 'model'),
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
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest tests/Feature/AttestEvidenceRecorderTest.php`
Expected: FAIL — "Class AttestEvidenceRecorder not found".

- [ ] **Step 3: Write the minimal implementation — happy path only**

Create `src/Evidence/AttestEvidenceRecorder.php`:

```php
<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use Closure;
use DateTimeImmutable;
use Fissible\AttestLaravel\Support\AttestRegistry;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\Events\ChainWriteFailed;
use Fissible\Verdict\Exceptions\EvidenceChainWriteFailed;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class AttestEvidenceRecorder implements EvidenceRecorder
{
    public function __construct(
        private readonly AttestRegistry $attest,
        private readonly EvidenceRecorder $fallback,
        private readonly ConnectionInterface $connection,
        private readonly Dispatcher $events,
        private readonly Closure $chainIdUsing,
        private readonly string $table = 'verdict_evidence',
        private readonly bool $chainProvenance = false,
        private readonly string $onFailure = 'alert',
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 50,
    ) {
        if (! in_array($this->onFailure, ['alert', 'throw'], true)) {
            throw new InvalidArgumentException("Unknown on_failure mode [{$this->onFailure}]. Expected 'alert' or 'throw'.");
        }
    }

    public function record(DecisionEvidence $evidence): void
    {
        $this->writeChained(
            correlationId: $evidence->envelopeId,
            recordType: 'decision',
            type: 'verdict.decision',
            payload: [
                'capability' => $evidence->capability,
                'stage' => $evidence->stage,
                'disposition' => $evidence->disposition,
                'reason' => $evidence->reason,
                'argument_fingerprint' => $evidence->argumentFingerprint,
                'idempotency_key_fingerprint' => $evidence->idempotencyKey === null ? null : hash('sha256', $evidence->idempotencyKey),
                'approval_receipt_fingerprint' => $evidence->approvalReceiptFingerprint,
                'approval_phase' => $evidence->approvalPhase,
                'approval_outcome' => $evidence->approvalOutcome,
                'target_policy' => $evidence->targetPolicy,
                'target_strategy' => $evidence->targetStrategy,
                'proposal_target_identity_fingerprint' => $evidence->proposalTargetIdentityFingerprint,
                'execution_target_identity_fingerprint' => $evidence->executionTargetIdentityFingerprint,
                'target_identity_matched' => $evidence->targetIdentityMatched,
                'rate_limit_key_fingerprint' => $evidence->rateLimitKeyFingerprint,
                'rate_limit_policy' => $evidence->rateLimitPolicy,
                'rate_limit_limit' => $evidence->rateLimitLimit,
                'rate_limit_remaining' => $evidence->rateLimitRemaining,
                'rate_limit_reset_at' => $evidence->rateLimitResetAt?->format(DATE_ATOM),
                'execution_claim_fingerprint' => $evidence->executionClaimFingerprint,
                'execution_claim_binding_fingerprint' => $evidence->executionClaimBindingFingerprint,
                'execution_claim_policy' => $evidence->executionClaimPolicy,
                'execution_claim_status' => $evidence->executionClaimStatus,
                'execution_claim_attempt' => $evidence->executionClaimAttempt,
                'invocation_id' => $evidence->invocationId,
                'recorded_at' => $evidence->recordedAt->format(DATE_ATOM),
            ],
        );
    }

    public function recordRelease(ContextReleaseEvidence $evidence): void
    {
        $this->writeChained(
            correlationId: $evidence->invocationId,
            recordType: 'context_release',
            type: 'verdict.context_release',
            payload: [
                'source' => $evidence->source,
                'destination' => $evidence->destination,
                'trust_zone' => $evidence->trustZone,
                'trust' => $evidence->trust->value,
                'data_class' => $evidence->dataClass->value,
                'disposition' => $evidence->disposition,
                'reason' => $evidence->reason,
                'requested_path_fingerprints' => $evidence->requestedPathFingerprints,
                'released_path_fingerprints' => $evidence->releasedPathFingerprints,
                'transform_fingerprints' => $evidence->transformFingerprints,
                'transformed_path_fingerprints' => $evidence->transformedPathFingerprints,
                'payload_fingerprint' => $evidence->payloadFingerprint,
                'invocation_id' => $evidence->invocationId,
                'recorded_at' => $evidence->recordedAt->format(DATE_ATOM),
            ],
        );
    }

    /** @param array<string, mixed> $payload */
    private function writeChained(?string $correlationId, string $recordType, string $type, array $payload): void
    {
        $chainId = ($this->chainIdUsing)();
        $attempt = 0;
        $lastError = null;

        while ($attempt < $this->maxAttempts) {
            $attempt++;

            try {
                $this->attest->chain($chainId)->record(
                    type: $type,
                    payload: $payload,
                    correlation: $correlationId,
                );

                return;
            } catch (Throwable $e) {
                $lastError = $e;

                if ($attempt < $this->maxAttempts) {
                    usleep($this->baseDelayMs * 1000 * (2 ** ($attempt - 1)));
                }
            }
        }

        $this->recordGap($chainId, $correlationId, $recordType, $attempt, $lastError);

        $this->events->dispatch(new ChainWriteFailed(
            chainId: $chainId,
            correlationId: $correlationId,
            recordType: $recordType,
            attempts: $attempt,
            message: $lastError?->getMessage() ?? 'unknown error',
        ));

        if ($this->onFailure === 'throw') {
            throw EvidenceChainWriteFailed::fromFailure($chainId, $recordType, $attempt, $lastError);
        }
    }

    private function recordGap(string $chainId, ?string $correlationId, string $recordType, int $attempts, ?Throwable $error): void
    {
        $this->connection->table($this->table)->insert([
            'id' => Str::uuid()->toString(),
            'record_type' => 'chain_gap',
            'correlation_id' => $correlationId,
            'stage' => $recordType,
            'disposition' => 'gap',
            'reason' => json_encode([
                'chain' => $chainId,
                'attempts' => $attempts,
                'error' => $error?->getMessage(),
            ], JSON_THROW_ON_ERROR),
            'recorded_at' => new DateTimeImmutable,
        ]);
    }
}
```

Note: `recordProvenance()`, `recordDerivation()`, `provenanceFor()`, `derivationsFor()` are required by the `EvidenceRecorder` interface and are added in Task 5. Until Task 5 lands, this class does not fully implement the interface — leave it as `implements EvidenceRecorder` anyway; PHP will raise a fatal error if instantiated without those methods, which is fine because Task 4's tests only need `record()`/`recordRelease()` and this is corrected within the same work session, not shipped mid-state. If you are executing this plan across multiple sessions, add no-op stub bodies (`throw new \LogicException('not yet implemented')`) at the end of Step 3 instead, and remove the stubs in Task 5 Step 3.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/pest tests/Feature/AttestEvidenceRecorderTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Write the failing retry test**

Append to `tests/Feature/AttestEvidenceRecorderTest.php`:

```php
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

it('throws instead of swallowing when on_failure is throw', function (): void {
    $store = new FlakyChainStore(AttestFixture::store(), failures: 99);
    $recorder = makeRecorder($store, onFailure: 'throw');

    expect(fn () => $recorder->record($this->decision))
        ->toThrow(EvidenceChainWriteFailed::class, 'Failed to write [decision] evidence to attest chain [verdict] after 3 attempt(s).');

    // The gap marker is still written even in throw mode — decision 5 in issue #11
    // separates "record the gap" from "how to react," and both apply together.
    expect(chainGapRows())->toHaveCount(1);
});
```

- [ ] **Step 6: Run to verify these three fail, then confirm they pass**

Run: `vendor/bin/pest tests/Feature/AttestEvidenceRecorderTest.php`
Expected on first run before Step 3's code handles retries: these three pass immediately too, actually — Step 3's implementation already contains the full retry/backoff/gap/event/throw logic. There is no red state for Step 5 specifically; this step exists to prove that logic (which had no dedicated test yet) is correct. If any of the three fail, the bug is in `writeChained()`/`recordGap()` above — common mistakes: off-by-one on `$attempt` vs `$this->maxAttempts` (should retry exactly `maxAttempts` times total, not `maxAttempts + 1`), or dispatching the event before recording the gap row (order does not matter functionally but the test asserts both happened, not their order).
Expected: PASS (5 tests total in the file so far).

- [ ] **Step 7: Commit**

```bash
git add src/Evidence/AttestEvidenceRecorder.php tests/Feature/AttestEvidenceRecorderTest.php
git commit -m "feat: add AttestEvidenceRecorder for decisions and context releases"
```

---

### Task 5: `AttestEvidenceRecorder` — provenance, derivations, and reads

**Files:**
- Modify: `src/Evidence/AttestEvidenceRecorder.php`
- Modify: `tests/Feature/AttestEvidenceRecorderTest.php`

**Interfaces:**
- Consumes: `Fissible\Verdict\Evidence\ProvenanceEntry`, `Fissible\Verdict\Evidence\ProvenanceDerivation` (existing); `EvidenceRecorder::recordProvenance()`, `::recordDerivation()`, `::provenanceFor()`, `::derivationsFor()` (the four remaining contract methods).
- Produces: a fully contract-complete `AttestEvidenceRecorder`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/AttestEvidenceRecorderTest.php`:

```php
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Evidence\DerivationKind;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;

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
        source: \Fissible\Verdict\Context\Source::external('search-api'),
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
        source: \Fissible\Verdict\Context\Source::external('search-api'),
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
```

All enum case names above (`DataClass::Internal`, `Trust::Untrusted`, `Trust::Trusted`, `ContextChannel::ToolResult`, `DerivationKind::Retrieved`) have been checked against `src/Context/DataClass.php`, `src/Context/Trust.php`, `src/Context/ContextChannel.php`, and `src/Evidence/DerivationKind.php` and match exactly.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/AttestEvidenceRecorderTest.php`
Expected: FAIL — "Call to undefined method AttestEvidenceRecorder::recordProvenance()" (or the stub `LogicException` from Task 4 Step 3's note, if you took that branch).

- [ ] **Step 3: Implement the remaining four methods**

Add to `src/Evidence/AttestEvidenceRecorder.php`, inside the class, replacing any stub bodies from Task 4:

```php
    public function recordProvenance(ProvenanceEntry $entry): void
    {
        $this->fallback->recordProvenance($entry);

        if (! $this->chainProvenance) {
            return;
        }

        $this->writeChained(
            correlationId: $entry->correlationId,
            recordType: 'provenance',
            type: 'verdict.provenance',
            payload: [
                'source' => $entry->source->identity(),
                'trust' => $entry->trust->value,
                'data_class' => $entry->dataClass->value,
                'channel' => $entry->channel->value,
                'component_label' => $entry->componentLabel,
                'component_fingerprint' => $entry->componentFingerprint,
                'content_fingerprint' => $entry->contentFingerprint,
                'recorded_at' => $entry->recordedAt->format(DATE_ATOM),
            ],
        );
    }

    public function recordDerivation(ProvenanceDerivation $derivation): void
    {
        $this->fallback->recordDerivation($derivation);

        if (! $this->chainProvenance) {
            return;
        }

        $this->writeChained(
            correlationId: $derivation->correlationId,
            recordType: 'provenance_derivation',
            type: 'verdict.provenance_derivation',
            payload: [
                'child_content_fingerprint' => $derivation->childContentFingerprint,
                'parent_content_fingerprint' => $derivation->parentContentFingerprint,
                'kind' => $derivation->kind->value,
                'recorded_at' => $derivation->recordedAt->format(DATE_ATOM),
            ],
        );
    }

    /** @return list<ProvenanceEntry> */
    public function provenanceFor(string $correlationId): array
    {
        return $this->fallback->provenanceFor($correlationId);
    }

    /** @return list<ProvenanceDerivation> */
    public function derivationsFor(string $correlationId, string $childContentFingerprint): array
    {
        return $this->fallback->derivationsFor($correlationId, $childContentFingerprint);
    }
```

Add the two missing `use` imports at the top of the file:

```php
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;
```

(These are in the same namespace as the class itself, `Fissible\Verdict\Evidence`, so PHP does not strictly require the `use` — but every sibling file in this directory, e.g. `DatabaseEvidenceRecorder.php`, does not import same-namespace classes either. Skip adding these two `use` lines; reference `ProvenanceEntry` and `ProvenanceDerivation` unqualified, matching that convention.)

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/pest tests/Feature/AttestEvidenceRecorderTest.php`
Expected: PASS (7 tests total in the file).

- [ ] **Step 5: Run the full suite to check for regressions**

Run: `composer test:unit`
Expected: all existing tests plus the new ones pass (244 + new count, 0 failures).

- [ ] **Step 6: Commit**

```bash
git add src/Evidence/AttestEvidenceRecorder.php tests/Feature/AttestEvidenceRecorderTest.php
git commit -m "feat: chain provenance and derivations behind a config flag, delegate reads to fallback"
```

---

### Task 6: Config and `VerdictServiceProvider` wiring

**Files:**
- Modify: `config/verdict.php`
- Modify: `src/VerdictServiceProvider.php`
- Test: `tests/Integration/AttestEvidenceRecorderServiceProviderTest.php`

**Interfaces:**
- Consumes: `AttestEvidenceRecorder` (Task 4/5), `Fissible\AttestLaravel\Support\AttestRegistry` (resolved from the container once `fissible/attest-laravel` is installed in the consuming app — Task 1 proved this resolves in tests), the existing `EvidenceRecorder::class` singleton binding pattern in `VerdictServiceProvider` (already read in full during research; the `DatabaseEvidenceRecorder::class` branch at approximately line 106 is the template).
- Produces: `config('verdict.evidence.attest.*')` keys; a working `AttestEvidenceRecorder` when `verdict.evidence.recorder` is set to `AttestEvidenceRecorder::class`.

- [ ] **Step 1: Write the failing integration test**

Create `tests/Integration/AttestEvidenceRecorderServiceProviderTest.php`:

```php
<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);

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
});

it('resolves an AttestEvidenceRecorder from config and records a real decision', function (): void {
    $recorder = app(EvidenceRecorder::class);

    expect($recorder)->toBeInstanceOf(AttestEvidenceRecorder::class);

    $recorder->record(new DecisionEvidence(
        envelopeId: 'env-int-1',
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
        recordedAt: new DateTimeImmutable,
    ));

    $envelope = \Fissible\AttestLaravel\Models\AttestEnvelope::query()
        ->forCorrelation('env-int-1')
        ->first();

    expect($envelope)->not->toBeNull()
        ->and($envelope->type)->toBe('verdict.decision');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest tests/Integration/AttestEvidenceRecorderServiceProviderTest.php`
Expected: FAIL — the container throws because `config('verdict.evidence.recorder')` resolves to `AttestEvidenceRecorder::class`, which is not special-cased, so `$app->make($recorder)` is attempted and fails (its constructor needs `AttestRegistry`, an `EvidenceRecorder` fallback, a `Closure`, etc. — several of which the container cannot autowire, particularly the `Closure $chainIdUsing`).

- [ ] **Step 3: Add config keys**

Edit `config/verdict.php`. Replace the existing `'evidence' => [...]` block with:

```php
    'evidence' => [
        // InMemoryEvidenceRecorder is only for tests and local development. Its unbounded,
        // process-local state is unsafe for production, Octane, and queue workers.
        'recorder' => NullEvidenceRecorder::class,
        'connection' => null,
        'table' => 'verdict_evidence',

        // Only consulted when 'recorder' is AttestEvidenceRecorder::class. Requires
        // fissible/attest-laravel (composer require fissible/attest-laravel) — see
        // docs/limitations.md, "No tamper-evident evidence".
        'attest' => [
            // Fixed chain id used by this default binding. Every deployment writes every
            // decision and context release to this one chain. Multi-tenant applications
            // should instead bind their own EvidenceRecorder — e.g. in a service provider:
            //   $this->app->extend(EvidenceRecorder::class, fn ($default, $app) => new AttestEvidenceRecorder(
            //       ..., chainIdUsing: fn (): string => 'tenant:'.CurrentTenant::id(), ...
            //   ));
            // See docs/limitations.md for the truncation-exposure and key-custody caveats
            // that apply regardless of chain topology.
            'chain' => env('VERDICT_ATTEST_CHAIN', 'verdict'),

            // The non-chained fallback recorder's connection/table. Provenance entries and
            // derivations are always readable through this table; decisions and context
            // releases are not (they exist only in the attest chain, plus a "chain_gap"
            // marker row here if a chained write ever exhausts its retries).
            'fallback_connection' => null,
            'fallback_table' => 'verdict_evidence',

            // Off by default: provenance volume scales with retrieved context, which can be
            // orders of magnitude larger than decisions, and chaining it by default would
            // make throughput unrepresentative of what most deployments need.
            'chain_provenance' => false,

            // 'alert' (default) never blocks the protected action — ADR 0007 already
            // decided evidence is not an authorization gate. 'throw' is for deployments
            // whose compliance regime requires fail-closed on evidence-write failure.
            'on_failure' => 'alert',
            'max_attempts' => 3,
            'base_delay_ms' => 50,
        ],
    ],
```

Add the import at the top of `config/verdict.php`:

```php
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
```

- [ ] **Step 4: Special-case the binding in `VerdictServiceProvider`**

In `src/VerdictServiceProvider.php`, add the import:

```php
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
```

Then, inside the `EvidenceRecorder::class` singleton factory (the closure containing the existing `if ($recorder === DatabaseEvidenceRecorder::class) { ... }` branch), add a sibling branch immediately after that `if` block closes, before the fallback `$app->make($recorder)` line:

```php
            if ($recorder === AttestEvidenceRecorder::class) {
                $fallbackConnection = config('verdict.evidence.attest.fallback_connection');
                $fallbackTable = config('verdict.evidence.attest.fallback_table', 'verdict_evidence');
                $chain = config('verdict.evidence.attest.chain', 'verdict');
                $onFailure = config('verdict.evidence.attest.on_failure', 'alert');
                $chainProvenance = config('verdict.evidence.attest.chain_provenance', false);
                $maxAttempts = config('verdict.evidence.attest.max_attempts', 3);
                $baseDelayMs = config('verdict.evidence.attest.base_delay_ms', 50);
                $connection = $app->make(DatabaseManager::class)->connection(
                    is_string($fallbackConnection) ? $fallbackConnection : null,
                );

                return new AttestEvidenceRecorder(
                    attest: $app->make(\Fissible\AttestLaravel\Support\AttestRegistry::class),
                    fallback: new DatabaseEvidenceRecorder(
                        connection: $connection,
                        table: is_string($fallbackTable) ? $fallbackTable : 'verdict_evidence',
                    ),
                    connection: $connection,
                    events: $app->make(Dispatcher::class),
                    chainIdUsing: static fn (): string => is_string($chain) ? $chain : 'verdict',
                    table: is_string($fallbackTable) ? $fallbackTable : 'verdict_evidence',
                    chainProvenance: (bool) $chainProvenance,
                    onFailure: is_string($onFailure) ? $onFailure : 'alert',
                    maxAttempts: is_int($maxAttempts) ? $maxAttempts : 3,
                    baseDelayMs: is_int($baseDelayMs) ? $baseDelayMs : 50,
                );
            }

```

`Dispatcher` is already imported in `VerdictServiceProvider.php` at line 40 (`use Illuminate\Contracts\Events\Dispatcher;`) — no new import needed for it.

- [ ] **Step 5: Run to verify it passes**

Run: `vendor/bin/pest tests/Integration/AttestEvidenceRecorderServiceProviderTest.php`
Expected: PASS.

- [ ] **Step 6: Run the full suite**

Run: `composer test`
Expected: analyse, lint:check, test:types, and test:unit all pass. Pay particular attention to `test:types` (100% type coverage) — every new property/param/return in Task 4–6's code must be typed; the config file's new `env()` calls are fine (already the pattern used elsewhere in `config/verdict.php`... actually check: the existing file has no `env()` calls at all today. Using `env()` directly in `config/verdict.php` is fine functionally, but if `php artisan config:cache` is a documented requirement elsewhere in this repo's docs, note that `env()` outside `config/*.php` is what breaks caching, not inside it — this is inside, so it is safe).

- [ ] **Step 7: Commit**

```bash
git add config/verdict.php src/VerdictServiceProvider.php tests/Integration/AttestEvidenceRecorderServiceProviderTest.php
git commit -m "feat: wire AttestEvidenceRecorder into config and the service provider"
```

---

### Task 7: Documentation

**Files:**
- Modify: `docs/limitations.md`
- Modify: `README.md`

**Interfaces:** none (docs only).

- [ ] **Step 1: Rewrite the "No tamper-evident evidence" section**

In `docs/limitations.md`, replace:

```markdown
### No tamper-evident evidence

The database evidence adapter is an ordinary mutable audit store. It is not append-only, immutable, signed, or tamper-evident, and it must not be described as cryptographic proof. A row recording a decision, approval, or provenance fact can be edited or deleted without detection. A tamper-evident adapter may be offered separately in the future; see [ADR 0007](adr/0007-evidence-layering.md).
```

with:

```markdown
### Tamper-evident evidence is opt-in, partial, and bounded by key custody

`DatabaseEvidenceRecorder` (the default when `verdict.evidence.recorder` is configured at all) is an ordinary mutable audit store: not append-only, immutable, signed, or tamper-evident. A row can be edited or deleted without detection. It must not be described as cryptographic proof.

`AttestEvidenceRecorder` (requires `composer require fissible/attest-laravel`) writes signed, hash-chained evidence via [`fissible/attest`](https://github.com/fissible/attest) instead. Even with it configured, several things remain true:

- **Only decisions and context releases are chained by default.** Provenance entries and derivations always go through the ordinary `DatabaseEvidenceRecorder` fallback (for read access — `provenanceFor()`/`derivationsFor()` have no chain-backed implementation) unless `verdict.evidence.attest.chain_provenance` is enabled, because provenance volume can be orders of magnitude larger than decision volume. An unchained provenance ledger is not covered by the chain's tamper-evidence guarantee — a team that assumes "Verdict has tamper-evident evidence" covers provenance will be wrong at exactly the wrong moment.
- **Local integrity, not global integrity, by default.** A tamper-evident chain proves nothing was edited after the fact only once someone actually verifies it (`php artisan attest:verify`) — an unverified chain is tamper-evident only in retrospect. Verdict does not schedule this for you; see [#41](https://github.com/fissible/verdict/issues/41) for recommended cadence.
- **Truncation is possible and locally undetectable.** An attacker who controls the evidence store can truncate a chain to a chosen point and re-link it; a truncated chain still verifies as internally consistent. Anchoring (`php artisan attest:anchor`, via `fissible/attest-laravel`) is the mitigation — it publishes a Merkle root a rewritten chain cannot reproduce — but anchoring is `@experimental` in `fissible/attest` 1.x and confirms with a lag equal to the anchor interval, not immediately.
- **Tamper-evidence is bounded by key custody.** The chain is tamper-evident against anyone who can reach the evidence store but not the Ed25519 signing key (`ATTEST_SIGNING_KEY_SEED`). An attacker holding that key can rewrite the chain and re-sign it, and verification will pass. Application RCE implies the ability to forge history unless the key is held outside the application's own reach.
- **A failed chain write does not block the protected action.** Per [ADR 0007](adr/0007-evidence-layering.md), evidence is not an authorization gate. `AttestEvidenceRecorder` retries a failed write with backoff, then records a `chain_gap` marker row in the ordinary evidence table (naming the chain and attempt count) and raises an event the application can route to an alert — it does not fail the request unless explicitly configured with `on_failure: 'throw'`.

See the [`AttestEvidenceRecorder` source](../src/Evidence/AttestEvidenceRecorder.php) for the exact configuration surface.
```

- [ ] **Step 2: Add a README pointer**

In `README.md`, under `### Controlling what information the AI sees` (the existing "Features" section covering evidence), add one sentence after the existing paragraph:

```markdown
`AttestEvidenceRecorder` (opt-in, requires `fissible/attest-laravel`) upgrades decisions and context releases from an ordinary mutable audit store to a signed, hash-chained one — see [limitations](docs/limitations.md#tamper-evident-evidence-is-opt-in-partial-and-bounded-by-key-custody) for exactly what it does and does not cover.
```

- [ ] **Step 3: Proofread against the actual shipped config keys**

Re-read `config/verdict.php` after Task 6 and confirm every config key named in this doc (`verdict.evidence.attest.chain_provenance`, `on_failure`) matches exactly. Fix any drift.

- [ ] **Step 4: Commit**

```bash
git add docs/limitations.md README.md
git commit -m "docs: document AttestEvidenceRecorder's coverage and caveats"
```

---

### Task 8: Final verification and issue closeout

**Files:** none (verification only).

- [ ] **Step 1: Full suite, lint, static analysis**

Run: `composer test`
Expected: 0 failures, 100% type coverage, pint clean, phpstan clean.

- [ ] **Step 2: Confirm the diff matches the plan's scope**

Run: `git diff main --stat` (from the worktree, against the branch point)
Expected files touched: `composer.json` (NOT `composer.lock` — this repo's `.gitignore` excludes it; a library package lets consumers resolve their own versions), `config/verdict.php`, `src/VerdictServiceProvider.php`, `src/Evidence/AttestEvidenceRecorder.php`, `src/Evidence/Events/ChainWriteFailed.php`, `src/Exceptions/EvidenceChainWriteFailed.php`, `docs/limitations.md`, `README.md`, and the `tests/` files created above. Nothing else.

- [ ] **Step 3: Push and open the PR**

```bash
git push -u origin feat/attest-evidence-recorder
gh pr create --title "feat: add AttestEvidenceRecorder for tamper-evident evidence" --body "Implements #11."
```

Fill in the PR body using `.github/PULL_REQUEST_TEMPLATE.md`'s sections (Summary, Linked issue, Trust and failure behavior, Evidence and data handling, Verification) — the "Trust and failure behavior" section should explicitly state the ADR 0007 guarantee (evidence write failure never blocks the protected action by default) and the "Evidence and data handling" section should state that chained payloads contain the same fingerprint-first fields the database adapter already writes, nothing new or more sensitive.

---

## Self-Review

**Spec coverage against issue #11's 7 design decisions:**

1. Chain topology (per-tenant) — `chainIdUsing: Closure` (Task 4) + documented `$app->extend()` escape hatch (Task 6) + truncation-exposure doc (Task 7). Verdict has no built-in tenant concept, so true per-tenant chaining is left to the application via the closure; the shipped default is a single fixed chain, documented as such.
2. Verification cadence — explicitly out of scope; owned by #41, cross-referenced in Task 7's docs.
3. Portable proofs — explicitly out of repo (belongs in `fissible/attest`), not touched.
4. Non-equivocation / anchoring — no adapter code needed (attest-laravel already ships `attest:anchor`); covered by Task 7 docs only.
5. Evidence-write failure — retry-with-backoff, gap marker, event, `on_failure` config — Task 4.
6. Chained record types — decisions + context releases chained, provenance/derivations behind `chain_provenance` flag — Tasks 4–5.
7. Key custody — Task 7 docs.

**Placeholder scan:** no "TBD"/"handle appropriately" strings. All factory-method names (`Source::application`, `Source::external`, `Destination::connection`), enum case names (`DataClass::Internal`, `Trust::Trusted`/`Untrusted`, `ContextChannel::ToolResult`, `DerivationKind::Retrieved`), and the `VerdictServiceProvider` `Dispatcher` import were checked against the actual source files in this repo, not guessed.

**Type consistency:** `AttestEvidenceRecorder`'s constructor signature is defined once in Task 4 Step 3 and extended (not redefined) in Task 5 — same parameter names and order throughout. `EvidenceRecorder` contract method signatures match `src/Contracts/EvidenceRecorder.php` exactly (checked against the actual file). `ChainWriteFailed` and `EvidenceChainWriteFailed` are defined once (Task 2) and consumed identically in Task 4.
