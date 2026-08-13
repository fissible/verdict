# Milestones

The release plan. [`RELEASES.md`](RELEASES.md) states the release *policy* — what a minor bump means
and when a release may be cut. This document states the *plan* — what goes in which release, and why
that ordering.

Work is ordered leaves-to-roots over the dependency graph: nothing is scheduled before the things it
depends on. Within a milestone, prefer the smallest effort first, then the most widely depended-on.

Effort key: **XS** (<1h) · **S** (1–2h) · **M** (~half day) · **L** (~1 day) · **XL** (2–3 days).

GitHub milestones mirror this document. When they disagree, this document is wrong and should be
corrected — the issues are the source of truth for scope, this is the source of truth for ordering.

---

## v0.3.0 — Evaluation harness extension *(cut)*

**Theme.** Ship the RAG-borne injection pack and the `BoundTool` wiring fix, and make the release
metadata honest again.

Everything in this release landed on `main` before the milestone existed. It was cut deliberately small
rather than held open for further scope — the attack pack has standing value now, and #29/#30 were
waiting on nothing but a tag.

| Issue | Effort | Deps | Status |
|---|---|---|---|
| [#43](https://github.com/fissible/verdict/issues/43) RAG-borne injection attack pack | M | none | ✅ merged in #55 |
| [#35](https://github.com/fissible/verdict/issues/35) Reject non-executable capabilities at `BoundTool` construction | S | none | ✅ |
| [#50](https://github.com/fissible/verdict/issues/50) Spike: measure Laravel AI tool-invocation correlation | S | none | ✅ unblocks #29 |
| — | S | none | ✅ `RELEASES.md` support matrix and public-surface refresh; `MILESTONES.md` added |

**Why so small.** The alternative was holding the tag for #48 and four XS doc issues — roughly a day of
work unrelated to what had already landed. Tagging first and moving that scope to v0.4.0 got the pack
released and freed the provenance chain to start immediately. A tag is a point in history; work in
flight does not need to wait for it.

---

## v0.4.0 — Provenance chain and dependency honesty *(cut)*

**Theme.** Make provenance a chain rather than a set, and stop depending on Laravel AI's surface by
assumption. Also absorbs the evaluation and documentation scope displaced when v0.3.0 was cut small.

**This tag also carries the whole of the v0.5.0 plan below.** Both milestones' scope completed on `main`
before either was tagged. Rather than emit two tags from one commit or hold the evidence-identity work
back to manufacture a v0.5.0 line that never existed as installable software, both shipped under
`v0.4.0`. The plan section below is retained as the record of *how the work was planned*; the tag is the
record of what shipped.

The work originally planned as v0.6.0 has been renumbered to v0.5.0, here and on GitHub, so that a
milestone name once again names the tag it ships in.

| Issue | Effort | Deps | Status |
|---|---|---|---|
| [#29](https://github.com/fissible/verdict/issues/29) Correlate provenance entries with decision evidence | M | #50 ✅ | ✅ |
| [#30](https://github.com/fissible/verdict/issues/30) Record derivation edges between provenance entries | L | #29 ✅ | ✅ |
| [#18](https://github.com/fissible/verdict/issues/18) Audit Laravel AI dependency surface and undocumented assumptions | M | none | ✅ |
| [#11](https://github.com/fissible/verdict/issues/11) Tamper-evident evidence recorder via `fissible/attest` | M | `attest-laravel` `^1.0.0` ✅ | ✅ |
| [#48](https://github.com/fissible/verdict/issues/48) `CaseStatus::Pending` for cases blocked on unlanded dependencies | M | none | ✅ — displaced from v0.3.0 |
| [#38](https://github.com/fissible/verdict/issues/38) Document approval TTL sizing against worst-case latency | XS | none | ✅ |
| [#39](https://github.com/fissible/verdict/issues/39) Document confirmation-fatigue guidance | XS | none | ✅ |
| [#40](https://github.com/fissible/verdict/issues/40) Frame shared rate-limit buckets as a composition bound | XS | none | ✅ |
| [#41](https://github.com/fissible/verdict/issues/41) Add evidence-verification cadence to operational responsibilities | XS | none | ✅ |

**Why #48 belongs with this group.** #43 surfaced it, and the RAG pack shipped with a skipped case that
is exactly what `CaseStatus::Pending` exists to express. That same skipped case is what #29 and #30
unskip, so the three land naturally together.

**The four XS docs** are the entire remaining operational-guidance backlog, no dependencies, under an
hour each. Clearing them closes a category rather than leaving loose ends across future milestones.

**Why #29 and #30 next.** #50 answered the mechanism question and is closed, with the reasoning recorded
while it was fresh. Deferring past one more release means re-deriving it.

**#29 correlates at invocation granularity, which is the safe one.** `correlationId` is populated from
Laravel AI's per-generation `invocationId` (`VerdictProvenanceMiddleware.php:37`,
`RecordAgentPromptProvenance.php:31`, `RecordToolResultProvenance.php:28`) — not the tool-invocation id.
`$invocationId` is captured lexically in `listenForToolInvocations()` and stays correct under nesting, so
the clobber that dominated the #50 spike does not touch #29. The `toolCallId` half of that spike's
decision becomes load-bearing only if Verdict later wants *which tool call* produced a decision, which is
#30 territory at the earliest. Do not over-engineer #29's key.

Propagation has a precedent in-tree: `ApprovalExecutionContext` is a container singleton holding frames,
pushed by `VerdictApprovalMiddleware` around `$next($prompt)`. A sibling context pushed by
`VerdictProvenanceMiddleware` gives #29 automatic propagation with no application-side threading, and
reads `null` outside an invocation — exactly the semantics the issue requires.

Both issues carry an acceptance-criteria item to unskip the provenance case in
`tests/Unit/RagBorneInjectionAttackPackTest.php`. That skipped test is the worked example of what this
milestone delivers.

**Why #18 was pulled forward.** It was backlog until the `toolInvocationId` work made it concrete:
Verdict depended on an undocumented Laravel AI correlation assumption and found out by measuring
(laravel/ai#855). laravel/ai#848 will change that surface again. The audit is worth doing while the
findings are in hand, and it pairs with the `RELEASES.md` matrix refresh in v0.3.0.

**#11 is unblocked.** `fissible/attest-laravel` reached `v1.0.0`, so the beta constraint that held this
back is gone and the adapter can be built against a stable contract. Most of the complexity lives
upstream in `attest`/`attest-laravel`; this is a thin adapter plus tests. #41 (v0.3.0) strengthens and is
strengthened by it, but neither blocks the other.

The issue body still reads `^1.0.0-beta for now` and refers to `v0.2.0` milestone scope — both stale, and
worth correcting when the issue is picked up.

---

## v0.5.0 plan — Evidence identity and configuration *(shipped in `v0.4.0`)*

**Theme.** Make a decision record say *who*, *against what configuration*, and *through which adapter* —
the identity questions previously missing from `DecisionEvidence`.

| Issue | Effort | Deps | Status |
|---|---|---|---|
| [#32](https://github.com/fissible/verdict/issues/32) Record a capability configuration fingerprint | S | none | ✅ |
| [#34](https://github.com/fissible/verdict/issues/34) Expose a stable execution identity to executors | S | none | ✅ — `scope: design` resolved |
| [#31](https://github.com/fissible/verdict/issues/31) Record actor and subject identity in decision evidence | M | none | ✅ |
| [#33](https://github.com/fissible/verdict/issues/33) Add a content-addressed capability configuration registry | M | #32 ✅ | ✅ |
| [#15](https://github.com/fissible/verdict/issues/15) Make `GuardedTool` usage observable in evidence | — | none | ✅ Implemented by [#73](https://github.com/fissible/verdict/pull/73) |

This work shared one evidence surface and ADR cluster (0013, 0015, 0017, 0008) with the v0.4.0 group,
which is why coordinating them mattered — and, in the end, why they tagged together rather than forcing
two rounds of evidence serialization and upgrade changes on adopters.

---

## v0.5.0 — Live evaluation and harness documentation *(next)*

**Theme.** Make the evaluation subsystem usable by someone who did not build it.

| Issue | Effort | Deps | Status |
|---|---|---|---|
| [#51](https://github.com/fissible/verdict/issues/51) Provide a live agent runner and a command to run live evaluation | L | none | Open |
| [#49](https://github.com/fissible/verdict/issues/49) Document the evaluation harness and attack packs | M/L | #51 (partial) | ✅ |

Ordered, not parallel. #49's live-evaluation section could not be written honestly before #51 lands, and
its `CaseStatus` section changed once #48 shipped in `v0.4.0`. #49 closed ahead of #51 on the sections
that do not depend on the live runner; the live-evaluation section is #51's to complete.

#51 is the only open issue left in the scheduled plan.

---

## 1.0 readiness — unversioned backlog

Not scheduled. These gate a 1.0 tag rather than any particular minor, and several are `help wanted`.

**Concurrency assurance.** Currently `grep -rli concurrent tests/` returns zero matches; the atomicity
claims are untested against actual concurrency.

| Issue | Effort | Deps |
|---|---|---|
| [#37](https://github.com/fissible/verdict/issues/37) Determine required isolation level for security-state connections | M | none — blocks a follow-on ADR |
| [#20](https://github.com/fissible/verdict/issues/20) Add genuine concurrency tests for claims, rate limits, approvals | — | related #37 |
| [#16](https://github.com/fissible/verdict/issues/16) Benchmark concurrency for claims, rate limits, approvals | — | related #37 |

Take #37 first: it is a spike, and its answer shapes what #20 and #16 are even measuring.

**Stability and traceability.**

| Issue | Effort | Deps |
|---|---|---|
| [#17](https://github.com/fissible/verdict/issues/17) Audit and label public extension-contract stability | — | none |
| [#42](https://github.com/fissible/verdict/issues/42) Trace each documented guarantee and non-guarantee to a test | M | none |
| [#19](https://github.com/fissible/verdict/issues/19) Add consolidated ordering table and streaming/queued compatibility matrix | — | none |
| [#141](https://github.com/fissible/verdict/issues/141) Hydrate the attest evidence configuration into a typed value object | S | none — precedent in #91 |

#17 and #42 are the two that genuinely gate 1.0: one states which interfaces are load-bearing, the other
proves the documented guarantees are actually tested.

#141 is smaller and independent: it applies #91's value-object treatment to the config layer, where the
provider currently spends ~95 lines validating eight attest keys imperatively. It does not gate 1.0, but
it removes a documented-unreachable branch and makes the attest invariants structural rather than
enforced by a sequence of runtime throws.

**Unscheduled.**

| Issue | Effort | Deps | Note |
|---|---|---|---|
| [#22](https://github.com/fissible/verdict/issues/22) Extend `ApprovalExecutionContext` scope across streamed responses | — | none | Overlaps the streaming path touched by laravel/ai#848 |
| [#36](https://github.com/fissible/verdict/issues/36) Add a `verdict:validate` wiring audit command | S | #35 ✅ | Issue states priority **low** |

---

## Upstream dependency watch

See [`docs/laravel-ai-compatibility.md`](docs/laravel-ai-compatibility.md) for the full inventory of what
Verdict's `src/` actually depends on in Laravel AI's surface, classified by how likely each dependency is
to change without warning, and which tests would catch it (#18).

Verdict pins `laravel/ai: ^0.10.2`, which in Composer's pre-1.0 caret semantics is `>=0.10.2 <0.11.0`.

- **laravel/ai#848** (open) fixes the nested `toolInvocationId` clobber. It is minor-bump shaped, so it
  will likely ship in `0.11.0` and will not be picked up automatically. It has since grown into a stack
  (#870, #872, #873, #874, #875, #876) that adds `RunContext` and `ToolFailed` and makes `float $time` a
  required argument to `ToolInvoked` — a declared breaking change. **#130** tracks the widening as one
  reviewed pass, including the two hand-constructed `ToolInvoked` events in
  `tests/Feature/LaravelAiProvenanceTest.php`.
- `tests/Feature/ToolInvocationCorrelationTest.php` pins the *current, buggy* behaviour deliberately.
  When the constraint widens past #848, that test goes red. **That failure is the designed alarm, not a
  regression** — it means upstream correlation semantics changed and Verdict's evidence path needs review.
- Dependabot watches Composer weekly and will open the widening PR. Whichever milestone is open when that
  lands absorbs the compatibility review, per `RELEASES.md`.
- `.github/workflows/laravel-ai-canary.yml` runs PHPStan and the suite against `laravel/ai:0.x-dev` weekly,
  non-blocking. Dependabot's PR arrives when upstream *publishes*; the canary reports when upstream
  *merges*. That lead time is the point — it is what lets an issue blocked on an in-flight upstream stack
  be scoped against what actually landed rather than against an open draft. A red canary means upstream
  changed: work **#130**'s checklist, do not widen `composer.json` to make it green. The constraint is
  `0.x-dev`, not `dev-0.x`; Composer normalizes a branch name that already looks like a version.
