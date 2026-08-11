# AttestEvidenceRecorder: Force an Explicit Chain-Topology Decision

**Status:** Approved design, not yet implemented.

**Target:** Amends PR #64 (`feat/attest-evidence-recorder`), which is still open, unmerged, and still under `[Unreleased]` in `CHANGELOG.md`. Not a new issue — this changes an unshipped default before it ships, so there is no deprecation path or breaking-change note to write.

## Motivation

Issue #11's design decision 1 settled on one chain per tenant as the topology, explicitly rejecting a single global chain because it becomes "a throughput ceiling on authorization itself." PR #64 as merged into review implements the *mechanism* correctly — `AttestEvidenceRecorder`'s constructor takes a required `Closure $chainIdUsing`, so per-tenant resolution is a first-class primitive, not bolted on — but the container-wired *default binding* silently wraps a single fixed config value (`'chain' => env('VERDICT_ATTEST_CHAIN', 'verdict')`) in a trivial closure. Every deployment that installs `AttestEvidenceRecorder` and doesn't specifically know to read `docs/limitations.md` closely enough gets the single-global-chain topology issue #11 rejected, by default, silently.

This matters more than an ordinary bad default because the choice is not cleanly reversible after the fact. A chain's integrity depends on an unbroken hash link (`prevHash`) across its full sequence. If a deployment starts on a shared global chain and later wants per-tenant chains, it cannot retroactively split the commingled history into isolated per-tenant chains — the old entries stay permanently mixed. Realizing the mistake later means starting fresh per-tenant chains and treating everything written before that point as a legacy segment that can never be cleanly handed to one tenant in isolation. A well-documented footgun is still a footgun: documentation reduces how many deployments get hurt, not how badly the ones that do get hurt.

The fix is to remove the silent default entirely and force every deployment enabling `AttestEvidenceRecorder` to make the topology choice explicitly, once, at configuration time — while keeping the choice cheap for the common single-tenant case (a string) and making the multi-tenant case fully expressible in config (a class name), not only reachable by overriding a service provider binding.

## Why two config keys instead of unifying to one resolver-shaped mechanism

An alternative considered: always require a resolver class, and ship a trivial built-in `FixedChainResolver` for the single-chain case, so there is exactly one mechanical path. Rejected — setting `'chain' => 'my-app'` explicitly (with no default to fall back to) already *is* the deliberate, undismissable act of choosing a single fixed chain. Requiring a resolver class just to hardcode one string is ceremony without benefit. Two named, mutually exclusive keys give the same "cannot proceed without deciding" property with less friction for the common case.

## Design

### New contract

`Fissible\Verdict\Contracts\AttestChainResolver` (new file, alongside the existing `EvidenceRecorder`, `Clock`, etc. in `src/Contracts/`):

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

No arguments — this mirrors the existing `chainIdUsing: Closure(): string` shape `AttestEvidenceRecorder` already accepts. An application implementing this reads whatever request/container-scoped state it needs (current tenant, current org) inside `resolve()` itself.

### Config changes (`config/verdict.php`)

Within the existing `'attest' => [...]` block:

- `'chain'` loses its `'verdict'` fallback: `'chain' => env('VERDICT_ATTEST_CHAIN')` (nullable, no default).
- New sibling key: `'chain_resolver' => env('VERDICT_ATTEST_CHAIN_RESOLVER')` (nullable class-string).

Replace the current comment block (which currently documents `$app->extend()` as the only way to get per-tenant chains) with an explanation of the two keys and why exactly one must be set — plus a short worked example of a resolver class, so the comment stays the "how do I actually do this" reference the existing block already serves.

### `VerdictServiceProvider` validation

Inside the existing `EvidenceRecorder::class` singleton factory's `AttestEvidenceRecorder::class` branch, before constructing the recorder:

```php
$chain = config('verdict.evidence.attest.chain');
$resolverClass = config('verdict.evidence.attest.chain_resolver');

if ($chain === null && $resolverClass === null) {
    throw new LogicException(
        'AttestEvidenceRecorder requires an explicit chain-topology decision: set '
        .'verdict.evidence.attest.chain to a fixed chain id for a single shared chain, or '
        .'verdict.evidence.attest.chain_resolver to a class implementing '
        .AttestChainResolver::class.' for per-tenant chains. This choice is not safely '
        .'changeable later — a chain\'s hash-linked history cannot be retroactively split '
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
    if (! is_string($resolverClass)) {
        throw new LogicException('The Verdict attest chain resolver configuration must contain a class name.');
    }

    // Type-check eagerly so misconfiguration fails at boot, not on the first evidence
    // write — via class_implements(), not $app->make(). class_implements() returns
    // false (never throws) for a class that doesn't exist or can't autoload, so a
    // typo'd class name still produces the LogicException below instead of an uncaught
    // framework exception. It also never constructs the resolver, so this check has no
    // side effects, unlike $app->make(), which would run the class's constructor just
    // to type-check it. The real per-write resolution below still goes through $app on
    // every call, deliberately never caching an instance, so a request-scoped or
    // tenant-scoped binding inside resolve() is re-evaluated fresh each time. Caching a
    // resolved instance here would reintroduce the exact stale-state-across-requests bug
    // this design exists to avoid under Octane.
    if (! in_array(AttestChainResolver::class, class_implements($resolverClass) ?: [], true)) {
        throw new LogicException("The [{$resolverClass}] chain resolver must implement ".AttestChainResolver::class.'.');
    }

    $chainIdUsing = static fn (): string => $app->make($resolverClass)->resolve();
} else {
    $chainIdUsing = static fn (): string => $chain;
}
```

`$chainIdUsing` then passes into `AttestEvidenceRecorder`'s constructor exactly as it does today — no change to `AttestEvidenceRecorder` itself. All the change is in `VerdictServiceProvider`, `config/verdict.php`, the new contract file, and docs.

The existing generic-recorder validation a few lines below this block (`$instance = $app->make($recorder)`, for a fully custom `EvidenceRecorder` implementation) has this exact same fragility today — a typo'd class name there throws an uncaught framework exception rather than a clean message. That's a pre-existing gap, not something this design introduces, and fixing it is out of scope here — but it's the reason to use `class_implements()` in the new code rather than copy the existing pattern into a second location.

### Failure paths through the existing retry/gap machinery

A `resolve()` that throws flows into `AttestEvidenceRecorder::writeChained()`'s existing `chainIdUsing`-throws handling (the `resolverFailed` branch, fixed in `5acacd3` earlier in this PR) exactly the same as a hand-written closure that throws — `phase: 'resolve_chain_id'`, `attempts: 0`, gap marker recorded, event dispatched, caller never blocked in `alert` mode. No new failure-handling code needed here; the design's job is to prove this existing path is actually exercised through the resolver-class wiring, not just through a raw closure (see Testing).

### Docs

- `docs/limitations.md`: replace the "shipped default topology is a single global chain" paragraph (added in the earlier final-review fix wave) — it described a silent default that no longer exists. Explain the two explicit options and state plainly why the choice isn't safely reversible (the hash-chain-splitting argument above).
- `config/verdict.php` comment block: rewritten per above.
- Both locations must say explicitly that `chain_resolver` supersedes `$app->extend(EvidenceRecorder::class, ...)` as the recommended way to get per-tenant chain ids specifically. Today's docs point at `$app->extend()` as *the* answer for multi-tenancy; once `chain_resolver` exists, leaving both documented side by side without ranking them reads as two competing answers. `$app->extend()` remains the right tool for customization `chain_resolver` can't express — swapping the fallback recorder, varying `on_failure` per tenant, replacing the whole `EvidenceRecorder` — but for "I need the chain id to vary by tenant," `chain_resolver` is now the first-class path and the docs should say so, not just add it as a third option.

## Testing plan

New `tests/Support/` classes (a container-bound class name must be a real named class, not anonymous):

- `StaticAttestChainResolver` — `resolve()` returns `'tenant:'.(++self::$calls)` off a resettable static counter. Two `record()` calls landing on two *different* chain tails is the proof that resolution happens fresh per write, not once and cached.
- `ThrowingAttestChainResolver` — `resolve()` throws `RuntimeException`, for the phase-coverage test below.

New cases in `tests/Integration/AttestEvidenceRecorderServiceProviderTest.php` (container-resolution-based — this validation lives in `VerdictServiceProvider` and can't be reached through a constructor-level unit test, since the constructor never sees the two raw config keys, only the already-built closure):

1. Neither `chain` nor `chain_resolver` set → `LogicException` with the topology-decision message.
2. Both set → `LogicException` with the ambiguous-configuration message.
3. `chain_resolver` set to a class not implementing `AttestChainResolver` → `LogicException` naming the class and the contract.
3a. `chain_resolver` set to a class name that doesn't exist (the typo case) → the same clean `LogicException`, not an uncaught framework exception — this is the specific regression `class_implements()` (over `$app->make()`) protects against, and needs its own test rather than relying on case 3 to imply it.
4. `chain` set alone → still resolves and writes to that fixed chain (regression coverage for the existing single-tenant path, now that it's no longer the default).
5. `chain_resolver` set to `StaticAttestChainResolver` → two `record()` calls produce entries on two distinct chain tails (`tenant:1`, `tenant:2`) — the freshness proof.
6. `chain_resolver` set to `ThrowingAttestChainResolver` → `record()` through the **container-built** recorder does not throw in `alert` mode, writes exactly one gap-marker row, and the row's `reason` JSON plus the dispatched `ChainWriteFailed` event both show `phase === 'resolve_chain_id'` and `attempts === 0`. This is the case worth calling out explicitly: it proves the closure `VerdictServiceProvider` builds around a resolver class correctly propagates into the existing phase-tagged handling from `5acacd3` — not just that `AttestEvidenceRecorder`'s own closure-handling logic works with a hand-rolled closure in isolation, which is already covered by the existing `tests/Feature/AttestEvidenceRecorderTest.php` case.

No changes needed to `tests/Feature/AttestEvidenceRecorderTest.php`'s existing constructor-level tests — `AttestEvidenceRecorder` itself is unchanged.

## Out of scope

- Any built-in resolver implementations beyond the two test-only classes above (e.g. a shipped `TenantContextChainResolver` reading some framework-standard "current tenant" concept) — Verdict has no tenancy concept of its own, and inventing one is not this design's job.
- Changing `chain_provenance`, `on_failure`, `max_attempts`, or `base_delay_ms` — unaffected by this change.
