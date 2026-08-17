# ADR 0027: A capability definition is a declaration, not a service

Status: Accepted

## Related issues

- [#210](https://github.com/fissible/verdict/issues/210) is the work this settles.
- [ADR 0019](0019-verdict-services-are-container-resolved.md) — Verdict's *services* are container-resolved.
  This ADR states where that posture stops.
- [ADR 0020](0020-live-trial-isolation-is-application-owned.md) — state that must not leak across trials.
  The lifetime rule the rejection below rests on is enforced practice rather than a stated invariant; its
  precedent is cited in §2.
- [#183](https://github.com/fissible/verdict/issues/183) — the binding-lifetime defect this codebase has
  already paid for once.
- [ADR 0017](0017-configuration-identity-in-evidence.md) — Verdict cannot see inside a closure. That
  blindness bounds what an affirmation can mean.

## Context

Registering a capability means composing a fluent builder inside a service provider's `boot()`. The
ceremony grows linearly with capabilities and gives a capability no canonical home in an application's
structure. `verdict:make-capability` has emitted a class with `public static function make(): Capability`
since v0.4.0 — the shape already exists; it is not a contract, and nothing discovers it.

The goal is to reduce registration ceremony to zero **without supplying a single security default**. That
constraint is what makes this a decision rather than a convenience: the generator's throwing TODOs are the
shortest path to *explicitly* answering every security question, and anything that makes it easier to skip
one of those answers is a regression disguised as ergonomics.

## Decision

### 1. The contract is `static make(): Capability`, and implementing it is an affirmation

`DefinesCapability` declares one member, matching what the generator already emits. Every capability
generated since v0.4.0 satisfies it by adding `implements DefinesCapability` — one token, in the file the
developer is already editing.

Adding the interface is the deliberate act that replaces provider registration. It says *I have replaced
every TODO in this file.* Verdict cannot check that claim (see §5), and does not pretend to.

### 2. A capability definition is a declaration, and is therefore never container-resolved

The contract is static, and discovery calls `ClassName::make()` without touching the container.

The reason is lifetime, not cost. Discovery runs once, at boot. An instance-based contract resolved from
the container would resolve whatever a definition class constructor-injects **at boot**, and that
collaborator would then live inside the built `Capability` for the life of the worker.

This codebase has already paid for that defect once. `VerdictServiceProvider` binds
`CapabilityConfigurationStore` with `scoped` rather than `singleton`, and says why in place:

> A singleton would outlive a recorder an application rebinds with a shorter lifetime — after a
> `Container::forgetScopedInstances()` the wrapper keeps writing to the discarded instance while readers
> resolve the new one, and nothing errors.

That is the rule — *a binding must not outlive what it captures* — as enforced practice, established by
[#183](https://github.com/fissible/verdict/issues/183). It is not stated as an invariant in any ADR;
recording it here is part of this decision's contribution. Constructor injection into a definition class
would reintroduce exactly that surface under Octane and queue workers, where nothing today can produce it.

So the generated closures calling `app()` inside their bodies are **not** a compromise around missing
dependency injection. They are the correct pattern: resolution happens at invocation time, in the request
scope that invocation belongs to, every time.

ADR 0019's container posture governs Verdict's own services. It does not extend to application
definitions, and importing it here would import the wrong lifetime.

### 3. Discovery registers only affirmed classes, through the ordinary registration path

`CapabilityDiscovery` scans configured paths (default `app/Capabilities`, matching where the generator has
always written) and **classifies** what it finds without building anything. A separate registrar calls
`make()` on each affirmed class and registers the result through `CapabilityRegistry::register()` — the
same method a provider calls.

Finding and building are separate because `verdict:validate` needs the classification without the side
effects: it reports on classes it must not register into a booting application.

Downstream, a discovered capability and a manually registered one are the same object. `registeredCapability()`,
`verdict:validate`, decision evidence, and `CapabilitySecurityTestKit` cannot tell them apart, and nothing
in Verdict may begin to.

Discovery runs on `$app->booted()`, after application providers have registered theirs, so a capability
registered both ways produces a deterministic collision rather than an ordering-dependent race.

### 4. A broken affirmation fails the deploy; an absent affirmation is inert and visible

| State | Outcome |
|---|---|
| Class in path, does not implement the contract | Inert. Listed by `verdict:validate`. |
| Class implements the contract but is abstract or otherwise not instantiable | Inert. Same advisory list. |
| File maps to no loadable class | Inert. Same advisory list. |
| Implements the contract, `make()` throws | **Boot fails**, cause chained. |
| Implements the contract, name already registered manually | Boot fails, naming the provider registration. |
| Two discovered classes producing the same capability name | Boot fails, naming both classes. |

The throw is not caught. A falsely-affirmed capability failing at boot is the earliest possible moment —
before any request, before any tool call.

**Boot reports every failure, not the first.** This corrects an earlier version of this decision, which
split the reporting: boot dying on the first failure, `verdict:validate` collecting them all. That split
cannot exist. Artisan bootstraps the application *before* dispatching a command:

```php
// Illuminate\Foundation\Console\Kernel::handle()
$this->bootstrap();                               // BootProviders → $app->boot() → booted() callbacks
return $this->getArtisan()->run($input, $output); // dispatch, after
```

So a throwing definition kills `php artisan verdict:validate` during its own bootstrap, before the command
runs. There is no artisan context after a throwing `booted()` callback. Fix-all-at-once therefore lives at
boot or nowhere, and it lives at boot: the registrar builds every affirmed definition, collects each
failure, and throws one exception listing all of them under a count.

**The pipeline guarantee survives, by a different mechanism.** `verdict:validate` in a deploy pipeline still
fails with the complete list before production boots the same code — the aggregated exception is thrown by
the command's own bootstrap, non-zero exit, full list in the output. What changed is which layer reports it,
not whether the pipeline catches it.

**Registration is all-or-nothing.** If any definition fails, none registers. This is an invariant rather
than an implementation detail: a boot that is going to die must not leave a partial security surface behind
first, which matters wherever a boot failure does not immediately end the process — test harnesses, and
anything Octane-shaped.

**Collecting means continuing past a failure**, which is safe precisely because of §2: a definition is a
declaration, so `make()` composes closures and must not have side effects. A definition that threw poisons
nothing that follows it. That framing is now a behavioural dependency, not only vocabulary.

The boot failure message names **both** legitimate exits: finish the TODOs, or remove
`implements DefinesCapability` until the capability is finished. Un-affirming is the honest way to ship a
deploy with a capability mid-work. An error that omits it pushes a developer toward deleting the file or
hacking out the TODO, both worse.

### 5. `verdict:validate` reports the unaffirmed, and only the unaffirmed

Broken definitions are reported by boot (§4), including during validate's own bootstrap, so the command
itself reports the one state that never blocks a boot: classes sitting in a discovery path that never
affirmed the contract.

Those advisories **print on every run**; `--strict` changes only the exit code, never the visibility. This is the `NullEvidenceRecorder` pattern: never blocks, always visible.
An unaffirmed generated file is otherwise the one state discovery leaves invisible — safe, because inert,
but not legible.

### 6. The per-boot scan cost is accepted, and caching is deferred with a named trigger

Under Octane the scan runs once per worker; under php-fpm it runs per request. The directory is small and
the classes are ones the application already loaded to register manually, so the marginal cost is the
directory walk.

Shipping without a cache is a deliberate trade, recorded here so the first php-fpm adopter who profiles
boot finds a known decision rather than an undiscovered defect. The future move is bootstrap-cache
integration, the way Laravel caches event discovery. **The trigger is profiling showing it matters** — not
a suspicion that it might.

## Alternatives rejected

**An instance method resolved from the container.** Rejected on lifetime grounds — see §2. It would
front-load resolution into boot and hand Octane and queue workers a stale-collaborator surface that today
cannot exist. Cost is the secondary objection: it turns a one-token edit of every already-generated class
into a real one. The testability argument for it also dissolves on inspection — `CapabilitySecurityTestKit`
already drives the real registered capability through the real protected path, and container-bound fakes
work unchanged for `app()`-resolved collaborators. Nothing it enables is unreachable today.

**Typed methods (`resolveTarget()`, `executor()`, …).** Reads better at the class level and duplicates the
builder's entire surface, creating two ways to say everything. The fluent API stays canonical.

**An abstract base class with overridable defaults.** Rejected in advance, and not deferred: a default
implementation of a security decision is a silent permit. Fewer questions is the wrong goal; the shortest
path to *explicitly* answering every question is the right one.

**Skipping a throwing capability and warning instead of failing.** Rejected. It makes an affirmed
capability silently absent, which surfaces later as a confusing unregistered-capability error at tool-build
time with the cause hidden. Worse, a capability that denies everything reads like a policy bug, and the fix
someone reaches for under pressure is a permissive path — the one failure mode this package exists to
prevent.

**Skipping discovery while `verdict:validate` runs**, so the command could aggregate failures itself.
Rejected on integrity grounds rather than brittleness: it would make validate audit a configuration the
application never actually boots with, which is worse than useless in an audit surface. The objection
generalizes — any future "skip X while command Y runs" has the same defect.

**Introspecting closures to detect unfinished TODOs.** Verdict cannot see inside a closure
([ADR 0017](0017-configuration-identity-in-evidence.md)), and invoking one to find out has side effects.

## Consequences

Registration ceremony drops to one token per capability, in a file the developer is already editing, and a
capability gains a canonical home.

**What this does not do:**

- **The interface is not proof.** It records a claim Verdict cannot verify, the same honest under-claim as
  `targetSource` naming the constructor that was used rather than a property of the closure body. A false
  affirmation still fails closed — at boot when construction throws, at first invocation otherwise.
- **It supplies no security default.** Discovery changes *when* a capability is registered, never *what* it
  permits.
- **It does not make an unfinished capability safe.** It makes an unfinished one visible.
