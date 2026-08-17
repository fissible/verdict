# Class-based capability definitions with auto-discovery — design

Issue: [#210](https://github.com/fissible/verdict/issues/210). Decisions and rejections:
[ADR 0027](../../adr/0027-a-capability-definition-is-a-declaration.md).

## Goal

Reduce capability registration ceremony to zero without supplying a single security default. A developer
who has finished a generated capability adds one token to the class declaration; nothing else.

## Architecture

Four components, each with one job.

### 1. `Fissible\Verdict\Contracts\DefinesCapability`

```php
interface DefinesCapability
{
    public static function make(): Capability;
}
```

Matches what `verdict:make-capability` has emitted since v0.4.0, so every existing generated class
satisfies it by adding `implements DefinesCapability`. Static by decision, not convenience — ADR 0027 §2.

### 2. `Fissible\Verdict\Capabilities\CapabilityDiscovery`

Scans configured paths and returns what it found, classified. It does not register: classification and
registration are separate so `verdict:validate` can consume the same classification without side effects.

```php
final readonly class CapabilityDiscovery
{
    /** @param list<string> $paths */
    public function __construct(private array $paths, private string $applicationNamespace) {}

    public function discover(): DiscoveredCapabilities;
}
```

`DiscoveredCapabilities` carries two lists: `affirmed` (FQCNs implementing the contract and instantiable)
and `unaffirmed` (everything else found in the path, with a reason — no contract, not instantiable, or no
loadable class). Both are lists of class-name strings plus reason; neither holds a built `Capability`.

**Class-name derivation.** A `.php` file's FQCN is the application namespace plus its path relative to
`app/`, matching Laravel's own discovery. A file whose derived class does not exist after autoload lands in
`unaffirmed` with the `no loadable class` reason rather than throwing.

**Instantiability.** `(new ReflectionClass($class))->isInstantiable()` routes abstract classes, interfaces,
and traits to `unaffirmed`. Without this, an abstract helper implementing the contract would reach
`make()` and fail as an `Error`, reported as a broken capability with a confusing message.

### 3. Registration, in `VerdictServiceProvider`

```php
$this->app->booted(function (): void {
    $this->app->make(CapabilityRegistrar::class)->registerDiscovered();
});
```

`booted`, not `boot`: application providers register their capabilities first, so a capability registered
both ways yields a deterministic collision rather than an ordering-dependent race.

`CapabilityRegistrar::registerDiscovered()` calls `$class::make()` for each affirmed class and passes the
result to `CapabilityRegistry::register()` — the same method a provider calls. Discovered and manually
registered capabilities are the same objects downstream.

**It catches nothing.** A throwing `make()` propagates and the deploy fails.

### 4. `verdict:validate`

Runs the same discovery with the opposite reporting discipline: collect everything, report once.

- Each affirmed class is constructed in a `try`/`catch`; every failure is collected with its cause. These
  are **errors** (exit 1) — they will fail at boot, the command's existing bar for a non-zero exit.
- Every unaffirmed class prints as an advisory on **every run**. `--strict` changes only the exit code,
  never the visibility.

## Configuration

```php
'capabilities' => [
    'discovery' => [
        'paths' => [app_path('Capabilities')],
    ],
],
```

Defaults to where the generator has always written. Discovery is on by default, which is safe because the
contract gates it: an existing `app/Capabilities/` predating this feature implements nothing, so an upgrade
discovers nothing until someone affirms. An empty `paths` array disables discovery entirely.

## Failure taxonomy

| State | Boot | `verdict:validate` |
|---|---|---|
| No contract | inert | advisory |
| Contract, not instantiable | inert | advisory |
| No loadable class for the file | inert | advisory |
| Contract, `make()` throws | **fails**, cause chained | error, aggregated with all others |
| Contract, name already registered manually | **fails**, names the provider registration | error |
| Two discovered classes, same capability name | **fails**, names both classes | error |

### Error message shape

Both legitimate exits, always:

```
[App\Capabilities\RefundCapability] could not be built: TODO: choose application-owned rate-limit
scope, limit, window, and binding.

Finish the TODOs in App\Capabilities\RefundCapability, or remove `implements DefinesCapability`
until it is finished.
```

Un-affirming is how a developer ships a deploy with a capability mid-work. An error omitting it pushes them
toward deleting the file or hacking out the TODO, both worse.

## Generator alignment

`verdict:make-capability` emits the `DefinesCapability` import, leaves the class **not** implementing it,
and adds a TODO directing the developer to add it once every other TODO is replaced. The closing
instructions replace "register this capability in your application provider" with that. Output path is
unchanged — changing it would orphan every capability generated since v0.4.0.

## Testing

| Unit | What it proves |
|---|---|
| `CapabilityDiscovery` over a temp directory | classification of all six states, including nested directories |
| Abstract class implementing the contract | lands in `unaffirmed`, never reaches `make()` |
| `CapabilityRegistrar` | a throwing `make()` propagates rather than being swallowed |
| Registrar collision | discovered-vs-manual and discovered-vs-discovered both fail, naming the participants |
| Feature: booted registration | a discovered capability is indistinguishable from a manual one through `registeredCapability()`, evidence, and `CapabilitySecurityTestKit` |
| Feature: `verdict:validate` | advisories print without `--strict`; `--strict` changes only the exit code; multiple broken classes are reported in one pass |
| Feature: default-on upgrade safety | a path full of contract-less classes registers nothing and fails nothing |

Fixtures live under `tests/Fixtures/Capabilities/` so the temp-directory scan runs over real classes.

## Documentation

- ADR 0027 (written).
- `docs/adoption-guide.md`: the affirm-to-register flow, and un-affirming as the way to ship mid-work.
- `README.md`: registration is one token, per #210's DX goal.
- `CHANGELOG.md`: new contract, discovery, config key, validate behavior, generator change. No upgrade
  break — nothing is discovered until a class is affirmed.

## Out of scope

- Any change to the fluent builder or to `Capability`'s security semantics.
- Abstract base classes with default implementations — rejected in ADR 0027, not deferred.
- Attribute-driven control declaration (`#[RateLimit]`).
- Discovery caching. Deferred with a named trigger in ADR 0027 §6: profiling showing it matters.
