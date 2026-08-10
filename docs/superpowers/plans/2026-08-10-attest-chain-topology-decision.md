# AttestEvidenceRecorder Chain-Topology Decision Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove `AttestEvidenceRecorder`'s silent single-global-chain default and force every deployment to make an explicit, config-validated choice between a fixed chain id (`verdict.evidence.attest.chain`) or a config-driven per-tenant resolver class (`verdict.evidence.attest.chain_resolver`, implementing a new `AttestChainResolver` contract). Amends PR #64 (`feat/attest-evidence-recorder`), still open and still under `[Unreleased]`.

**Architecture:** One new one-method contract (`Fissible\Verdict\Contracts\AttestChainResolver`). `config/verdict.php`'s `'chain'` key loses its `'verdict'` fallback; a new sibling `'chain_resolver'` key is added. `VerdictServiceProvider`'s existing `AttestEvidenceRecorder::class` binding branch validates that exactly one of the two is set (using `class_implements()`, not `$app->make()`, to type-check a resolver class without risking an uncaught exception on a typo or constructing an instance just to check its shape), then builds the same `Closure $chainIdUsing` `AttestEvidenceRecorder`'s constructor already accepts — no changes to `AttestEvidenceRecorder` itself.

**Tech Stack:** PHP 8.3, Laravel 12/13, Pest 4 — same as the rest of this PR.

**Spec:** `docs/superpowers/specs/2026-08-10-attest-chain-topology-decision-design.md` — read this first for the full rationale (why two keys instead of one resolver-shaped mechanism, why `class_implements()` over `$app->make()`, why this is safe to change now). This plan implements that spec; where anything here seems to need justification beyond what's written, the spec has it.

## Global Constraints

- PHP `^8.3`, `declare(strict_types=1)` in every new/modified file.
- 100% type coverage enforced by `composer test` (`pest --type-coverage --min=100`).
- `final` classes, constructor property promotion, named arguments — match existing style exactly.
- No changes to `AttestEvidenceRecorder` itself. Its constructor already accepts `Closure $chainIdUsing`; this feature only changes what builds that closure.
- No new ADR — per this repo's convention, the design decisions live in the spec doc, matching how the original `AttestEvidenceRecorder` plan handled this.
- This is a genuine, deliberate breaking change to an **unreleased** default (removing `'chain' => env('VERDICT_ATTEST_CHAIN', 'verdict')`'s fallback). Every existing test that relies on that default must be updated to set `verdict.evidence.attest.chain` explicitly — this is called out per-task below, not left for a task to discover as a surprise failure.

---

### Task 1: `AttestChainResolver` contract and two test-double implementations

**Files:**
- Create: `src/Contracts/AttestChainResolver.php`
- Create: `tests/Support/StaticAttestChainResolver.php`
- Create: `tests/Support/ThrowingAttestChainResolver.php`

**Interfaces:**
- Produces: `Fissible\Verdict\Contracts\AttestChainResolver` — one method, `resolve(): string`, may throw. Consumed by Task 2's `VerdictServiceProvider` wiring and by both test-double implementations here.
- Produces: `Fissible\Verdict\Tests\Support\StaticAttestChainResolver implements AttestChainResolver` — `resolve()` returns `'tenant:'.(++self::$calls)` off a public static counter (public so tests can reset it in their own `beforeEach`/setup, matching how `tests/Support/FlakyChainStore.php`'s `ChainCallCounter` exposes its count via a public method rather than hiding it).
- Produces: `Fissible\Verdict\Tests\Support\ThrowingAttestChainResolver implements AttestChainResolver` — `resolve()` unconditionally throws `RuntimeException('tenant resolution failed')`.
- Consumes: nothing from elsewhere in this plan — this is the leaf task.

This task has no independent runtime test of its own (an interface has no behavior, and the two implementations are trivial enough that their real proof is being exercised by Task 2's integration tests). Verify via static analysis only, matching the precedent set by the original `AttestEvidenceRecorder` plan's Task 3 (`FlakyChainStore`/`AttestFixture`, verified the same way).

- [ ] **Step 1: Write the contract**

Create `src/Contracts/AttestChainResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

interface AttestChainResolver
{
    /**
     * Return the chain id to write to for the write currently in progress.
     *
     * May throw. A thrown exception is not treated as a bug — it is handled the same
     * way as an exhausted chain-store write: a chain_gap marker is recorded, a
     * ChainWriteFailed event is dispatched with phase: 'resolve_chain_id' and
     * attempts: 0, and the caller is not blocked unless on_failure is 'throw'. See
     * AttestEvidenceRecorder::writeChained().
     */
    public function resolve(): string;
}
```

- [ ] **Step 2: Write the two test doubles**

Create `tests/Support/StaticAttestChainResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use Fissible\Verdict\Contracts\AttestChainResolver;

final class StaticAttestChainResolver implements AttestChainResolver
{
    public static int $calls = 0;

    public function resolve(): string
    {
        return 'tenant:'.(++self::$calls);
    }
}
```

Create `tests/Support/ThrowingAttestChainResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use Fissible\Verdict\Contracts\AttestChainResolver;
use RuntimeException;

final class ThrowingAttestChainResolver implements AttestChainResolver
{
    public function resolve(): string
    {
        throw new RuntimeException('tenant resolution failed');
    }
}
```

- [ ] **Step 3: Verify**

Run: `vendor/bin/phpstan analyse src/Contracts/AttestChainResolver.php tests/Support/StaticAttestChainResolver.php tests/Support/ThrowingAttestChainResolver.php --memory-limit=1G`
Expected: `[OK] No errors`.

Run: `vendor/bin/pint src/Contracts/AttestChainResolver.php tests/Support/StaticAttestChainResolver.php tests/Support/ThrowingAttestChainResolver.php --test`
Expected: clean (no fixers needed) — but if Pint reports formatting fixes, run `vendor/bin/pint` (without `--test`) on the same three files to apply them, then re-run `--test` to confirm clean. Do not hand-format to guess what Pint wants.

- [ ] **Step 4: Commit**

```bash
git add src/Contracts/AttestChainResolver.php tests/Support/StaticAttestChainResolver.php tests/Support/ThrowingAttestChainResolver.php
git commit -m "feat: add AttestChainResolver contract and two test-double implementations"
```

---

### Task 2: Config validation and `VerdictServiceProvider` wiring

**Files:**
- Modify: `config/verdict.php`
- Modify: `src/VerdictServiceProvider.php`
- Modify: `tests/Integration/AttestEvidenceRecorderServiceProviderTest.php`

**Interfaces:**
- Consumes: `Fissible\Verdict\Contracts\AttestChainResolver` (Task 1), `Fissible\Verdict\Tests\Support\StaticAttestChainResolver` and `ThrowingAttestChainResolver` (Task 1, tests only), `Fissible\Verdict\Evidence\AttestEvidenceRecorder`'s existing constructor (unchanged — `chainIdUsing: Closure` is still the eleventh... fifth positional/named parameter, unchanged shape).
- Produces: the container now throws `LogicException` for `AttestEvidenceRecorder::class` when neither/both of `chain`/`chain_resolver` are configured, or when `chain_resolver` doesn't conform; otherwise builds the same `chainIdUsing` closure shape as before.

This is the core of the feature. The existing `AttestEvidenceRecorder::class` branch in `VerdictServiceProvider.php` (currently lines 118-145 — confirm the exact line numbers in your checkout before editing, since Task 1 doesn't touch this file and line numbers should be unchanged, but always verify against what you actually see) is being replaced, not appended to.

- [ ] **Step 1: Write the failing tests**

First, fix the two **existing** tests in `tests/Integration/AttestEvidenceRecorderServiceProviderTest.php` so they keep passing once the `'chain'` config default is removed. Find this in the file's `beforeEach`:

```php
beforeEach(function (): void {
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
```

Change it to:

```php
beforeEach(function (): void {
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
    config()->set('verdict.evidence.attest.chain', 'verdict');
```

This one line keeps the file's two existing tests (`'resolves an AttestEvidenceRecorder from config and records a real decision'` and `'routes provenance through the real DatabaseEvidenceRecorder fallback the container built'`) passing under the new validation — they were previously relying on the `'chain'` config's default, which is going away, without setting it themselves.

Next, extract the repeated 25-argument `DecisionEvidence` construction already duplicated across this file's two existing tests into a local helper (you're about to add several more call sites that need one). Add near the top of the file, after the `afterEach` block and before the first `it(...)`:

```php
function attestDecisionEvidence(string $envelopeId): DecisionEvidence
{
    return new DecisionEvidence(
        envelopeId: $envelopeId,
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
    );
}
```

Named `attestDecisionEvidence`, not a generic `decisionEvidence`, because Pest loads every test file's global functions into one process — a generic name risks colliding with a same-named helper in a future file (this exact naming-collision risk was flagged and fixed once already in this PR's history for `chainGapRows()`/`makeRecorder()`; don't reintroduce it here).

Replace the existing first test's inline 25-argument construction with a call to this helper — find:

```php
    $recorder->record(new DecisionEvidence(
        envelopeId: 'env-int-1',
        capability: 'orders.refund',
        ... (the rest of the 25 arguments)
    ));
```

Replace with:

```php
    $recorder->record(attestDecisionEvidence('env-int-1'));
```

Now add the new test cases. Add the imports this needs at the top of the file, alongside the existing `use` statements:

```php
use Fissible\Verdict\Evidence\Events\ChainWriteFailed;
use Fissible\Verdict\Tests\Support\StaticAttestChainResolver;
use Fissible\Verdict\Tests\Support\ThrowingAttestChainResolver;
use Illuminate\Support\Facades\Event;
```

Append these `it(...)` blocks at the end of the file:

```php
it('throws when neither chain nor chain_resolver is configured', function (): void {
    config()->set('verdict.evidence.attest.chain', null);

    expect(fn () => app(EvidenceRecorder::class))
        ->toThrow(LogicException::class, 'AttestEvidenceRecorder requires an explicit chain-topology decision');
});

it('throws when both chain and chain_resolver are configured', function (): void {
    config()->set('verdict.evidence.attest.chain_resolver', StaticAttestChainResolver::class);

    expect(fn () => app(EvidenceRecorder::class))
        ->toThrow(LogicException::class, 'AttestEvidenceRecorder received both');
});

it('throws when chain_resolver does not implement AttestChainResolver', function (): void {
    config()->set('verdict.evidence.attest.chain', null);
    config()->set('verdict.evidence.attest.chain_resolver', stdClass::class);

    expect(fn () => app(EvidenceRecorder::class))
        ->toThrow(LogicException::class, 'The ['.stdClass::class.'] chain resolver must implement');
});

it('throws a clean LogicException, not an uncaught framework exception, when chain_resolver names a class that does not exist', function (): void {
    config()->set('verdict.evidence.attest.chain', null);
    config()->set('verdict.evidence.attest.chain_resolver', 'Fissible\\Verdict\\Tests\\Support\\ThisClassDoesNotExist');

    expect(fn () => app(EvidenceRecorder::class))
        ->toThrow(LogicException::class, 'chain resolver must implement');
});

it('resolves the chain id through the configured resolver class fresh on every write', function (): void {
    config()->set('verdict.evidence.attest.chain', null);
    config()->set('verdict.evidence.attest.chain_resolver', StaticAttestChainResolver::class);
    StaticAttestChainResolver::$calls = 0;

    $recorder = app(EvidenceRecorder::class);
    expect($recorder)->toBeInstanceOf(AttestEvidenceRecorder::class);

    $recorder->record(attestDecisionEvidence('env-tenant-1'));
    $recorder->record(attestDecisionEvidence('env-tenant-2'));

    $first = AttestEnvelope::query()->forCorrelation('env-tenant-1')->first();
    $second = AttestEnvelope::query()->forCorrelation('env-tenant-2')->first();

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and($first->chain_id)->toBe('tenant:1')
        ->and($second->chain_id)->toBe('tenant:2');
});

it('propagates a throwing chain_resolver into the existing resolverFailed/phase-tagged gap handling', function (): void {
    Event::fake([ChainWriteFailed::class]);

    config()->set('verdict.evidence.attest.chain', null);
    config()->set('verdict.evidence.attest.chain_resolver', ThrowingAttestChainResolver::class);

    $recorder = app(EvidenceRecorder::class);
    $recorder->record(attestDecisionEvidence('env-resolver-fail'));

    $row = app(DatabaseManager::class)->connection()
        ->table('verdict_evidence')
        ->where('correlation_id', 'env-resolver-fail')
        ->where('record_type', 'chain_gap')
        ->first();

    expect($row)->not->toBeNull();

    $reason = json_decode((string) $row->reason, true, flags: JSON_THROW_ON_ERROR);
    expect($reason['phase'])->toBe('resolve_chain_id')
        ->and($reason['attempts'])->toBe(0);

    Event::assertDispatched(ChainWriteFailed::class, fn (ChainWriteFailed $e): bool => $e->phase === 'resolve_chain_id'
        && $e->attempts === 0);
});
```

Note: this file's two **original** tests, now that their `beforeEach` sets `'chain' => 'verdict'` explicitly, already serve as the "fixed chain id still works" regression case the spec's testing plan calls out as item 4 — no separate new test needed for that; don't add a redundant one.

- [ ] **Step 2: Run to verify the new tests fail, and check the two fixed-up existing tests still pass**

Run: `vendor/bin/pest tests/Integration/AttestEvidenceRecorderServiceProviderTest.php`
Expected: the two original tests pass (proving Step 1's `beforeEach` fix works). The six new tests fail — the "neither/both/doesn't-implement/doesn't-exist" ones because `VerdictServiceProvider` doesn't validate yet (config silently still defaults or the resolver key is silently ignored), the "resolves fresh" and "propagates" ones because `chain_resolver` isn't wired to anything yet.

- [ ] **Step 3: Update `config/verdict.php`**

Find the `'attest' => [...]` block's `'chain'` line and its preceding comment:

```php
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
```

Replace with:

```php
        'attest' => [
            // Exactly one of 'chain' or 'chain_resolver' must be set — there is no default.
            // This choice is not safely changeable later: a chain's hash-linked history
            // cannot be retroactively split by tenant. See docs/limitations.md,
            // "Tamper-evident evidence is opt-in, partial, and bounded by key custody".
            //
            // 'chain': a fixed chain id. Every deployment writes every decision and context
            // release to this one chain. Only correct for genuinely single-tenant
            // deployments — every chained write serializes behind this one chain's append
            // lock.
            'chain' => env('VERDICT_ATTEST_CHAIN'),

            // 'chain_resolver': a class implementing
            // Fissible\Verdict\Contracts\AttestChainResolver, resolved through the
            // container fresh on every write (never cached), so a request-scoped or
            // tenant-scoped binding inside resolve() is re-evaluated each time. This is
            // the recommended path for per-tenant chains — it supersedes binding a custom
            // EvidenceRecorder via $app->extend() for this specific need.
            // $app->extend(EvidenceRecorder::class, ...) remains the right tool for
            // customization this can't express: swapping the fallback recorder, varying
            // on_failure per tenant, or replacing the whole EvidenceRecorder.
            //
            // Example:
            //   final class TenantChainResolver implements \Fissible\Verdict\Contracts\AttestChainResolver
            //   {
            //       public function resolve(): string
            //       {
            //           return 'tenant:'.CurrentTenant::id();
            //       }
            //   }
            //   // config/verdict.php:
            //   'chain_resolver' => \App\Support\TenantChainResolver::class,
            'chain_resolver' => env('VERDICT_ATTEST_CHAIN_RESOLVER'),
```

- [ ] **Step 4: Update `VerdictServiceProvider.php`**

Add the import, alongside the existing `use Fissible\Verdict\Contracts\EvidenceRecorder;` line:

```php
use Fissible\Verdict\Contracts\AttestChainResolver;
```

Replace the entire `if ($recorder === AttestEvidenceRecorder::class) { ... }` block (verify the exact current content in your checkout — it should match what's quoted below, since only Task 1 has landed since this plan was written, and Task 1 doesn't touch this file):

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
                    attest: $app->make(AttestRegistry::class),
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

With:

```php
            if ($recorder === AttestEvidenceRecorder::class) {
                $fallbackConnection = config('verdict.evidence.attest.fallback_connection');
                $fallbackTable = config('verdict.evidence.attest.fallback_table', 'verdict_evidence');
                $chain = config('verdict.evidence.attest.chain');
                $resolverClass = config('verdict.evidence.attest.chain_resolver');
                $onFailure = config('verdict.evidence.attest.on_failure', 'alert');
                $chainProvenance = config('verdict.evidence.attest.chain_provenance', false);
                $maxAttempts = config('verdict.evidence.attest.max_attempts', 3);
                $baseDelayMs = config('verdict.evidence.attest.base_delay_ms', 50);

                if ($chain !== null && ! is_string($chain)) {
                    throw new LogicException('The Verdict attest chain configuration must contain a chain id string.');
                }

                if ($resolverClass !== null && ! is_string($resolverClass)) {
                    throw new LogicException('The Verdict attest chain resolver configuration must contain a class name.');
                }

                if ($chain === null && $resolverClass === null) {
                    throw new LogicException(
                        'AttestEvidenceRecorder requires an explicit chain-topology decision: set '
                        .'verdict.evidence.attest.chain to a fixed chain id for a single shared chain, or '
                        .'verdict.evidence.attest.chain_resolver to a class implementing '
                        .AttestChainResolver::class.' for per-tenant chains. This choice is not safely '
                        ."changeable later — a chain's hash-linked history cannot be retroactively split "
                        .'by tenant. See docs/limitations.md.'
                    );
                }

                if ($chain !== null && $resolverClass !== null) {
                    throw new LogicException(
                        'AttestEvidenceRecorder received both verdict.evidence.attest.chain and '
                        .'verdict.evidence.attest.chain_resolver. Configure exactly one — they express '
                        .'mutually exclusive chain topologies.'
                    );
                }

                if ($resolverClass !== null) {
                    if (! in_array(AttestChainResolver::class, class_implements($resolverClass) ?: [], true)) {
                        throw new LogicException("The [{$resolverClass}] chain resolver must implement ".AttestChainResolver::class.'.');
                    }

                    $resolverClassName = is_string($resolverClass) ? $resolverClass : '';
                    $chainIdUsing = static fn (): string => $app->make($resolverClassName)->resolve();
                } else {
                    $fixedChain = is_string($chain) ? $chain : '';
                    $chainIdUsing = static fn (): string => $fixedChain;
                }

                $connection = $app->make(DatabaseManager::class)->connection(
                    is_string($fallbackConnection) ? $fallbackConnection : null,
                );

                return new AttestEvidenceRecorder(
                    attest: $app->make(AttestRegistry::class),
                    fallback: new DatabaseEvidenceRecorder(
                        connection: $connection,
                        table: is_string($fallbackTable) ? $fallbackTable : 'verdict_evidence',
                    ),
                    connection: $connection,
                    events: $app->make(Dispatcher::class),
                    chainIdUsing: $chainIdUsing,
                    table: is_string($fallbackTable) ? $fallbackTable : 'verdict_evidence',
                    chainProvenance: (bool) $chainProvenance,
                    onFailure: is_string($onFailure) ? $onFailure : 'alert',
                    maxAttempts: is_int($maxAttempts) ? $maxAttempts : 3,
                    baseDelayMs: is_int($baseDelayMs) ? $baseDelayMs : 50,
                );
            }
```

The `$resolverClassName = is_string($resolverClass) ? $resolverClass : '';` and `$fixedChain = is_string($chain) ? $chain : '';` lines look redundant given the type checks a few lines above already guarantee these are strings by the time execution reaches them — they're deliberately defensive, matching this file's existing idiom (`is_string($x) ? $x : default`) elsewhere in this exact method, so PHPStan can narrow the closures' return type to `string` without depending on multi-statement, multi-variable flow analysis across several preceding `if`/`throw` blocks holding. Keep them even though they read as dead code — do not simplify them away in this task.

- [ ] **Step 5: Run to verify all tests pass**

Run: `vendor/bin/pest tests/Integration/AttestEvidenceRecorderServiceProviderTest.php`
Expected: PASS (10 tests total — the original 2, fixed up, plus 6 new, plus... count them; if the number doesn't match what you added, find out why before moving on).

- [ ] **Step 6: Run the full suite and static analysis**

Run: `composer test`
Expected: 0 failures, phpstan clean, pint clean, 100% type coverage. If PHPStan flags either closure's return type despite the defensive lines in Step 4, that is the one place in this task where you have latitude to adjust — but confirm with a real PHPStan run before assuming it's needed; don't add speculative type-narrowing nobody asked for.

- [ ] **Step 7: Commit**

```bash
git add config/verdict.php src/VerdictServiceProvider.php tests/Integration/AttestEvidenceRecorderServiceProviderTest.php
git commit -m "feat: require an explicit chain-topology decision for AttestEvidenceRecorder"
```

---

### Task 3: Documentation

**Files:**
- Modify: `docs/limitations.md`
- Modify: `CHANGELOG.md`

**Interfaces:** none — docs only.

- [ ] **Step 1: Rewrite the "shipped default topology" bullet in `docs/limitations.md`**

Find, inside the "Tamper-evident evidence is opt-in, partial, and bounded by key custody" section:

```markdown
- **The shipped default topology is a single global chain.** `verdict.evidence.attest.chain` defaults to one chain id (`verdict`) for the entire deployment, so every chained write serializes behind that one chain's append lock — at volume the chain becomes a throughput ceiling on authorization itself. One chain per tenant is the better general-purpose topology. Multi-tenant applications should bind their own recorder with a `chainIdUsing:` resolver via `$app->extend(EvidenceRecorder::class, ...)`; see the worked example in `config/verdict.php`.
```

Replace with:

```markdown
- **Chain topology is a required, explicit choice — there is no default.** Configure exactly one of `verdict.evidence.attest.chain` (a fixed chain id, correct only for genuinely single-tenant deployments — every chained write serializes behind that one chain's append lock) or `verdict.evidence.attest.chain_resolver` (a class implementing `Fissible\Verdict\Contracts\AttestChainResolver`, resolved fresh on every write, for per-tenant chains). Neither configured, or both configured, fails at boot rather than picking a default: a chain's hash-linked history cannot be retroactively split by tenant, so this choice is not safely revisable after evidence has been written. `chain_resolver` supersedes binding a custom recorder via `$app->extend(EvidenceRecorder::class, ...)` for this specific need — that mechanism remains available for customization `chain_resolver` can't express, such as swapping the fallback recorder or varying `on_failure` per tenant. See the worked example in `config/verdict.php`.
```

- [ ] **Step 2: Amend the `AttestEvidenceRecorder` `CHANGELOG.md` entry**

Find, under `## [Unreleased]`:

```markdown
- Add an opt-in `AttestEvidenceRecorder` that writes signed, hash-chained decision and context-release
  evidence through `fissible/attest-laravel`, with configurable chain id, optional provenance chaining,
  bounded write retries, and a durable `chain_gap` marker plus `ChainWriteFailed` event on exhaustion.
  See `docs/limitations.md`, "Tamper-evident evidence is opt-in, partial, and bounded by key custody",
  for what the chain does and does not guarantee.
```

Replace with:

```markdown
- Add an opt-in `AttestEvidenceRecorder` that writes signed, hash-chained decision and context-release
  evidence through `fissible/attest-laravel`. Chain topology is a required, explicit choice — a fixed
  chain id or a per-tenant `AttestChainResolver` class, with no default — plus optional provenance
  chaining, bounded write retries, and a durable `chain_gap` marker plus `ChainWriteFailed` event on
  exhaustion. See `docs/limitations.md`, "Tamper-evident evidence is opt-in, partial, and bounded by key
  custody", for what the chain does and does not guarantee.
```

This amends the existing unreleased-feature entry rather than adding a second bullet describing the same class — the feature hasn't shipped, so there's nothing to describe as a change against a prior release.

- [ ] **Step 3: Proofread against the actual shipped config keys and error messages**

Re-read `config/verdict.php` and `src/VerdictServiceProvider.php` after Task 2 and confirm every config key name (`chain`, `chain_resolver`) and every claim ("fails at boot", "resolved fresh on every write", "supersedes `$app->extend()`") in both doc edits matches the real, current code exactly. Fix any drift.

- [ ] **Step 4: Commit**

```bash
git add docs/limitations.md CHANGELOG.md
git commit -m "docs: document the required chain-topology decision"
```

---

### Task 4: Final verification

**Files:** none — verification only.

- [ ] **Step 1: Full suite, lint, static analysis**

Run: `composer test`
Expected: 0 failures, 100% type coverage, pint clean, phpstan clean.

- [ ] **Step 2: Confirm the diff matches the plan's scope**

Run: `git diff <task-1-start-commit> --stat` (the commit before Task 1's, i.e. `858135e` at the time this plan was written — confirm with `git log` if unsure)
Expected files touched: `src/Contracts/AttestChainResolver.php`, `tests/Support/StaticAttestChainResolver.php`, `tests/Support/ThrowingAttestChainResolver.php`, `config/verdict.php`, `src/VerdictServiceProvider.php`, `tests/Integration/AttestEvidenceRecorderServiceProviderTest.php`, `docs/limitations.md`, `CHANGELOG.md`. Nothing else.

- [ ] **Step 3: Push**

```bash
git push origin feat/attest-evidence-recorder
```

PR #64 already exists and is in draft — no new PR to open, no PR body to write. Report back that CI is running and let the human decide when to take the PR out of draft.

## Self-Review

**Spec coverage:** every section of `docs/superpowers/specs/2026-08-10-attest-chain-topology-decision-design.md` maps to a task — the contract (Task 1), config + service-provider validation including the `class_implements()` fix and the docs-supersession requirement (Task 2 code, Task 3 docs), the testing plan's six integration cases plus the "fixed chain still works" regression note (Task 2), and the `resolve()` docblock (Task 1).

**Placeholder scan:** no "TBD"/"handle appropriately" strings. One explicit judgment call is flagged for the implementer rather than hidden: Task 2 Step 6's note that PHPStan *might* still want adjustment to the defensive-ternary lines despite them being written specifically to avoid that — told to verify with a real run rather than assume either way.

**Type consistency:** `AttestChainResolver::resolve(): string` is defined once (Task 1) and consumed identically in Task 2's `$app->make($resolverClassName)->resolve()` call and in both test doubles. The `$chainIdUsing` closure's shape (`Closure(): string`) is unchanged from what `AttestEvidenceRecorder`'s constructor already expects — verified by reading the real current file before writing this plan, not assumed from memory.
