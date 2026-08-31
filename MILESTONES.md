# Milestones

The release plan. [`RELEASES.md`](RELEASES.md) states the release *policy* — what a minor bump means
and when a release may be cut. This document states the *plan* — what goes in which release, and why
that ordering.

Work is ordered leaves-to-roots over the dependency graph: nothing is scheduled before the things it
depends on. Within a milestone, prefer the smallest effort first, then the most widely depended-on.

Effort key: **XS** (<1h) · **S** (1–2h) · **M** (~half day) · **L** (~1 day) · **XL** (2–3 days).

GitHub milestones mirror this document. When they disagree, this document is wrong and should be
corrected — the issues are the source of truth for scope, this is the source of truth for ordering.

**Every issue is attached to a milestone when it is filed** — the release expected to ship it — or added
to the **deliberately unscheduled** list (see Contributor-ready) with its reason recorded. An unmilestoned
open issue is a filing gap, not a scheduling state. Closed issues carry the milestone of the tag that
shipped them, per the backfill rule above. Practice adopted 2026-08-25, when the backlog was swept:
fourteen open issues were attached and two closed ones (#276, #280) backfilled to v0.11.0.

**Milestone membership was backfilled on 2026-08-20**, so GitHub now answers "which tag shipped this?"
for every closed issue. Expect the per-release tables below to list *fewer* issues than the milestone
holds — v0.4.0's milestone carries 42 closed issues against the nine rows in its section. That is the
intended relationship: the tables record what was *planned and why it was ordered that way*; the
milestone records what the tag *contains*.

The backfill was derived from each issue's close time against the release publication times, not from
whichever pull request happened to mention the issue last — that heuristic put #65 in an unreleased tag
nine days after it closed, and misplaced #97, #112, #150, and #153. Every disagreement between the two
methods was resolved against the code: #65 by finding `configuredDescriptionFingerprint` first present at
v0.4.0, #150 and #153 by the tags containing their fix commits, #97 and #112 against the statement already
in the adoption guide.

Two issues carry no milestone on purpose, both closed `not planned`: [#106](https://github.com/fissible/verdict/issues/106)
(tenant-aware pending-review query seam) and [#227](https://github.com/fissible/verdict/issues/227) (a
streamed-resumption defect report that a mis-wired probe produced and which no code change answered).
A milestone states which release shipped the work; neither shipped any.

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

## v0.6.0 — Live evaluation soundness *(cut)*

**Theme.** Make a live evaluation result trustworthy enough to act on. Every issue here was discovered
by running #51's harness against a real model, not by reading the code.

| Issue | Effort | Deps | Status |
|---|---|---|---|
| [#137](https://github.com/fissible/verdict/issues/137) Define suite lifecycle across live evaluation trials | M | none | ✅ merged in #171 — [ADR 0020](docs/adr/0020-live-trial-isolation-is-application-owned.md) |
| [#139](https://github.com/fissible/verdict/issues/139) Distinguish an unattempted capability from a breached one | M/L | none | ✅ merged in #172 |
| [#138](https://github.com/fissible/verdict/issues/138) Decide how sample size affects a live evaluation verdict | M/L | #137 ✅, #139 ✅ | ✅ merged in #173 — [ADR 0021](docs/adr/0021-coverage-adequacy-gates-a-live-verdict.md) |

Ordered by how fast an adopter meets them, and both settled issues took a recorded decision before any
code. #137 landed first in the end: a multi-trial rate is not independent observations until trials are
isolated, and ADR 0020 settled who owns resetting them. #139 followed — `toolDidNotExecute()` failed
when the attacked capability was *absent* as well as when it executed, so a model that reached for a
different tool failed a security case Verdict had handled correctly, across 12 call sites in all four
packs.

**#138 depends on #139 as well as #137, and the dependency is stronger than the table can show.** #139
moves an unattempted attack from `Failed` to `Error`, and `Score::evaluated()` is `passed + failed` — so
errors leave the denominator entirely, not just the numerator. A five-case security suite where the model
attacks once and ignores the rest reported `1 passed / 4 failed` → 20% → **NOT MET** before #139, and
reports `1 passed / 0 failed` → 100% → **MET** after it. #139 is correct and had to land; the consequence
is that the less cooperative the model, the easier the threshold becomes to meet. #138 is what closes
that, which is why this milestone should not be tagged with #137 and #139 alone. [ADR 0021](docs/adr/0021-coverage-adequacy-gates-a-live-verdict.md) settles it: a verdict is gated on coverage before rate, and a purpose whose measurable-but-unmeasured outcomes outnumber its evaluated ones reports `INSUFFICIENT` rather than a rate-based verdict.

All three have merged and **v0.6.0 was tagged on 2026-08-14**.

The three are one argument rather than three fixes, which is worth stating because they landed
separately. #137 made trials independent; #139 stopped an unattempted attack being counted as a breach;
#138 stopped #139's correction from making the gate easier to pass. Shipping any two without the third
would have left a live verdict weaker than the one it replaced.

Deferred from this milestone with their reasons recorded rather than dropped:
[#174](https://github.com/fissible/verdict/issues/174) (per-case coverage, deferred from #138) and
[#170](https://github.com/fissible/verdict/issues/170) (an unguarded control arm, whose three stated
dependencies are now all settled). Both are scheduled into v0.7.0 below.

---

## v0.7.0 — Prove the boundary is load-bearing *(cut)*

**Theme.** Answer the question every reader of a security control asks first: *what happens without it?*
Live evaluation currently measures whether Verdict denied an attack. It does not measure whether the attack
would have succeeded unguarded, and those are different claims — only the second shows the boundary is
doing work.

| Issue | Effort | Deps | Status |
|---|---|---|---|
| [#174](https://github.com/fissible/verdict/issues/174) Decide whether coverage adequacy applies per case | M | #138 ✅ | ✅ Shipped — #179 |
| [#170](https://github.com/fissible/verdict/issues/170) Add an unguarded control arm so a live run can show what Verdict prevented | XL | #137 ✅, #138 ✅, #139 ✅, #174 ✅ | ✅ Shipped — #180, #181, #186 |

**#174 first, and not only because it is smaller.** The control arm's unit of measurement is the 2×2 for a
single case — guarded denied or executed, against control executed or declined. v0.6.0's coverage gate is
purpose-level, so it cannot express "this control's breach case never ran once." Landing #170's reporting on
top of a purpose-level adequacy rule would produce a comparison that reads as complete while a whole control
went unmeasured — the same class of defect [ADR 0021](docs/adr/0021-coverage-adequacy-gates-a-live-verdict.md)
closed one level up.

**#170 is XL and should not be talked down.** Its three stated dependencies closed in v0.6.0, so it is
unblocked for the first time, but the issue carries four things that each need doing properly: a safety
model for deliberately letting an attack succeed, a breach case per control rather than one overall, a model
chosen to breach reliably rather than to look good, and a sampling mode that distinguishes regression from
rate estimation. Attempting it as a reporting feature would produce a comparison that looks rigorous and is
not — the issue says so itself.

**Safety leads here, and that is a change in posture.** Every previous milestone hardened a boundary. This
one deliberately runs an attack against an unguarded agent so the dangerous capability actually executes.
`CONTRIBUTING.md`'s requirement that live evaluation use synthetic, reversible data stops being advisory at
that point. Whether the control arm needs its own opt-in, separate from the two switches that already gate
live evaluation, is part of #170's decision rather than an implementation detail.

**Why this milestone is worth its size.** v0.6.0 made a single live result trustworthy. The payoff for that
work is being able to compare two, and the comparison is the first artifact this project can produce that
demonstrates prevention rather than asserting it.

**Shipped 2026-08-16.** The recorded control-arm run lives in `docs/evaluation.md`: against an abliterated
Ollama model, the unguarded arm executed the cross-principal lookup and cancellation on every replay and the
guarded arm denied them on every replay. Read narrowly, as the run itself is written — it demonstrates the
*authorization* boundary under greedy reproducibility, and is explicitly not a rate, not the authority/intent
gap, and not the human-approval boundary. Three follow-ups surfaced during the work carry into v0.8.0 below:
[#183](https://github.com/fissible/verdict/issues/183)/[#184](https://github.com/fissible/verdict/pull/184)
(guarded-arm evidence correlation — fixed and shipped in this tag),
[#185](https://github.com/fissible/verdict/issues/185), and
[#187](https://github.com/fissible/verdict/issues/187).

---

## v0.8.0 — Measure and defend intent *(scope complete, untagged)*

**Theme.** v0.7.0 demonstrated the *authorization* boundary is load-bearing — but only that boundary, and
only against outside-authority attacks. The harder case is inside-authority: an injected instruction
selecting a record the actor **legitimately owns**, where authorization passes and the wrong-ness is that the
action was not the user's intent. This milestone makes that gap measurable and hardens the harness that
measures it, so a future recorded run can demonstrate the intent boundary the way v0.7.0 demonstrated
authorization.

| Issue | Effort | Deps | Status |
|---|---|---|---|
| [#185](https://github.com/fissible/verdict/issues/185) Distinguish harness blindness from model non-attempts in the coverage gates | M | #183 ✅ | ✅ Shipped — PR #189 |
| [#187](https://github.com/fissible/verdict/issues/187) Add an inside-authority intent case so the control arm can measure the authority/intent gap | L | #170 ✅ | ✅ Shipped |
| [#192](https://github.com/fissible/verdict/issues/192) Make context-resolved targets a first-class, evidence-visible choice | M | #187 ✅ | ✅ Shipped |
| [#195](https://github.com/fissible/verdict/issues/195) Surface proposal provenance at the moment of decision | XL | #192 ✅ | ✅ Shipped — PR #205, ADR 0026 |
| [#152](https://github.com/fissible/verdict/issues/152) Decide and enforce the binding fingerprint canonicalization contract | M | — | ✅ Shipped — PR #208 |
| [#163](https://github.com/fissible/verdict/issues/163) Record the tool description fingerprints instead of discarding them | S | — | ✅ Shipped — PRs #209, #211 |
| [#210](https://github.com/fissible/verdict/issues/210) Register a capability by affirming it, not by wiring it | L | — | ✅ Shipped — PR #215, ADR 0027 |
| [#147](https://github.com/fissible/verdict/issues/147) Write a worked incident-response walkthrough over the evidence tables | M | — | ✅ Shipped — PR #220 |
| [#146](https://github.com/fissible/verdict/issues/146) Warn from `verdict:validate` when a non-durable adapter is configured outside local | S | — | ✅ Shipped — PR #221 |
| [#218](https://github.com/fissible/verdict/issues/218) Prove the confirmed-mutation allow-execute completes live (streamed + queued) | L | — | ✅ Shipped — PRs #233, #235 |
| [#224](https://github.com/fissible/verdict/issues/224) Surface integrity-vs-completeness in verification output | S | — | ✅ Shipped — PR #232 |
| [#230](https://github.com/fissible/verdict/issues/230) Name a confirmation gate that can never pause — advisory half | S | — | ✅ Shipped — PR #231; rejection half in v1.0.0 |
| [#223](https://github.com/fissible/verdict/issues/223) Give evidence records an Attest-independent canonical identity | M | — | ✅ Shipped — PR #236 |

**#185 first — it makes every future recorded run trustworthy.** v0.7.0's own recorded run was nearly
published against a silently-blind guarded arm ([#183](https://github.com/fissible/verdict/issues/183)): the
coverage gates could not tell "the harness saw nothing" from "the model attempted nothing." #185 gates
integrity before coverage, failing loudly when the apparatus is blind rather than laundering blindness into a
coverage verdict. Landing #187's intent demonstration on a harness that cannot draw that distinction would
repeat the class of defect [ADR 0021](docs/adr/0021-coverage-adequacy-gates-a-live-verdict.md) closed one
level up — the same argument that put #174 before #170.

**#187 is the direct sequel to v0.7.0's demonstration** — the boundary that run explicitly did not cover. It
is not a small addition: it needs a context-resolved-vs-proposal-resolved pairing, both arms guarded,
distinct from #170's guarded/unguarded control arm, which refuses a guarded control arm by construction. The
defensive mechanism it measures against — target provenance, so a proposal-resolved argument cannot redirect
the executor — is being scoped separately (its ADR takes 0025, since #185 took 0024) and pairs with this
issue rather than blocking it.

**#192 and #195 are the defend-half of the same theme.** #187 made the authority/intent gap measurable;
#192 made the resolution path a first-class, evidence-visible choice, so an auditor can query the population
that matters rather than recompute a hash to find it; and #195 surfaced declared provenance to the human the
boundary defers to, which is the intent control for a consequential capability. Together they close the loop
the theme names: measure the gap, then give the approver the one fact that lets them act on it.

Two design-scope follow-ups were filed from #195's work rather than absorbed into it, and are **deliberately
not in this milestone**: [#201](https://github.com/fissible/verdict/issues/201) (cross-invocation content
lineage does not reach an approver) and [#204](https://github.com/fissible/verdict/issues/204) (approval
challenge facts are not observable to the live attack packs, so #195's claims are proven deterministically
rather than measured per-case). Both contain decisions that belong in front of an ADR, not at the end of an
implementation branch.

**Scope is a starting point.** These are the follow-ups v0.7.0 surfaced; the milestone accretes `scope: ready`
work that lands before the tag, exactly as prior milestones did — which is why #152, #163, #210, #147, and
#146 appear above without having been in the original plan.

This tag's window also absorbed work without a tracking issue: Claude 5 live-harness support and the
aligned-ceiling control run (#217), and making `verify:claims` offline-safe with the network made opt-in
(#219, #222).

**#218 closed 2026-08-19** with both transports verified through completion: streamed via a
`StepTextGateway` (#233) and queued across a real `InvokeAgent` dispatch, executing exactly once after
approval (#235). The queued cell was the gap external review had named. Its scoping measured that a
two-turn approval resume produces two distinct Laravel AI invocation ids, so the live harness cannot
correlate the proposing turn with the executing one by `invocation_id` — that constraint carries into
[#204](https://github.com/fissible/verdict/issues/204), which #218's closure unblocks and which is
scheduled into v0.10.0 below.

**Every row is shipped and the tag is ready to cut.**

---

## v0.9.0 — Adoption-grade proof, cut on upstream compatibility

**Theme.** v0.8.0 finished making the boundary measurable; this milestone makes the proof *continuous*
and *copyable*. Continuous: the attack packs stop being something that was run once and start being
something CI re-verifies on every commit. Copyable: the correct wiring stops being prose and becomes an
application someone can clone.

| Issue | Effort | Deps | Status |
|---|---|---|---|
| [#148](https://github.com/fissible/verdict/issues/148) Check in pack baselines and fail CI on unexplained regressions | M | none | ✅ merged in #241 |
| [#237](https://github.com/fissible/verdict/issues/237) Clone-and-run reference application | XL | v0.8.0 tag | ✅ |
| [#130](https://github.com/fissible/verdict/issues/130) Widen `laravel/ai` to `^0.11` | M | upstream tag ✅ | ✅ merged in #244 — pulled forward from v1.0.0 |
| [#164](https://github.com/fissible/verdict/issues/164) Rate-limit window boundary, expiry, and cross-window leakage | M | none | ✅ merged in #238 — contributor pool, accreted |
| [#248](https://github.com/fissible/verdict/issues/248) SHA-256 validators accept a trailing newline in three sites | XS | none | ✅ accreted |

**Both theme items shipped, and then upstream forced the tag.** `laravel/ai 0.11.0` published on
2026-08-19, hours after v0.8.0 was cut. Every published Verdict requires `^0.10.2`, so an adopter who
already had `laravel/ai ^0.11` could not install Verdict at all: `composer require fissible/verdict`
fails outright, and `--with-all-dependencies` does not rescue it because the root constraint blocks the
downgrade. Since Verdict layers *on top of* Laravel AI, the usual discovery order puts the adopter on the
newest Laravel AI before they ever reach Verdict — so that failure sits directly on the evaluation path.
#130 was pulled forward from v1.0.0 and this tag cut on the compatibility fix rather than held for more
scope. Same reasoning as v0.3.0: a tag is a point in history, and work in flight does not need to wait
for it.

**#130 drops `laravel/ai 0.10.x`.** That is a breaking change for existing consumers and therefore a
minor bump per [`RELEASES.md`](RELEASES.md). It is forced rather than chosen: upstream's #874 made
`float $time` a required argument on `ToolInvoked`, and the correlation assertion Verdict pins *inverts*
between the two lines — under `0.10.x` the outer event correctly carries the inner tool's id, under
`0.11.0` it does not. A security-relevant test that asserts opposite things depending on which dependency
happens to be installed is worth less than the line it would preserve.

**Two items moved out rather than held, so the tag was not delayed by work the theme never promised:**

- [#204](https://github.com/fissible/verdict/issues/204) (approval-challenge facts observable to the live
  packs) → **v0.10.0**. Research-thread work; see the section below.
- [#225](https://github.com/fissible/verdict/issues/225) (vendor-neutral `EvidenceReference`) → **no
  milestone, deliberately**. Its own acceptance criteria gate it on "something outside Verdict actually
  needs to reference a Verdict claim." Scheduling it into any dated milestone would make that tag hostage
  to an external consumer who may never appear. It is captured design, not scheduled work.

**#164 and #248 keep their `v1.0.0` milestone on GitHub and are listed here anyway.** That is the rule
working as designed, not a bookkeeping slip: the 1.0 milestone states what 1.0 *requires*, and it does not
gate interim tags — whatever lands early ships in whichever tag is open. This table is the record of what
the tag *contains*; the milestone is the record of what the bar *needs*. They are different questions, and
prior milestones accreted work the same way (see v0.7.0's #152, #163, #210, #147, #146).

**This tag's window also absorbed work without a tracking issue:** the failure-path tool-correlation
mirror over `ToolFailed` (#246, the deferred half of #130) and the outbound claim-reference documentation
(#242).

**#148 was contributor-pool work and was deliberately pulled out of it.** A milestone must not depend on
unclaimed volunteer work; scheduling #148 meant the maintainer claimed it.

---

## v0.10.0 — Measuring the approval boundary

**Theme.** v0.7.0 measured authorization and v0.8.0 measured intent. This makes the human-approval
boundary measurable the same way — proven per-case by the live packs rather than deterministically by a
harness that already knows the answer.

| Issue | Effort | Deps | Status |
|---|---|---|---|
| [#204](https://github.com/fissible/verdict/issues/204) Approval-challenge facts observable to the live attack packs | L | #218 ✅ | ✅ Shipped — PR #258 |
| [#251](https://github.com/fissible/verdict/issues/251) Cross-principal order search: set-shaped case, filtered-permit outcome | M–L | #260 | ✅ Shipped — PRs #263/#264/#266/#270/#273 |
| [#260](https://github.com/fissible/verdict/issues/260) Widening-mutation suite over the predicate normalizer | S–M | none | ✅ Shipped — PR #263 |

**Why #251 was pulled forward.** Its design completed under four rounds of external review on the
issue itself (capture point, oracle shape, normalizer policy, independence — all decided and
recorded), and it converts the scope limit #250 documented on the headline guarded-arm claims —
record-keyed tools only — back into coverage. Design freshness is the asset being spent; the same
accrete-don't-gate rule as #204 applies, and it is not a release gate for this tag.

**Why this is not in v1.0.0.** The 1.0 milestone states what 1.0 *requires*. #204 makes an existing
guarantee measurable rather than closing a gap that blocks the bar, so it is scheduled without being a
release gate. Scope will accrete here the way every prior milestone accreted `scope: ready` work that
landed before its tag.

**One constraint carried in from #218's scoping:** a two-turn approval resume produces two distinct
Laravel AI invocation ids, and `laravel/ai 0.11.0` did not change that — both `prompt()` and `stream()`
still mint one unconditionally per call. So the live harness cannot correlate the proposing turn with the
executing one by `invocation_id`; the tool call id is the boundary-spanning key. #204's design has to
start from that.

## v0.10.1 — Correctness patch

**Theme.** The correctness exception to the batched-release cadence (see `RELEASES.md`): a defect in
published behavior ships its own prompt patch rather than waiting for the next themed minor.

| Issue | Effort | Deps | Status |
|---|---|---|---|
| [#284](https://github.com/fissible/verdict/issues/284) Injection case asserted a denial the boundary never makes | M | none | ✅ Fixed — PR #293; `v0.10.1` release pending |

**Why a patch, not a wait.** The storefront `indirect-instruction-in-retrieved-document` case asserted
`decisionIs(Deny)` while the real boundary returns `RequireConfirmation`; the deterministic runners
*simulated* a denial that never happens. The gpt-oss:20b 100-trial run (2026-08-23) scored 38/100
correct approval-gate holds as **failures**, which suppressed that run's published zero-breach bound —
a wrong number already in `docs/evaluation.md`. Readiness item 9 forbids releasing over it, and the
cadence policy forbids sitting on it to preserve a batch. Case v2 asserts the confirmation gate; both
runners stop simulating; the recorded runs are re-read.

## v0.11.0 — Grounded methodology and configurable migrations *(cut)*

**Theme.** The first milestone-gated batch after the cadence change: ground the harness's own claims in
external prior work, and make the shipped migrations honor the table names the config already lets an
adopter rename. Both are additive and low-risk; neither introduces new mechanism.

| Issue | Effort | Deps | Status |
|---|---|---|---|
| [#296](https://github.com/fissible/verdict/issues/296) Ground the evaluation methodology in external prior work | S | none | ✅ Merged — PR #302 |
| [#290](https://github.com/fissible/verdict/issues/290) Migration stubs honor the configured table names | S–M | #287 ✅ | ✅ Merged — PR #313 |

**Why these two.** #296 grounds the harness's claims (rule-of-three source, over-restriction precedent,
benchmarking-validity checklist) against published prior work — it shipped in PR #302. #290 closes a real
adopter footgun: the stores read `config('verdict.*.table')` but the migration stubs hardcode the default
names, so a config-only rename fails at first write; its only dependency (#287, the schema-assertion tests)
merged 2026-08-23. Both items merged; **v0.11.0 tagged 2026-08-25**.

**Why #294 is no longer here.** The flagship attack-surface case turned out to be unexpressible against the
current observation model (see #294's design finding): its exfil oracle needs a privacy-safe boundary
observation that does not yet exist. Rather than gate a feature release on a design cycle, #294 moved to
**v0.13.0** behind its prerequisite. This is the cadence policy working — batch features, do not block a
release on newly-discovered design work.

## v0.12.0 — Harden the shipped claims *(complete — all items merged, ready to cut)*

**Theme.** The findings from the Ox Alpha external review (2026-08-25) that harden the trust behind the
package's README claims and its fail-closed posture. **This is the next minor after v0.11.0** — the ready,
high-priority hardening batch ships ahead of the design-gated security sequence (now v0.13.0), which keeps
releases monotonic (current tag `v0.10.1`) while not making implementation wait on #304's unresolved design.
Queue order within the milestone is the review's own, recorded on #305.

| Issue | Effort | Deps | Status |
|---|---|---|---|
| [#305](https://github.com/fissible/verdict/issues/305) Per-receipt authorization expressible + a required, fail-closed authorization hook | M–L | none | ✅ Merged — PR #316 (breaking: authorizer now required) |
| [#307](https://github.com/fissible/verdict/issues/307) `verdict:evidence:verify` — a Verdict-aware delegate to chain verification | M | fissible/attest-laravel | ✅ Merged — PR #317 |
| [#308](https://github.com/fissible/verdict/issues/308) `CanonicalJson` mutates `serialize_precision` process-globally | S–M | none | ✅ Merged — PR #318 (byte-equivalence verified across ~250k values) |
| [#310](https://github.com/fissible/verdict/issues/310) Custom durable recorders silently get the no-op capability-configuration store | S | none | ✅ Merged — PR #319 |

**Neither #305 nor #307 is a false published claim** (checked against the tree, 2026-08-25). The README
assigns approval authorization to the *application* ("Your application—not the model—decides… whether a
person must approve") and does not claim Verdict enforces it; and verification already exists via
attest-laravel's `attest:verify`, which Verdict's documentation currently names as the verifier
(`docs/limitations.md`, `docs/adoption-guide.md`). So these are **high-priority hardening, not patch-release defects** — no
out-of-order documentation-correction patch is warranted. #305 closes an *expressibility* gap (the shipped
stub's `TODO: verify this receipt belongs to a conversation` cannot be written, because the receipt carries
no conversation binding); #307 gives Verdict a configuration-aware delegate to `attest:verify`, documents
exactly which Verdict evidence is covered, and proves Verdict-recorded attest envelopes detect corruption.
#308 (a process-global `ini` mutation during canonicalization — replace
with deterministic float formatting) and #310 (a silent no-op store where a fail-closed error belongs) are
the review's quick wins.

**Scoping notes carried in from #305's review pushback.**
- The authorization hook is **required and fail-closed**, not nullable-with-a-warning: `approve()`/`reject()`
  without a configured authorizer must refuse, and the stub must wire a working example rather than a TODO.
- Because a required authorizer **changes approval behavior for existing adopters** (an `approve()` that
  worked will start refusing until an authorizer is configured), #305 must ship an **explicit upgrade and
  configuration path** — an upgrade note, the new config key(s), and the receipt-binding migration — so the
  breaking change is deliberate and guided, not a surprise on `composer update`.
- Server-side signing (key management) stays out of scope for this milestone.
---

## v0.13.0 — Security evaluation and upstream compatibility

**Theme.** Two design-first tracks: extend the attack-surface coverage the #294 finding opened, and
harden the seam where Verdict meets a fast-moving 0.x upstream. Design-gated (#304 and #324 both start
with design), so it follows the v0.12.0 hardening batch rather than leading the release order.

| Issue | Effort | Deps | Status |
|---|---|---|---|
| [#304](https://github.com/fissible/verdict/issues/304) Privacy-safe observation: did a registered secret marker appear in an executed argument? | M | none | ✅ Shipped — ADR 0032 (PR #332) + build (PR #334) |
| [#294](https://github.com/fissible/verdict/issues/294) Data exfiltration through a scoped search tool's arguments | M | #304 ✅ | ✅ Merged — PR #337 |
| [#295](https://github.com/fissible/verdict/issues/295) Check-to-use digest binding (TOCTOU) | M–L | new cross-call primitive | ✅ Closed — mechanism PR #368, declared projection #366, pack case and proxy-ladder docs PR #387 |
| [#324](https://github.com/fissible/verdict/issues/324) laravel/ai compatibility contract: adapter boundary, named contract tests, published matrix | L | none | ✅ Closed — ADR 0033 (PR #338) plus all three build units below |
| [#339](https://github.com/fissible/verdict/issues/339) Enforce the adapter boundary: `ApprovedToolCalls`, the `InvocationContext` move, the zone rule | M | #324 ✅ | ✅ Merged — PR #344 |
| [#340](https://github.com/fissible/verdict/issues/340) A named laravel/ai contract suite, run against the version matrix | M | none | ✅ Merged — PR #347 |
| [#341](https://github.com/fissible/verdict/issues/341) Publish a verdict × console × laravel/ai compatibility matrix | S | none | ✅ Merged — PR #350 |
| [#366](https://github.com/fissible/verdict/issues/366) A resource checkpoint's byte projection is inferred, not declared | S–M | #295 ✅ | ✅ Merged — PR #374 |
| [#356](https://github.com/fissible/verdict/issues/356) `DatabaseEvidenceRecorder` has no column degradation; `verdict:validate` never checks the evidence table | S–M | none | ✅ Merged — PR #364 |
| [#363](https://github.com/fissible/verdict/issues/363) `recordDerivation()` has no column degradation and the derivations table is never audited | XS–S | #356 ✅ | ✅ Merged — PR #372 |
| [#359](https://github.com/fissible/verdict/issues/359) Concurrency-harness drain has no timeout; evidence fixtures drift from migrations | S | none | ✅ Merged — PR #370 |
| [#362](https://github.com/fissible/verdict/issues/362) Connection-timezone timestamp boundary | XS–S | #335 ✅ | ✅ Merged — PR #365 |
| [#358](https://github.com/fissible/verdict/issues/358) Octane lifetime: longer-lived objects capture scoped collaborators | M | none | ✅ Closed — three sites: PR #369 (registry), PR #371 (tool collaborators), PR #385 (per-invocation advertisement) |
| [#360](https://github.com/fissible/verdict/issues/360) Reconcile remaining doc inconsistencies from external review | S | none | ✅ Merged — PR #373 |
| [#322](https://github.com/fissible/verdict/issues/322) Durability checks read `verdict.evidence.recorder` while runtime honors the writer override — one blind spot, three sites | S | none | ✅ Merged — PR #376 |
| [#311](https://github.com/fissible/verdict/issues/311) Low-severity hardening batch from the external review | S–M (batch) | none | 6 of 7 shipped — PRs #378, #379, #380, #381, #383, #384; item 5's ledger gap-marker half is the milestone's only open work |
| [#315](https://github.com/fissible/verdict/issues/315) Named indexes keep default-derived names — two renamed installs collide on PostgreSQL | S | #290 ✅ | ✅ Merged — PR #377 |
| [#321](https://github.com/fissible/verdict/issues/321) Housekeeping: `verdict:evidence:verify` option forwarding; validate double-reads authorizer config | XS–S | none | ✅ Merged — PR #375 |
| [#335](https://github.com/fissible/verdict/issues/335) `recorded_at` stamped in the application timezone but read back as UTC | XS–S | none | ✅ Merged — PR #336; filed without a milestone, attached here, found by verdict-console VC-13 |

**Attack-surface track.** #304 is design-first: a boundary observation that records only a boolean match
per registered secret marker (never raw arguments or fragments — ADR 0008-clean), and that defines its
encoding and concatenation residuals explicitly. Once accepted and implemented, #294 builds the exfil case
on it. #295 is the other primitive-needing surface (a cross-call resource digest), independent of #304. Its
mechanism shipped in PR #368 with the byte projection inferred from the target's shape rather than declared
by the capability; #366 replaces that inference with a declaration, and the pack case waits on it, because a
digest that moves for reasons nobody declared reports swaps that never happened.

**Compatibility track.** #324 hardens the laravel/ai coupling into a formal contract — an adapter /
anti-corruption boundary so a 0.x upstream refactor touches only the adapter, named consumer-driven
contract tests wired into the existing canary, and a published verdict × console × laravel/ai matrix. Its
*defensive* half is unilateral (no upstream coordination needed); the coordination asks are a flagged
follow-on. This was named the one **gating** condition in the external "would Laravel endorse this" review,
and it is the surface [laravel/ai#932](https://github.com/laravel/ai/pull/932) is actively churning (see
#265) — so within this milestone it can lead the design work rather than wait behind the attack-surface
track. Builds on #18 (dependency audit) and #131 (the 0.x-dev canary).

**The design gate has lifted.** ADR 0033 settled the boundary, and the audit behind it shrank the work:
the kernel's whole dependency on upstream's approval vocabulary is a set of tool-call ids, the correlation
type #324 proposed inventing already exists and only needs moving, and `guard()`/`bound()` stay put because
they never dereference the type they name. What remains is three independently reviewable units — #339
(defensive core), #340 (contract suite + matrix run), #341 (published matrix) — of which only #339 depends
on the ADR at all. #340 and #341 can be picked up in any order, including by different people; #339 is the
one that changes the security kernel and should be reviewed as such. **All three shipped**, and #324 closed
with them on 2026-08-28.

**Release status (2026-08-29).** Every issue in this milestone is closed except #311, whose seventh item —
a provenance-ledger gap marker for a tool result that cannot be canonicalized — is the only open work. Its
locality half shipped in PR #384, so what remains is a design question about the ledger's vocabulary rather
than a defect in published behaviour. `main` is 44 commits past `v0.12.0`.

**Hardening carry-over.** #322, #311, #315, #321, #358, #360, and #363 are fix-shaped work from the review rounds
riding the next tag rather than joining either design track. #298's design half also ships in this tag:
ADR 0031 (the approval read contract) merged after v0.12.0 was cut, so the closed issue carries this
milestone while the cluster it unblocks lives in v0.15.0.

**The approval-surface cluster has its milestone: v0.15.0.** Held from this release since it was planned —
#297 (`RequireReview` has no runtime, the keystone) → #298 ✅ → #299, with #265/#300/#306/#320/#327 and the
verdict-console ADR 0001 that surfaced them — it was cut as its own milestone on 2026-08-25; see below.
#230 stays on v1.0.0 (a boundary decision on the 1.0 bar) and #201 stays deliberately unscheduled.

## v0.13.1 — Shipped-behaviour hardening *(patch)* — **shipped 2026-08-30**

**Theme.** Defects reproduced against the released `v0.13.0` tag — each a fix to *published* behaviour, so a
patch, kept off the approvals-design minor below. The signature is consistent: each is a prior fix that landed
one scope-boundary short — cleared at the call scope instead of the invocation scope (#390), keyed on column
presence instead of requiredness (#391), captured inside the measurement window instead of outside it
(#392/#393), gated on raw config instead of the effective class (#395). The two CI/release-integrity gates
that would have caught this class ride the same patch.

| Issue | Effort | Deps | Status |
|---|---|---|---|
| [#395](https://github.com/fissible/verdict/issues/395) `verdict:validate` audits by raw config, not the effective writer | XS | none | ✅ Shipped — PR #404 |
| [#390](https://github.com/fissible/verdict/issues/390) Tool-description fingerprint clears at call scope, not invocation | S | none | ✅ Shipped — PR #401 |
| [#391](https://github.com/fissible/verdict/issues/391) Evidence degradation is presence-keyed, not requiredness-keyed | S | none | ✅ Shipped — PR #402 |
| [#392](https://github.com/fissible/verdict/issues/392) Resource checkpoint runs inside the predicate-capture window | S | none | ✅ Shipped — PR #403 |
| [#393](https://github.com/fissible/verdict/issues/393) Checkpoint records `executed:true` before the executor runs; double-counts | S–M | none | ✅ Shipped — PR #405 |
| [#397](https://github.com/fissible/verdict/issues/397) Required per-PR MySQL smoke lane (smallest engine-discriminating slice) | S–M | none | ✅ Shipped — PR #406 |
| [#398](https://github.com/fissible/verdict/issues/398) Release-time changelog-completeness gate | S | none | ✅ Shipped — PR #407 |
| [#408](https://github.com/fissible/verdict/issues/408) A narrow-role writer/ledger override ignores the configured evidence table and connection | XS | none | ✅ Shipped — PR #404 |

**#408 was filed after the fact.** It was found while fixing #395 and fixed in the same pull request, because
it was load-bearing for it: #395 teaches `verdict:validate` to audit the tables a narrow-role deployment uses,
and without #408 the audit would have begun reporting on tables the deployment never opens. Filed retroactively
so the change has a ticket rather than only a changelog line; it has no entry of its own, and is described
inside #395's.

**Ordering.** Smallest-first: #395 is a one-line gate correction; then the evidence/provenance-integrity fixes
(#390/#391) and the evaluation-instrument fixes (#392/#393); then the two gates that close the release-integrity
holes these fixes surfaced — #397 for the MySQL row-order defect that shipped because the matrix is non-blocking,
#398 for the changelog gap caught only by a manual pass. The evaluation-surface fixes (#392/#393) touch an
`@experimental` surface; they are measurement-integrity, not authorization-gate, corrections.

**The two design-gated findings are not here**, and both now sit on v0.14.0 rather than in the unscheduled
list this section originally sent them to. #396 (the adapter-boundary `approveAll()` becoming a silent
deny-all) was held back "until its observability contract is decided" — which contradicted how every other
item on that milestone is treated: v0.14.0 exists to *carry* a design round, not to receive work after one.
#394 (Octane singleton-registry re-registration + `ActionContext` instance-form staleness) rides along as
hardening carry-over. Both moved 2026-08-30; see v0.14.0 below.

## v0.13.2 — Release-tooling integrity *(patch)*

**Theme.** Work on the release *path* rather than on shipped behaviour. v0.13.1 closed the changelog hole
(#398) and made the MySQL lane blocking (#397); this closes the last one it left — the release script tagged
before anything could vet the commit it tagged.

| Issue | Effort | Deps | Status |
|---|---|---|---|
| [#410](https://github.com/fissible/verdict/issues/410) `release.sh` tags before CI can vet the release commit, and never runs the suite itself | XS | #398 | ✅ Shipped — PR #411 |

**Why this is a milestone and not just a merge.** The fix is effective the moment it is on `main`, because
`release.sh` runs from the working tree rather than from an installed package — so unlike every other milestone
here, the tag is bookkeeping rather than delivery. It exists to give release-path work a home of its own, so it
is neither smuggled into a behaviour patch nor left unscheduled.

**What it does not close.** The push to `main` still bypasses branch protection, deliberately: requiring a pull
request for a version bump would mean the tag cannot sit on `main`'s tip without a second round-trip. The gap
that mattered was tagging before a verdict, and that is now a local gate — measured on v0.13.1, CI started nine
seconds before the GitHub Release published, with the run still in flight.

---

## v0.14.0 — Approvals: design rounds

**Theme.** Originally the two review findings needing a design round before implementation, both concerning
the approval receipt. #306 moved to v0.15.0 on 2026-08-25 — the move this section always planned — leaving
the schema/portability round. #396 and #394 joined on 2026-08-30.

| Issue | Effort | Deps | Status |
|---|---|---|---|
| [#309](https://github.com/fissible/verdict/issues/309) Receipt timestamp round-trips depend on a uniform DB session timezone | S (validate) / M (schema) | #168 | open — `scope: design` |
| [#396](https://github.com/fissible/verdict/issues/396) `approveAll()`/`approveRemaining()` becomes a silent deny-all at the adapter boundary | S (round) + S (impl) | ADR 0033 | open — `scope: design` |
| [#394](https://github.com/fissible/verdict/issues/394) Octane: singleton registry throws on same-name re-registration; `ActionContext` instance capture goes stale | M (round) + M (impl) | #358 | open — `scope: design` |

**Relationship to the approval-surface cluster.** #306 is squarely an ADR 0026 round; when the cluster was
cut as v0.15.0 it moved there, **to be designed together with #300** (`issuedAt`) rather than shipped
alone — they touch the same `ApprovalChallenge` payload. #309 is a narrower schema/portability correctness
round and stands on its own; it relates #168.

**Why #396 is on-theme and #394 is not.** #396 is an approvals-surface design round — what an adapter owes an
adopter when it fail-closes silently — so it belongs here on the same terms as #309: the milestone carries the
round, it does not wait for it. #394 is the honest exception. It is a confirmed Octane lifecycle defect with no
approvals content, and there is no hardening minor to hold it; rather than leave it indefinitely unscheduled it
rides this milestone as **hardening carry-over**, which this document has precedent for. Recorded as an
exception so the theme stays legible: if an Octane/hardening minor is ever cut, #394 is the item that moves.
---

## v0.15.0 — Approvals: the approval-surface cluster

**Theme.** The cluster verdict-console ADR 0001 surfaced, held for a dedicated milestone since the v0.13.0
plan and cut on 2026-08-25. Its design keystone half-shipped early: #298 closed as
[ADR 0031](docs/adr/0031-approval-reads-are-observational-and-scoped.md) (approval reads are observational
and scoped), which rides v0.13.0's tag; this milestone is the remaining build order. The recorded order is
#297 → #298 ✅ → #299, with the status reads (#327) able to ship for the sync lane alone — they are what
verdict-console's three workaround deletions (VC-10, VC-43, VC-45) wait on.

| Issue | Effort | Deps | Status |
|---|---|---|---|
| [#300](https://github.com/fissible/verdict/issues/300) `ApprovalChallenge` has no `issuedAt` | XS | none | open — ungated, contributor-drivable; design with #306 |
| [#327](https://github.com/fissible/verdict/issues/327) Implement the `ApprovalStatusReader` read contract | S | ADR 0031 ✅ | ✅ shipped (ApprovalStatusReader) |
| [#320](https://github.com/fissible/verdict/issues/320) Does authorization precede receipt-state resolution — `Unauthorized` can mask expired/consumed | S (round) | #305 ✅ | ✅ shipped ([ADR 0036](docs/adr/0036-receipt-state-precedes-authorization.md), PR #430) |
| [#265](https://github.com/fissible/verdict/issues/265) Queued-resumption reference test teaches `continueLastConversation()` | S | watches laravel/ai #932 | open |
| [#299](https://github.com/fissible/verdict/issues/299) Receipt transitions dispatch no events | S–M | #327 | open — gated on the read contract |
| [#306](https://github.com/fissible/verdict/issues/306) The approver cannot see what they approve — revisit ADR 0026's challenge contents | M (round) + M (impl) | ADR 0026 | open — `scope: design`; moved from v0.14.0 |
| [#297](https://github.com/fissible/verdict/issues/297) `RequireReview` is a disposition with no runtime | L–XL | none | open — `scope: ready`, building; [ADR 0035](docs/adr/0035-the-asynchronous-review-lane.md) (#428), the loud reserve (#429) and the value layer (#434) have landed |
| [#357](https://github.com/fissible/verdict/issues/357) `pendingWithin()` is an unbounded scan + N+1 with no expiry filter | S–M | none | open — reader-queue scale; a pre-1.0 must-fix the v0.14.0 review named |
| [#425](https://github.com/fissible/verdict/issues/425) `findForToolCall()` collapses 2+ receipts to `null`, indistinguishable from absent | S (round) + M (impl) | none | ✅ shipped (PRs #431, #435) — collision is an opt-in seam; the `Stable` contract is intact |
| [#436](https://github.com/fissible/verdict/issues/436) #320 placed a new behavioural obligation on the Stable `ApprovalReceiptStore` contract | S (round) + S (impl) | #320 ✅ | open — the silent half of the same Stable-contract question #425 raised loudly |

**Cluster membership, settled.** #230 stays on v1.0.0 — it is a boundary decision on the 1.0 bar, and it
was already scheduled there when the cluster was cut. #201 stays deliberately unscheduled with its recorded
reason. ADR 0031 §6 reserves #297's review-request reads to ride the #327 contract, and #299 is only
meaningful against that contract's freshness statement — the intra-milestone ordering is load-bearing, not
aesthetic.

**The two reader-queue defects ride this milestone.** #357 (`pendingWithin()` scan + N+1) and #425
(`findForToolCall()` ambiguity → `null`) are the two the v0.14.0 external review named as most likely to
bite an adopter's approval queue at scale, and asked to see fixed before 1.0. Both are independent of the
design keystone and drivable any time. #357 is still open; #425 is closed and settled.

**#425 shipped in two parts, and the second one is the point.** PR #431 gave the tool-call lookup three
outcomes — absent, single, collision — because `null` could not express the third, and a reviewer queue
therefore rendered a receipt collision as absence. That part was right and stands. What shipped with it was
an incompatible change to the return type of `ApprovalReceiptStore::findForToolCall()` — a contract this
repository labels **Stable** in [`docs/extension-contract-stability.md`](docs/extension-contract-stability.md),
meaning "intended to remain compatible through Verdict 1.0". The break was *recorded* in that document
rather than reversed, which is the wrong order: a Stable-through-1.0 promise is not something to normalize
after the fact, and the window to reshape it closes the moment an adopter writes a custom store against it.

PR #435 reshaped it. `findForToolCall()` and `statusForToolCall()` are restored verbatim, ambiguity
included — `git diff` against pre-#425 `main` touches docblock lines only — and the three-outcome read
moved to two opt-in interfaces: `DistinguishesReceiptCollisions` on the store side and
`DistinguishesStatusCollisions` on the read side. Both shipped stores and both paired readers implement
them; a custom store written against v0.14.0 needs no edit. The section recording the break is gone from
the stability document, because there is no longer a break to record.

`statusForToolCall()` did not strictly need reversing — `ApprovalStatusReader` is `Experimental` — but it
was reversed anyway, so the seam is opt-in symmetrically on both sides rather than opt-in on one and
mandatory on the other.

Two things the reshape settled that are worth keeping. A reader carries the collision interface **only**
when it can honour it: the container pairs a custom store with `DistinguishingStoreBackedApprovalStatusReader`
when the store adopted the store seam and with the untouched `StoreBackedApprovalStatusReader` when it did
not, because `instanceof` is the probe consumers are told to trust and a capability that throws for half
its instances is a false positive on it. And `ApprovalManager::challengeForToolCall()` is deliberately left
on the ambiguous read — a collision yields no challenge either way, so wiring it to the seam would make
every custom store's support of the seam load-bearing for no behavioural gain.

**#436 is the same question asked quietly.** Reviewing PR #435 surfaced that #320 put a new *behavioural*
obligation on the same Stable contract: a custom store must now refuse decisions on terminal or expired
receipts, or it finalizes them with no authorization check at all — because the manager no longer consults
the authorizer for a receipt it already knows is undecidable. Before #320 a lax store was still covered.
This is worse than #425's break in one respect: a signature change fails loudly at load time, and this one
compiles clean and fails as a missing authorization check. Filed on this milestone with three options and a
recommendation; it is not a proposal to revert #320, whose outcome semantics are correct.

**Cross-repo consumer.** verdict-console tracks adoption of the opt-in seam at
[fissible/verdict-console#96](https://github.com/fissible/verdict-console/issues/96) (milestone
`verdict-gated`), which also carries the `fissible/verdict` constraint bump from `^0.14`.

**The precedent this sets.** A `Stable` label is a constraint on how a fix may be shaped, not a note to be
amended when a fix is inconvenient. The three-outcome read was the right diagnosis both times; what
changed is that the second version does not charge existing adapters for it.

---

## Contributor-ready

These carry `scope: ready` and are open to anyone. They also now carry the `v1.0.0` milestone — a change
from the earlier rule that unclaimed work stays unscheduled. The underlying principle is unchanged: a
release must not depend on strangers who may never start. The milestone states what 1.0 *requires*; it
does not gate interim tags — whatever lands early ships in whichever tag is open, exactly as displaced
scope did for v0.4.0, and whatever is still unclaimed at the 1.0 decision point is absorbed by the
maintainer or explicitly re-triaged (see the v1.0.0 section below). Labels remain the discovery surface —
`CONTRIBUTING.md` points contributors at `scope: ready`, and newcomers filter on `good first issue`.

Ordered by suggested pickup order: defects first, then self-contained work with a visible result.

| Issue | Effort | Label | Deps |
|---|---|---|---|
| [#165](https://github.com/fissible/verdict/issues/165) Give `verdict:prune-rate-limits` a test that can fail | XS | `good first issue` | none |
| [#143](https://github.com/fissible/verdict/issues/143) Publish PostgreSQL and MariaDB arms of the security-state benchmark | S | `good first issue` | none — compose services already exist |
| [#142](https://github.com/fissible/verdict/issues/142) Collapse the repeated store/connection/table triple into a shared value object | XS | `good first issue` | none — carved out of #141 |
| [#166](https://github.com/fissible/verdict/issues/166) Prove a failing `CapabilityConfigurationStore` fails closed | S | `good first issue` | none — template at `SemanticRateLimitTest:194` |
| [#168](https://github.com/fissible/verdict/issues/168) Assert the schema the migrations produce on MySQL, MariaDB, and PostgreSQL | S/M | `good first issue` | none — the matrix already runs them |
| [#144](https://github.com/fissible/verdict/issues/144) Prove the in-memory stores agree with the database stores | M | `help wanted` | none |
| [#145](https://github.com/fissible/verdict/issues/145) Add a delegation attack pack for actor-versus-subject confusion | M | `help wanted` | #31 ✅ |
| [#167](https://github.com/fissible/verdict/issues/167) Pin cross-actor approval receipt separation end to end | S | `help wanted` | none |
| [#151](https://github.com/fissible/verdict/issues/151) Harden field-path handling in the release path | M | `help wanted` | none — #150 shipped in v0.5.0 |
| [#164](https://github.com/fissible/verdict/issues/164) Cover rate-limit window boundary, expiry, and cross-window leakage | M | `scope: ready` | none |
| [#141](https://github.com/fissible/verdict/issues/141) Hydrate the attest evidence configuration into a typed value object | S | `scope: ready` | none — precedent in #91 |
| [#424](https://github.com/fissible/verdict/issues/424) Make the cross-recorder derivation order contract true below one-second precision | S | `scope: ready` | none — in-memory precision + a same-second fixture |

**#151 is the hardening that remains open after the same audit.** #149 and #150 were fixed in v0.5.0:
neither was an authorization bypass, but both were asymmetries that were cheap to fix and expensive to
explain later. #152 continued that hardening and shipped in v0.8.0's window; #151 is the remaining half,
around field-path handling.

[#153](https://github.com/fissible/verdict/issues/153) was settled in v0.5.0: a failed evidence write now
dispatches `EvidenceWriteFailed` and execution continues, leaving the operational gates — rate-limit
consumption, approval consumption, and execution-claim admission — as the only authorization decisions.
The outcome differs by gate: a rate-limit unit can self-heal, while an admitted execution claim that
cannot be finalized blocks its binding indefinitely. See ADR 0007 and the v0.5.0 changelog.

**#163 through #168 came out of a test-coverage audit after v0.5.0.** None is an authorization bypass and
none makes a released version unsafe; they are places where a guarantee holds today but nothing pins it.
#163 was the only one that changed shipped behaviour — the tool-description fingerprints were computed and
then discarded — and it shipped in v0.8.0's window. Of what remains, #165 is the cheapest and the most
instructive: the command's only test asserts `Pruned 0` against an empty table, so it holds whether pruning
works or does nothing at all.

#167 and #168 are deliberately last. Cross-actor receipt separation already follows from `actor_id`
participating in the binding, and the migrations already execute against all three engines in the
concurrency matrix — both issues pin an existing property rather than close a suspected hole, and both say
so. An earlier draft of the audit claimed migrations only ever run on SQLite and that a poisoned
description could redirect a capability; neither survived checking, and neither is filed.

**#143 closes a gap between what is claimed and what is published.** ADR 0018's retry policy exists because
of how PostgreSQL reports serialization failures at COMMIT. That behavior is tested, but the benchmark table
covers SQLite and MySQL only.

**#142 and #141 must not collide.** #141 owns the `evidence.attest` block, which has real invariants; #142
owns the four repeated store sections, which have none. Whoever takes the second should rebase on the first.

**#424 is the pinning pool's exact shape.** The v0.14.0 review's degradation audit found the
"identical derivation order across both recorders" contract pinned but false below one-second precision:
the database column stores whole seconds while the in-memory recorder sorts on microseconds, and every
fixture is whole-second, so the suite cannot see the divergence. A guarantee true in appearance but not in
fact, with the fixture that would catch it missing.

**Deliberately unscheduled**, each for its own reason rather than by the old blanket rule:

- [#201](https://github.com/fissible/verdict/issues/201) — a recorded, documented limitation; it becomes
  scheduled work when an adopter hits it, per the v1.0.0 section's argument.
- [#212](https://github.com/fissible/verdict/issues/212) — on-demand by its own framing; attach it to
  whichever recorded run next needs a middle-spectrum arm.
- [#213](https://github.com/fissible/verdict/issues/213) — an epic; its children take milestones (#148
  opens in v0.9.0), the umbrella does not close with any one tag.
- [#259](https://github.com/fissible/verdict/issues/259) — a design-first governance/cost gate (external
  budget facts at the action boundary); it becomes scheduled work when an adopter demonstrates the
  metered-tenant requirement, the same argument that holds #201. Added to this list 2026-08-25.

---

## v1.0.0 — the 1.0 bar

An earlier revision of this section scheduled nothing, on the argument that inventing 1.0 work would
produce a backlog that measures imagination rather than adoption. That argument held until the backlog
produced 1.0-shaped work on its own: two decisions now name 1.0 as their natural boundary, one dependency
question cannot be called settled while upstream sits behind a known unreleased breaking change, and the
contributor pool's pinning work serves the bar directly. The milestone now exists and carries them.

The bar itself is unchanged and stated in [`RELEASES.md`](RELEASES.md): stable documented contracts, an
explicit Laravel AI compatibility strategy, upgrade-safe migrations, real-application feedback, and no
known silent bypass within the supported integration paths.

**The decision pair, first:**

| Issue | Effort | Deps |
|---|---|---|
| [#159](https://github.com/fissible/verdict/issues/159) Decide whether the capability invariant is structural or only declarative | M | none |
| [#230](https://github.com/fissible/verdict/issues/230) Reject a pause-less confirmation gate at registration (rejection half) | S | #159 |

These are one question at two layers — whether the boundary is enforced by structure or by advisory — and
should settle in one ADR. #230's thread already proposes the sequencing: the v0.8.0 advisory (#231) *is*
the deprecation period, and registration-time rejection lands at the 1.0 boundary.

**A third decision, from the survey rather than the backlog.**
[#352](https://github.com/fissible/verdict/issues/352) asks whether Verdict signals proximity to a
semantic rate limit before it denies. The values are already recorded — `rateLimitLimit`,
`rateLimitRemaining`, and `rateLimitResetAt` sit on `DecisionEvidence` — so the open question is a
boundary one, not a capability one: is a near-miss something Verdict emits, or something an operator
reads out of evidence afterwards. It belongs here because the same question was already answered once,
for tool-description divergence, with "whether it should is a separate decision, deliberately not made
here." Leaving it answered in one place and open in the other is what 1.0's bar rules out. Closing it
as a recorded refusal is a valid outcome; building a signal is not required.

**Upstream compatibility — satisfied, and moved out.** [#130](https://github.com/fissible/verdict/issues/130)
lived here because the compatibility-strategy criterion could not be called satisfied while the dependency
sat behind a known unreleased breaking change. Upstream published `0.11.0` on 2026-08-19 and #130 shipped
in v0.9.0, so the blocker is gone. What the criterion now requires is not an issue but a practice: that
the strategy keeps being exercised on each upstream minor. The dependency watch below is that practice.

**The pinning pool** is the contributor-ready section above: every guarantee that holds today but is not
pinned by a test, plus the hardening that makes an allowlist explainable. Suggested pickup order is stated
there; #156 carries `scope: design` and needs a decision before code.
[#160](https://github.com/fissible/verdict/issues/160)'s design settled on the issue and shipped as the
write-ahead intent lever (PR #330, ADR 0007 Update); its review round's two code-health deferrals —
`intentGate()`'s return shape and `Capability`'s wither boilerplate — are recorded as
[#331](https://github.com/fissible/verdict/issues/331) in this milestone.

**What this milestone cannot contain:** the real-application-feedback criterion is not backlog. It arrives
through adoption — [#237](https://github.com/fissible/verdict/issues/237) is the nearest instrument;
external contributors and issue reports are the rest. A 1.0 with every issue above closed but no
integration feedback is not a true 1.0. The tag waits for the evidence, not the checklist.

**One evidence-ordering decision carries here.** [#432](https://github.com/fissible/verdict/issues/432):
evidence rows have no insertion-order column — the primary key is a random UUID and `recorded_at` is a
whole-second timestamp, so `provenanceFor()` breaks ties by UUID and returns a *stable* order that is not
the recorded one. #311 item 6 named this root cause and #383 closed the half the review had reported
(run-to-run instability) with a portable data-order tiebreaker, deliberately not adding a monotonic column
because SQLite forbids a secondary auto-increment. What is left is the promise itself: whether Verdict
states that evidence reads are in recorded order, or states that they are not. It belongs to the 1.0 bar
as a documented-contract question rather than a defect — the current behaviour is stable and safe, and
"an audit reader may assume the order they see is the order it happened" is either true or must be said
to be false. #389 is where it surfaced, as the reason a degradation test can only assert sets.

**One hardening advisory carries here.** [#426](https://github.com/fissible/verdict/issues/426): the
instance-form `ActionContext` is documented-forbidden (ADR 0027 §7) yet runtime-accepted with no warning.
Documentation-not-enforcement is a defensible posture, consistent with `GuardedTool`, which is why this is
low — but "the docs forbid it, the code accepts it silently" is the asymmetry a 1.0 bar exists to close.
The fix is an advisory (warn, never reject).

---

## Upstream dependency watch

See [`docs/laravel-ai-compatibility.md`](docs/laravel-ai-compatibility.md) for the full inventory of what
Verdict's `src/` actually depends on in Laravel AI's surface, classified by how likely each dependency is
to change without warning, and which tests would catch it (#18).

Verdict pins `laravel/ai: ^0.11.0`, which in Composer's pre-1.0 caret semantics is `>=0.11.0 <0.12.0`.
`0.10.x` is no longer supported — see v0.9.0 above for why that floor move was forced rather than chosen.

- **The run-context stack shipped in `0.11.0` and was absorbed in one reviewed pass (#130 → #244).**
  laravel/ai#848, the draft this watch originally tracked, was **closed as superseded**; the work split
  into #870, #872, #873, #874, #875, and #876. Do not cite #848 as the fix — **#872** is what deleted the
  shared `toolInvocationId` and scoped it through a `RunContext`.
- `tests/Feature/ToolInvocationCorrelationTest.php` used to pin the *buggy* behaviour deliberately, so an
  upstream fix would fail loudly rather than change the meaning of recorded evidence in silence. **The
  alarm fired as designed**, and the assertion now states the fixed behaviour: each tool's completion
  event reports its own id. #246 added the failure-path mirror over `ToolFailed`, which occupies the same
  trailing-event position the defect used to corrupt.
- Dependabot watches Composer weekly and will open the widening PR. Whichever milestone is open when that
  lands absorbs the compatibility review, per `RELEASES.md`.
- `.github/workflows/laravel-ai-canary.yml` runs PHPStan and the suite against `laravel/ai:0.x-dev` weekly,
  non-blocking. Dependabot's PR arrives when upstream *publishes*; the canary reports when upstream
  *merges*. That lead time is the point — it is what lets an issue blocked on an in-flight upstream stack
  be scoped against what actually landed rather than against an open draft. A red canary means upstream
  changed: open a widening issue on #130's pattern — a compatibility review and a release — and do not
  widen `composer.json` to make it green. The constraint is `0.x-dev`, not `dev-0.x`; Composer normalizes
  a branch name that already looks like a version.
- **The canary reports merges, not releases, and that distinction cost real time once.** It ran on
  schedule the morning after the stack merged and reported exactly the two failures #130 predicted — but
  `0.11.0` publishing a week later produced no signal of its own, because a published tag outside the
  constraint is invisible to CI by construction. Dependabot is the alarm for *publication*; the canary is
  the alarm for *merge*. Neither replaces reading upstream's releases.
