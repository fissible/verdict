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

## v0.5.0 — Live evaluation and harness documentation *(cut)*

**Theme.** Make the evaluation subsystem usable by someone who did not build it.

| Issue | Effort | Deps | Status |
|---|---|---|---|
| [#51](https://github.com/fissible/verdict/issues/51) Provide a live agent runner and a command to run live evaluation | L | none | ✅ — [#140](https://github.com/fissible/verdict/pull/140) |
| [#49](https://github.com/fissible/verdict/issues/49) Document the evaluation harness and attack packs | M/L | #51 (partial) | ✅ |

Ordered, not parallel. #49's live-evaluation section could not be written honestly before #51 lands, and
its `CaseStatus` section changed once #48 shipped in `v0.4.0`. #49 closed ahead of #51 on the sections
that do not depend on the live runner; the live-evaluation section is #51's to complete.

#51 landed the observer, the `verdict:evaluation-live` command, and one recorded run against a local
Ollama model. That run is a **single constrained observation, not a validation**: both thresholds
reported NOT MET, and `docs/evaluation.md` publishes it beside an earlier single-trial run of the same
model that produced the opposite security disposition. Running it for real surfaced three limitations
that reading the code did not — they are scheduled as `v0.6.0` rather than held against this tag,
since each is a design decision and `RELEASES.md` sanctions 0.x API refinement between minors.

Both issues in this plan are now closed.

---

## v0.6.0 — Live evaluation soundness *(next)*

**Theme.** Make a live evaluation result trustworthy enough to act on. Every issue here was discovered
by running #51's harness against a real model, not by reading the code.

| Issue | Effort | Deps | Status |
|---|---|---|---|
| [#139](https://github.com/fissible/verdict/issues/139) Distinguish an unattempted capability from a breached one | M/L | none | Open — `scope: design` |
| [#137](https://github.com/fissible/verdict/issues/137) Define suite lifecycle across live evaluation trials | M | none | Open — `scope: design` |
| [#138](https://github.com/fissible/verdict/issues/138) Decide how sample size affects a live evaluation verdict | M/L | #137 | Open — `scope: design` |

Ordered by how fast an adopter meets them. #139 is first because it fires on the very first live run:
`Assertions::toolDidNotExecute()` fails when the attacked capability is *absent* as well as when it
executed, so a model that reaches for a different tool fails a security case Verdict handled correctly —
across 12 call sites in all four packs. #137 next, because multi-trial rates are not independent
observations until it is settled, which is why #51's recorded run is a single trial. #138 depends on
#137: a sample-size floor counted in trials measures nothing while trials share state.

All three are `scope: design` — each needs a decision recorded before implementation, not just a patch.

---

## Contributor-ready — deliberately unscheduled

These carry `scope: ready` and are open to anyone. They are **not** attached to a milestone, and that is a
decision rather than an oversight: a milestone closes when its issues close, so scheduling unclaimed
volunteer work would make a release depend on strangers who may never start. Labels are the discovery
surface — `CONTRIBUTING.md` points contributors at `scope: ready`, and newcomers filter on
`good first issue`. Whatever lands before a tag ships in that tag, exactly as displaced scope did for
v0.4.0.

Ordered by suggested pickup order: defects first, then self-contained work with a visible result.

| Issue | Effort | Label | Deps |
|---|---|---|---|
| [#143](https://github.com/fissible/verdict/issues/143) Publish PostgreSQL and MariaDB arms of the security-state benchmark | S | `good first issue` | none — compose services already exist |
| [#142](https://github.com/fissible/verdict/issues/142) Collapse the repeated store/connection/table triple into a shared value object | XS | `good first issue` | none — carved out of #141 |
| [#146](https://github.com/fissible/verdict/issues/146) Warn from `verdict:validate` when a non-durable adapter is configured outside local | S | `good first issue` | none |
| [#147](https://github.com/fissible/verdict/issues/147) Write a worked incident-response walkthrough over the evidence tables | M | `help wanted` | none — the v0.4.0 chain is complete |
| [#144](https://github.com/fissible/verdict/issues/144) Prove the in-memory stores agree with the database stores | M | `help wanted` | none |
| [#148](https://github.com/fissible/verdict/issues/148) Check in pack baselines and fail CI on unexplained regressions | M | `help wanted` | none |
| [#145](https://github.com/fissible/verdict/issues/145) Add a delegation attack pack for actor-versus-subject confusion | M | `help wanted` | #31 ✅ |
| [#151](https://github.com/fissible/verdict/issues/151) Harden field-path handling in the release path | M | `help wanted` | none — #150 shipped in v0.5.0 |
| [#152](https://github.com/fissible/verdict/issues/152) Decide and enforce the binding fingerprint canonicalization contract | M | `scope: ready` | none |
| [#141](https://github.com/fissible/verdict/issues/141) Hydrate the attest evidence configuration into a typed value object | S | `scope: ready` | none — precedent in #91 |

**#151 and #152 are the hardening that remained open after the same audit.** #149 and #150 were fixed in
v0.5.0: neither was an authorization bypass, but both were asymmetries that were cheap to fix and
expensive to explain later. #151 and #152 continue that hardening around field-path handling and binding
fingerprint canonicalization.

[#153](https://github.com/fissible/verdict/issues/153) was settled in v0.5.0: a failed evidence write now
dispatches `EvidenceWriteFailed` and execution continues, leaving the operational gates — rate-limit
consumption, approval consumption, and execution-claim admission — as the only authorization decisions.
The outcome differs by gate: a rate-limit unit can self-heal, while an admitted execution claim that
cannot be finalized blocks its binding indefinitely. See ADR 0007 and the v0.5.0 changelog.

**#147 is the one worth finishing soonest.** v0.4.0 completed the forensic chain — invocation correlation,
derivation edges, actor and subject identity, configuration fingerprints, optional tamper-evidence — and
every piece is documented where it was built, nowhere end to end. It is the document a prospective adopter
opens to decide whether any of this is real.

**#143 closes a gap between what is claimed and what is published.** ADR 0018's retry policy exists because
of how PostgreSQL reports serialization failures at COMMIT. That behavior is tested, but the benchmark table
covers SQLite and MySQL only.

**#142 and #141 must not collide.** #141 owns the `evidence.attest` block, which has real invariants; #142
owns the four repeated store sections, which have none. Whoever takes the second should rebase on the first.

---

## 1.0 readiness

Nothing is scheduled, and nothing is listed here on purpose.

Every issue this section previously named — the concurrency spike and its tests and benchmarks, the
extension-contract stability audit, the guarantee-to-test traceability sweep, the ordering and
compatibility matrix — is closed. Listing closed work as a backlog made the document say the opposite of
what was true.

What replaces it is not a shorter list. Verdict's remaining 1.0 questions are the ones only real
applications can ask: which contracts turn out to be load-bearing in an integration nobody here designed,
which documented guarantee turns out to be worded more strongly than the implementation supports, and which
upgrade path hurts. Those become issues when someone hits them, not before. Inventing them now would
produce a backlog that measures imagination rather than adoption.

The 1.0 bar itself is unchanged and stated in [`RELEASES.md`](RELEASES.md): stable documented contracts, an
explicit Laravel AI compatibility strategy, upgrade-safe migrations, real-application feedback, and no known
silent bypass within the supported integration paths.

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
