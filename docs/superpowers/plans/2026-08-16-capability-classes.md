# Class-Based Capability Definitions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduce capability registration to one token — `implements DefinesCapability` — with discovery, a six-state failure taxonomy, and no security defaults.

**Architecture:** A static contract; a discovery service that *classifies* without building; a registrar that builds and registers through the ordinary `CapabilityRegistry::register()`; `verdict:validate` reusing the classification with aggregate reporting. Decisions and rejections: [ADR 0027](../../adr/0027-a-capability-definition-is-a-declaration.md). Full design: [the spec](../specs/2026-08-16-capability-classes-design.md).

**Tech Stack:** PHP 8.3, Laravel 12/13, Pest 4 — same as the rest of the repo.

## Global Constraints

- `declare(strict_types=1)` in every file; `final` / `final readonly` classes; constructor property promotion; named arguments.
- 100% type coverage (`pest --type-coverage --min=100`); PHPStan clean; Pint clean.
- **Never supply a security default.** Discovery changes *when* a capability registers, never *what* it permits.
- **Do not introspect closures.** The interface is an affirmation, not a proof (ADR 0027 §1, ADR 0017).
- Discovery must not catch a throwing `make()` at boot. `verdict:validate` catches, aggregates, and reports.
- Advisories print on every `verdict:validate` run; `--strict` changes only the exit code.

**Refinement to the spec's sketch:** `CapabilityDiscovery` takes `(string $rootPath, string $rootNamespace, array $paths)` rather than `(paths, applicationNamespace)`. Mapping a file to an FQCN needs both halves of the PSR-4 pair, and the test fixtures live under `Fissible\Verdict\Tests\` rather than `App\`.

---

### Task 1: The contract

**Files:**
- Create: `src/Contracts/DefinesCapability.php`
- Test: covered by Task 2's fixtures (an interface alone has no behavior to assert)

**Interfaces:**
- Produces: `Fissible\Verdict\Contracts\DefinesCapability::make(): Capability` (static)

- [ ] **Step 1: Write the contract**

```php
<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Capabilities\Capability;

/**
 * Marks a class as a finished capability definition, discoverable without provider registration.
 *
 * Implementing this is an affirmation, never a proof: Verdict cannot see inside the closures the
 * returned Capability carries (ADR 0017), and does not pretend to. A false affirmation still fails
 * closed — at boot when construction throws, at first invocation otherwise. See ADR 0027.
 */
interface DefinesCapability
{
    public static function make(): Capability;
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Contracts/DefinesCapability.php
git commit -m "feat: add the DefinesCapability affirmation contract"
```

---

### Task 2: Classification

**Files:**
- Create: `src/Capabilities/CapabilityDiscovery.php`, `src/Capabilities/DiscoveredCapabilities.php`, `src/Capabilities/UnaffirmedCapability.php`
- Create fixtures: `tests/Fixtures/Capabilities/` (see Step 1)
- Test: `tests/Unit/CapabilityDiscoveryTest.php`

**Interfaces:**
- Consumes: `DefinesCapability`
- Produces:
  - `CapabilityDiscovery::__construct(string $rootPath, string $rootNamespace, array $paths)`
  - `CapabilityDiscovery::discover(): DiscoveredCapabilities`
  - `DiscoveredCapabilities` with `public array $affirmed` (`list<class-string<DefinesCapability>>`) and `public array $unaffirmed` (`list<UnaffirmedCapability>`)
  - `UnaffirmedCapability` with `public string $class`, `public string $reason` and constants `NO_CONTRACT`, `NOT_INSTANTIABLE`, `NO_CLASS`

- [ ] **Step 1: Create the fixtures**

`tests/Fixtures/Capabilities/AffirmedCapability.php` — implements the contract, returns a working `Capability`.
`tests/Fixtures/Capabilities/UnaffirmedCapability.php` — has `make()`, does **not** implement the contract.
`tests/Fixtures/Capabilities/AbstractAffirmedCapability.php` — `abstract`, implements the contract.
`tests/Fixtures/Capabilities/Nested/NestedAffirmedCapability.php` — proves recursion.
`tests/Fixtures/Capabilities/ThrowingRateLimitCapability.php` — **verbatim `verdict:make-capability --rate-limit` output**, whose `make()` throws while building. Comment must say why this fixture: it is the generator's real output, so it guards discovery's failure path *and* the generator's fail-closed property at once.

- [ ] **Step 2: Write the failing test**

```php
it('classifies every state it can find', function (): void {
    $discovery = new CapabilityDiscovery(
        rootPath: __DIR__.'/../Fixtures',
        rootNamespace: 'Fissible\\Verdict\\Tests\\Fixtures\\',
        paths: [__DIR__.'/../Fixtures/Capabilities'],
    );

    $found = $discovery->discover();

    expect($found->affirmed)->toContain(AffirmedCapability::class)
        ->and($found->affirmed)->toContain(NestedAffirmedCapability::class)
        ->and($found->affirmed)->toContain(ThrowingRateLimitCapability::class)
        ->and($found->affirmed)->not->toContain(AbstractAffirmedCapability::class)
        ->and(array_column($found->unaffirmed, 'class'))->toContain(AbstractAffirmedCapability::class);
});
```

Plus one test per reason: `NO_CONTRACT`, `NOT_INSTANTIABLE`, `NO_CLASS` (a `.php` file whose class name does not match its path).

- [ ] **Step 3: Run it and watch it fail** — `vendor/bin/pest tests/Unit/CapabilityDiscoveryTest.php`, expect "Class ... not found".

- [ ] **Step 4: Implement**

`discover()` walks each path with `Finder`/`RecursiveDirectoryIterator` for `*.php`, derives the FQCN as `$rootNamespace . str_replace(['/', '.php'], ['\\', ''], $relativePath)`, and classifies:

```php
if (! class_exists($class)) {
    $unaffirmed[] = new UnaffirmedCapability($class, UnaffirmedCapability::NO_CLASS);
} elseif (! is_a($class, DefinesCapability::class, true)) {
    $unaffirmed[] = new UnaffirmedCapability($class, UnaffirmedCapability::NO_CONTRACT);
} elseif (! (new ReflectionClass($class))->isInstantiable()) {
    $unaffirmed[] = new UnaffirmedCapability($class, UnaffirmedCapability::NOT_INSTANTIABLE);
} else {
    $affirmed[] = $class;
}
```

**`make()` is never called here.** Classification has no side effects.

- [ ] **Step 5: Run tests, then commit**

```bash
git commit -m "feat: classify capability definition classes without building them"
```

---

### Task 3: Registration

**Files:**
- Create: `src/Capabilities/CapabilityRegistrar.php`
- Create: `src/Exceptions/CapabilityDefinitionFailed.php`
- Test: `tests/Unit/CapabilityRegistrarTest.php`

**Interfaces:**
- Consumes: `CapabilityDiscovery::discover()`, `CapabilityRegistry::register()`
- Produces: `CapabilityRegistrar::registerDiscovered(): void`, `CapabilityDefinitionFailed::forClass(string $class, Throwable $cause)`

- [ ] **Step 1: Write the failing tests**

```php
it('registers an affirmed capability through the ordinary registry', ...);

it('lets a throwing make() fail the boot rather than swallowing it', function (): void {
    expect(fn () => $registrar->registerDiscovered())
        ->toThrow(CapabilityDefinitionFailed::class);
});

it('names both legitimate exits when a definition fails', function (): void {
    // The message shape is load-bearing design: without the second exit, the discovered
    // alternative is deleting the file or hacking out the TODO. Assert both.
    expect(fn () => $registrar->registerDiscovered())
        ->toThrow(CapabilityDefinitionFailed::class, 'Finish the TODOs')
        ->and(fn () => $registrar->registerDiscovered())
        ->toThrow(CapabilityDefinitionFailed::class, 'remove `implements DefinesCapability`');
});

it('chains the original cause so the TODO text is the diagnosis', ...);
it('fails when a discovered name is already registered manually', ...);   // names the provider registration
it('fails when two discovered classes produce the same name', ...);       // names both classes
```

- [ ] **Step 2: Run, watch fail. Step 3: Implement. Step 4: Run, pass. Step 5: Commit.**

`registerDiscovered()` calls `$class::make()` inside a `try`/`catch (Throwable $e)` that rethrows `CapabilityDefinitionFailed::forClass($class, $e)` — the catch exists **only** to attach the message and cause, never to continue. Track `array<string, class-string>` of registered-name → class to detect discovered-vs-discovered before handing to the registry.

---

### Task 4: Provider wiring and config

**Files:**
- Modify: `src/VerdictServiceProvider.php`, `config/verdict.php`
- Test: `tests/Feature/CapabilityDiscoveryRegistrationTest.php`

- [ ] **Step 1: Failing tests** — a discovered capability is indistinguishable from a manual one (`registeredCapability()`, evidence, `CapabilitySecurityTestKit`); a path of contract-less classes registers nothing and fails nothing (**the upgrade-safety test**); empty `paths` disables discovery.

- [ ] **Step 2–4: Implement**

```php
$this->app->booted(function (): void {
    $this->app->make(CapabilityRegistrar::class)->registerDiscovered();
});
```

`booted`, not `boot`: application providers register first, so a collision is deterministic rather than racy.

Config, with the cross-reference comment both ways:

```php
// Discovers capability *definition classes* in your application — classes implementing
// DefinesCapability. Not to be confused with `capability_configurations` below, which is the
// durable registry of recorded capability configuration. See ADR 0027.
'capabilities' => [
    'discovery' => [
        'paths' => [app_path('Capabilities')],
    ],
],
```

and above `capability_configurations`:

```php
// The durable registry that expands a configuration fingerprint into the declared configuration
// that produced it. Not to be confused with `capabilities.discovery`, which finds definition
// classes in your application.
```

- [ ] **Step 5: Commit.**

---

### Task 5: `verdict:validate`

**Files:**
- Modify: `src/Console/Commands/ValidateVerdictCommand.php`
- Test: `tests/Feature/ValidateVerdictCommandTest.php`

- [ ] **Step 1: Failing tests** — unaffirmed classes print **without** `--strict`; `--strict` changes only the exit code; every broken class is reported in one pass (assert two distinct class names in one run); a broken class is an error (exit 1) with or without `--strict`.

- [ ] **Step 2–4:** Run the same `CapabilityDiscovery`, then build each affirmed class in `try`/`catch`, collecting failures rather than dying on the first — the opposite discipline to boot, deliberately (ADR 0027 §5).

- [ ] **Step 5: Commit.**

---

### Task 6: Generator alignment

**Files:**
- Modify: `src/Console/Commands/MakeCapabilityCommand.php`
- Test: `tests/Feature/MakeCapabilityCommandTest.php`

- [ ] **Step 1: Failing tests** — generated file imports `DefinesCapability`, does **not** implement it, carries the TODO directing the developer to add it once every other TODO is replaced; closing instructions say to affirm rather than to register in a provider.

- [ ] **Step 2–5:** Implement, verify, commit. Output path unchanged.

---

### Task 7: Documentation

**Files:**
- Modify: `README.md`, `docs/adoption-guide.md`, `CHANGELOG.md`

- [ ] Adoption guide: the affirm-to-register flow, and un-affirming as the honest way to ship with a capability mid-work.
- [ ] README: registration is one token.
- [ ] CHANGELOG: contract, discovery, config key, validate behavior, generator change; state that nothing is discovered until affirmed, so there is no upgrade break.
- [ ] Commit.

---

## Self-review

- **Spec coverage:** contract (T1), classification incl. all three unaffirmed reasons (T2), registration and all three failure rows (T3), wiring/config/upgrade-safety (T4), validate discipline (T5), generator (T6), docs (T7). Six-state taxonomy: rows 1–3 in T2, rows 4–6 in T3.
- **Type consistency:** `discover()` returns `DiscoveredCapabilities`; `$affirmed` is `list<class-string<DefinesCapability>>`; `$unaffirmed` is `list<UnaffirmedCapability>`; the registrar consumes both. Names match across tasks.
- **Deferred:** discovery caching, per ADR 0027 §6, trigger being profiling that shows it matters.
