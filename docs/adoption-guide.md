# Verdict adoption guide

This guide turns Verdict's documented security boundary into an application-owned adoption plan: a limited, non-production pilot first, then a separately reviewed high-consequence deployment. It is not a production certification, a vendor assessment, or a substitute for the [security model](security-model.md) and [limitations](limitations.md).

Verdict protects only the capability path configured and invoked through it. Start with one bounded, reversible capability; do not use a pilot to establish whether an irreversible or high-value operation is safe.

## Pilot readiness checklist

Complete and record each item before exposing a protected capability to pilot users.

- [ ] **Choose one capability and its target.** Use `BoundTool`, a trusted target resolver, and an explicit execution-target policy. Write and test the Laravel policy against the authenticated actor and tenant-scoped target. The model's arguments must not select an object that the resolver or policy has not accepted. See the [security model](security-model.md#authorization) and [target-freshness guidance](security-model.md#target-freshness-and-toctou).
- [ ] **Choose the execution mode from the compatibility matrix.** Start with a cell marked verified in [execution-mode compatibility](architecture.md#execution-mode-compatibility). Its notes state what the tests do and do not establish; a green cell is not a live-provider or application-policy certification.
- [ ] **Select the admission controls deliberately.** For each capability, decide whether it needs a canonical, material-fact approval binding, `atMostOnce()` claim identity and retention, and/or a semantic `rateLimit()` scope, window, and limit. An execution claim controls Verdict admission, not an external side effect. See [approval](security-model.md#human-approval), [claims](security-model.md#preventing-duplicate-actions), and [limits](security-model.md#limiting-what-ai-can-do).
- [ ] **Own the approval flow.** Build the authenticated reviewer queue, endpoint, display, decision audit, and resume/conversation handling in the application. Present every material binding fact, authenticate the reviewer, and store only an opaque application identifier in `approvedBy`. Verdict ships no reviewer UI, route, queue, or durable conversation-resumption protocol. `php artisan verdict:make-approval-flow` publishes route-free application skeletons as an optional starting point — including a working `VerdictApprovalAuthorizer` — and registers none of them. Configure `verdict.approvals.authorizer` and run the `approval_context` migration before the first decision: approval decisions are fail-closed, so `approve()`/`reject()` refuse until an authorizer is configured, and supply the binding identifiers the authorizer checks via `ActionContext(approvalContext: [...])`. See [who may decide a receipt](security-model.md#who-may-decide-a-receipt). **Two requirements are easy to miss and fail silently.** Approving the agent framework's pending call is not approving Verdict's receipt: call `ApprovalManager::approve()` from your authenticated reviewer flow, *and* resume with a specific tool-call decision (`Decisions::from([$toolCallId => Decision::approve()])`). `Decision::approveAll()` is a wildcard Verdict deliberately refuses, so a blanket approval from the model loop cannot authorize a specific consequential action — a resume that skips either step executes nothing and looks like a broken feature. See [approval resolution and scope](architecture.md#resolving-an-approval) and [confirmation-fatigue guidance](security-model.md#avoiding-confirmation-fatigue). For the queue's *reads*, use the `ApprovalStatusReader` contract
  ([ADR 0031](adr/0031-approval-reads-are-observational-and-scoped.md)) rather than querying the
  receipt table: `pendingWithin()` lists pending receipts scoped by the same `approvalContext`
  identifiers you capture at issuance, and `statusFor()` reads one receipt back — including after it
  is decided, which is what tells "already decided" apart from "lapsed, undecided". Applications
  that do not capture `approvalContext` keep the application-owned `tool_call_id` join for listing;
  the reader's status reads work either way.
- [ ] **Explicitly configure evidence.** `NullEvidenceRecorder` is the default and records nothing. Select a durable recorder, give the application a retention and access policy, and verify it records the pilot's expected decisions and context releases. Do not call a mutable database evidence table cryptographic proof. See [evidence limitations](limitations.md#tamper-evident-evidence-is-opt-in-partial-and-bounded-by-key-custody). Before the pilot ends, walk one real decision end to end with the [incident-response guide](incident-response.md) — an evidence store nobody has queried is an assumption, not a control.
- [ ] **Exercise failure and recovery.** Test denied authorization, expired/rejected approval, duplicate admission, exhausted limits, an executor with an indeterminate external outcome, and the application's reconciliation path. The application owns domain idempotency keys, transactional outboxes, reconciliation, and compensating operations. See [downstream-side-effect limits](limitations.md#no-guarantee-of-downstream-side-effects).
- [ ] **Set operational ownership.** Name owners for authentication and tenancy, policy/domain-rule changes, evidence access and retention, provider/data governance, incident response, and alerts. Protect controller, scheduler, queue, and service paths that can bypass Verdict with their own equivalent controls.

## Registering capabilities: affirm, don't wire

`verdict:make-capability` writes a class to `app/Capabilities/` with a `make(): Capability` method and a
TODO for every security question it cannot answer for you. Replacing those TODOs is the work. When they are
all replaced, add one token:

```php
final class RefundCapability implements DefinesCapability
```

That is the registration. Verdict discovers definition classes implementing `DefinesCapability` under
`verdict.capabilities.discovery.paths` and registers them at boot, through the same path
`Verdict::capability()` uses — a discovered capability and a hand-registered one are the same object
everywhere downstream. Provider wiring still works and is still supported; it is no longer necessary.

**Implementing the contract is an affirmation, not a proof.** Verdict cannot see inside your closures, so it
cannot check that you replaced the TODOs. A definition that affirms while still unfinished fails at boot if
its TODO throws while building, and at first invocation otherwise. Both are fail-closed.

**To ship a deploy with a capability mid-work, remove the interface.** An unaffirmed class is inert —
nothing registers it, nothing fails — and `verdict:validate` names it on every run so it cannot be
forgotten. Un-affirming is the supported way to park unfinished work; deleting the file or hacking out a
TODO are not.

### What `verdict:validate` tells you, and what boot tells you

Run `php artisan verdict:validate` in your deploy pipeline. Two different things can happen:

- **Unaffirmed classes are reported by the command.** Advisory, printed on every run, never blocking. Add
  `--strict` to make CI fail on them; that changes the exit code only, never what is printed.
- **A broken definition fails the command's own bootstrap, before the command runs.** Artisan boots the
  application before dispatching, so a capability that affirms the contract and then throws while building
  takes the process down during startup — with *every* such failure listed at once, each naming its class,
  its cause, and both ways to resolve it.

**That second case is the pipeline working, not the tooling breaking.** The guarantee you want from
`verdict:validate` in CI — *this deploy fails with the full list before production ever boots this code* —
holds either way; only which layer prints it differs. Fix the listed definitions, or remove
`implements DefinesCapability` from the ones that are not ready, and re-run.

## Independent security-state connection

The database approval, rate-limit, and execution-claim stores must commit independently of an application transaction. If an application wraps a Verdict invocation in a transaction, configure those stores with a different **named Laravel connection**, even when it points to the same physical MySQL or PostgreSQL database. A different connection name gives Laravel a separate PDO and transaction scope; reusing the default connection does not. This does not create an atomic transaction with the executor or an external provider: the application still needs an outbox and reconciliation. See [ADR 0004](adr/0004-independent-security-state-transactions.md).

In `config/database.php`, duplicate the application's MySQL or PostgreSQL connection under a distinct name. This MySQL example intentionally uses the same database while allowing separate credentials and connection lifecycle:

```php
'verdict_security' => [
    'driver' => 'mysql',
    'host' => env('VERDICT_SECURITY_DB_HOST', env('DB_HOST', '127.0.0.1')),
    'port' => env('VERDICT_SECURITY_DB_PORT', env('DB_PORT', '3306')),
    'database' => env('VERDICT_SECURITY_DB_DATABASE', env('DB_DATABASE')),
    'username' => env('VERDICT_SECURITY_DB_USERNAME', env('DB_USERNAME')),
    'password' => env('VERDICT_SECURITY_DB_PASSWORD', env('DB_PASSWORD')),
    'unix_socket' => env('VERDICT_SECURITY_DB_SOCKET', env('DB_SOCKET')),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'prefix_indexes' => true,
    'strict' => true,
],
```

For PostgreSQL, duplicate the application's `pgsql` entry instead, retain the same `host`, `port`, and `database`, and name it `verdict_security`. Do not point either example at a replica: these stores make authoritative writes. Then opt the shipped stores into that named connection in `config/verdict.php`:

```php
'capability_configurations' => [
    'connection' => 'verdict_security',
],
'approvals' => [
    'connection' => 'verdict_security',
],
'rate_limits' => [
    'connection' => 'verdict_security',
],
'execution_claims' => [
    'connection' => 'verdict_security',
],
```

Run the package migrations against that database (`php artisan migrate --database=verdict_security`), and test an invocation inside an application transaction before relying on this topology. The connection boundary prevents an outer rollback from erasing an admitted Verdict security-state transition; it cannot make the application transaction, the executor, and a downstream provider commit together.

## Evidence profile for a high-consequence deployment

An opt-in profile, not a default. It keeps the package's default no-op recorder unchanged and requires a deployment-specific decision about availability, integrity, retention, topology, and alert handling.

1. Install and configure `fissible/attest-laravel`, including its signing-key custody and storage configuration. Select exactly one Verdict chain topology: a fixed `chain` only for a genuinely single-tenant deployment, or an application `AttestChainResolver` for per-tenant chains. A chain cannot be safely split after evidence is written.
2. Configure the recorder and its ordinary fallback explicitly. The `fallback_connection` stores provenance and derivations and receives `chain_gap` records after a failed chained write; use a dedicated named connection when that isolation is part of the deployment's threat model.

```php
use App\Support\TenantChainResolver;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;

// config/verdict.php
'evidence' => [
    'recorder' => AttestEvidenceRecorder::class,
    'attest' => [
        'chain' => null,
        'chain_resolver' => TenantChainResolver::class,
        'fallback_connection' => 'verdict_security',
        'fallback_table' => 'verdict_evidence',
        'chain_provenance' => false,
        'on_failure' => 'alert',
        'max_attempts' => 3,
        'base_delay_ms' => 50,
    ],
],
```

3. Make the evidence-failure decision explicit. `on_failure: 'alert'` preserves the default boundary: Verdict records a gap where possible, emits `ChainWriteFailed`, and does not turn an evidence outage into an authorization outage. A high-consequence deployment may choose `on_failure: 'throw'` only after deciding that failed evidence must fail closed and after proving the resulting availability and recovery behavior. In both modes, route `ChainWriteFailed` to an owned incident channel and test that route.
4. Choose retention, access control, encryption, tenant isolation, and a required verification level. Chaining decisions and context releases does not automatically chain provenance, approval receipts, or protect against a holder of the signing key. Read the complete [tamper-evidence boundary](limitations.md#tamper-evident-evidence-is-opt-in-partial-and-bounded-by-key-custody).

The following application scheduler is a starting point, not a package-managed schedule. Select `--min-anchor` for the assurance level your deployment requires, run verification at least daily, and verify after anchoring. Route a non-zero command exit to the same on-call process used for `ChainWriteFailed`.

```php
// routes/console.php or app/Console/Kernel.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('attest:anchor')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('verdict:evidence:verify --min-anchor=remote_header_confirmed')
    ->dailyAt('02:15')
    ->withoutOverlapping()
    ->onOneServer();
```

`verdict:evidence:verify` is Verdict's configuration-aware delegate to `attest:verify`: it resolves the
configured fixed Verdict chain and uses Attest's configured connection and trusted keys. It does not
reimplement signature or chain verification. A deployment using `chain_resolver` has more than one possible
chain, so schedule one invocation per concrete chain, e.g.
`verdict:evidence:verify --chain=tenant:42 --min-anchor=remote_header_confirmed`. The command reports whether
provenance is covered by the configured `chain_provenance` setting; it never verifies approval receipts.
For an exceptional verification run, it forwards Attest's `--trusted-key`, `--trusted-key-file`,
`--bitcoin-core-rpc`, `--bitcoin-core-cookie`, `--esplora-url`, and range/anchor options unchanged.

`attest:anchor` is experimental and confirmation lags its anchor interval. Verification detects a problem; it does not identify the actor or repair evidence. Keep an out-of-band record of each chain head and entry count as described in [limitations](limitations.md#tamper-evident-evidence-is-opt-in-partial-and-bounded-by-key-custody).

## Failing closed on unrecorded actions

By default an evidence-store outage never blocks a protected action ([ADR 0007](adr/0007-evidence-layering.md)):
the action executes and no durable record of it exists. A deployment whose compliance regime requires the
opposite — the action fails rather than happening unrecorded — opts into the write-ahead intent lever
([#160](https://github.com/fissible/verdict/issues/160)):

```php
// config/verdict.php — every capability, unless one opts out:
'intents' => [
    'required' => true,
    'connection' => 'verdict_security', // independently committed, like every security-state store
],
```

```php
// or per capability, in either direction:
Capability::usingPolicy('orders.refund', 'refund', $resolveTarget)
    ->requiresIntentRecord()          // this action must not act unrecorded
    ->executionTarget($policy)
    ->executeUsing($executor);

Capability::usingPolicy('orders.lookup', 'view', $resolveTarget)
    ->requiresIntentRecord(false);    // tolerate an intent-store outage for lookups
```

What the lever buys, precisely: **no protected action enters the execution pipeline's mutating phase
unless a durable intent record for that action has been committed** — written between the last
non-mutating gate and the rate-limit consume, so a failed write denies with nothing consumed and
costs one retry. Publish and run **both** migrations: the intent table (tag
`verdict-intent-migrations`) *and* the evidence `intent_id` column
(`add_intent_id_to_verdict_evidence_table`, in tag `verdict-evidence-migrations` — or publish
everything at once with `verdict-migrations`). The evidence column is not optional and not
lever-gated: `DatabaseEvidenceRecorder` writes it on every evidence insert, so any deployment using
a database-backed recorder must run that migration on upgrade regardless of the lever. Then wire
`ActionIntentWriteFailed` to paging and schedule the
[intents-with-no-outcome query](incident-response.md#scheduled-verification-intents-with-no-outcome).

Budget for the write: every attempt reaching the intent gate commits one durable row plus a
fail-open evidence mirror — including attempts a later gate then denies, which is the point (a
throttled attempt is still an attempt somebody made). Storage grows with *attempts*, not successes,
and Verdict ships no pruning command for the table; decide the archive window with your compliance
regime before enabling the lever fleet-wide.
`verdict:validate` fails when a capability requires the intent record and the table is missing. Read
[what the lever does not guarantee](limitations.md#the-intent-lever-guarantees-a-pre-mutation-record-not-an-outcome-record)
before presenting it to an auditor — outcome records stay fail-open, nothing fails closed after the
executor, and the chained copy is the mirror, not the gate.

## Latency and capacity decision

Verdict does not publish a package-wide SLO. Establish the pilot's latency budget from measurements of the application's contention, database topology, queue depth, provider behavior, and downstream executor. Include queue delay and the full validate-to-execute interval when sizing approval TTLs; do not use median request latency as a security bound.

Measure a representative load before changing TTLs or rate-limit windows. The database stores use bounded retries for recognized concurrency conflicts, but an application must still decide whether its retry time and saturation behavior fit the user-facing or queued workflow. Review the current behavior in [limitations](limitations.md#security-state-conflict-retries-are-bounded-and-verdict-owned) and the deployment's queue/reconciliation runbook.

## High-consequence production gate

Do not treat pilot success as this gate. Require a separate security and operations sign-off recording the evidence for each item:

- [ ] Every in-scope capability has a tested target resolver, tenant/authentication boundary, Laravel policy, domain invariant checks, and protected non-AI entry points.
- [ ] Approval review and resumption are application-owned, authenticated, consequence-weighted, and tested for expiry, denial, replay, and reviewer audit. Do not claim a built-in Verdict approval UI or worker.
- [ ] Claim identity/retention, semantic-limit scope/window, external idempotency keys, transactional outbox, reconciliation, and indeterminate-claim response are reviewed with the owning domain team.
- [ ] Evidence recorder, chain topology, fallback storage, signing-key custody, retention/access policy, verification level/cadence, anchoring cadence, and alert route have been exercised in the target environment.
- [ ] The selected execution mode is marked verified in the [compatibility matrix](architecture.md#execution-mode-compatibility), and the test boundary in its note is acceptable. In particular, queued approval resumption is verified only with a **durable conversation store**: the paused turn crosses the job boundary through conversation history, and a resume dispatched without it has nothing to reconstruct. It also requires the receipt to be approved in Verdict through the application's own authenticated flow, and the resuming dispatch to carry a *specific* tool-call decision rather than `Decision::approveAll()`'s wildcard — getting either wrong produces silent non-execution.
- [ ] Provider contracts, model/data governance, logging, secrets, and incident ownership have been reviewed outside Verdict's package boundary.
- [ ] The team has reviewed the current [limitations](limitations.md), [open security-relevant issues](https://github.com/fissible/verdict/issues?q=is%3Aissue%20is%3Aopen%20label%3Asecurity), and [ADR 0018](adr/0018-repeatable-read-and-serializable-require-a-conflict-retry.md)'s measured concurrency findings (the [#97](https://github.com/fissible/verdict/issues/97) and [#112](https://github.com/fissible/verdict/issues/112) retry gaps closed in v0.4.0). Follow their current state; this guide does not represent open items as resolved.

Completing this list is evidence of an application decision, not a Verdict production certification. Re-run it when a capability, execution mode, provider, security-state topology, evidence configuration, or downstream effect changes.
