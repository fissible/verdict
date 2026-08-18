<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console\Commands;

use Fissible\Verdict\Approvals\ApproverAudience;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Capabilities\CapabilityDiscovery;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Capabilities\InMemoryCapabilityConfigurationStore;
use Fissible\Verdict\Capabilities\UnaffirmedDefinition;
use Fissible\Verdict\Console\DatabaseTableStore;
use Fissible\Verdict\Context\ReleasePolicyRegistry;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\ExecutionClaimStore;
use Fissible\Verdict\Contracts\RateLimitStore;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\Verdict\ExecutionClaims\InMemoryExecutionClaimStore;
use Fissible\Verdict\RateLimits\InMemoryRateLimitStore;
use Fissible\Verdict\Targets\ExecutionTargetStrategy;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Throwable;

final class ValidateVerdictCommand extends Command
{
    protected $signature = 'verdict:validate {--strict : Also fail on advisory warnings, not only on configuration that will fail at runtime}';

    protected $description = 'Audit registered Verdict capability wiring without executing actions';

    /**
     * Exit-code contract: verdict:validate returns non-zero only for configuration that will
     * **fail at runtime** (a store whose backing table is missing, an unresolvable custom store).
     * Configuration that is legal but probably not intended — a no-op evidence recorder, a
     * non-durable store outside local (#146) — is **advisory** and warns without failing. `--strict`
     * opts into failing on advisory findings too, for adopters who want CI to block on them. This
     * is a single rule for every check rather than a per-check decision.
     */
    public function handle(
        CapabilityRegistry $capabilities,
        Container $container,
        ReleasePolicyRegistry $releasePolicies,
        CapabilityDiscovery $discovery,
    ): int {
        $errors = [];
        $warnings = [];
        $information = [];
        $needsApprovals = false;
        $needsRateLimits = false;
        $needsExecutionClaims = false;

        foreach ($capabilities->all() as $capability) {
            if (! $capability->isExecutable()) {
                $warnings[] = "Capability [{$capability->name}] has no executor; this is valid only for GuardedTool-style use.";
            }

            $needsApprovals = $needsApprovals || $capability->confirmationRequired();
            $needsRateLimits = $needsRateLimits || $capability->rateLimitPolicy() !== null;
            $needsExecutionClaims = $needsExecutionClaims || $capability->executionClaimPolicy() !== null;

            if ($capability->executionTargetPolicy()?->strategy === ExecutionTargetStrategy::AcceptStaleSnapshot) {
                $information[] = "Capability [{$capability->name}] deliberately accepts a stale execution-target snapshot.";
            }
        }

        foreach ([
            [
                'needed' => $needsApprovals,
                'contract' => ApprovalReceiptStore::class,
                'label' => 'approval receipt',
            ],
            [
                'needed' => $needsRateLimits,
                'contract' => RateLimitStore::class,
                'label' => 'rate-limit',
            ],
            [
                'needed' => $needsExecutionClaims,
                'contract' => ExecutionClaimStore::class,
                'label' => 'execution-claim',
            ],
        ] as $store) {
            if (! $store['needed']) {
                continue;
            }

            $this->auditStore(
                errors: $errors,
                container: $container,
                contract: $store['contract'],
                label: $store['label'],
            );
        }

        // Advisory: a class sitting in a discovery path that never affirmed the contract is inert,
        // which is safe, and invisible, which is not legible. Verdict cannot tell "unfinished" from
        // "finished and never affirmed" — it cannot see inside the closures (ADR 0017) — so it names
        // the class and lets a human tell the difference. Prints on every run; --strict changes only
        // the exit code.
        //
        // Rendered as a short component line plus a detail line, because components truncate to the
        // terminal width and the reason is the half an operator needs.
        $unaffirmed = [];

        foreach ($discovery->discover()->unaffirmed as $definition) {
            $unaffirmed[] = [
                'class' => $definition->class,
                'detail' => match ($definition->reason) {
                    UnaffirmedDefinition::NO_CONTRACT => 'It does not implement DefinesCapability: unfinished, or finished but never affirmed. '
                        .'Verdict will not register it. Add the contract once every TODO in it is replaced.',
                    UnaffirmedDefinition::NOT_INSTANTIABLE => 'It is abstract or otherwise not instantiable, so it can never be registered. '
                        .'A base class or interface does not belong in a discovery path.',
                    UnaffirmedDefinition::NO_CLASS => 'It does not declare the class its path implies, so it cannot be autoloaded. '
                        .'Check the namespace against the file location.',
                    default => 'It cannot be registered.',
                },
            ];
        }

        // Advisory: a confirmation gate with no approver release policy asks a human to authorize an
        // action while telling them nothing about where the proposal came from. It is legal — Verdict
        // ships no default policy, because a default would be Verdict authorizing a release on the
        // application's behalf (ADR 0026 §1) — and it must stay legal at runtime, so the gap surfaces
        // here at the wiring audit rather than as a failure at challenge creation.
        if ($needsApprovals && ! $releasePolicies->hasRoute(ApproverAudience::source(), ApproverAudience::destination())) {
            $warnings[] = 'Capabilities require confirmation but no context release policy is registered for the approver route '
                .'('.ApproverAudience::source()->identity().' -> '.ApproverAudience::destination()->identity().'); '
                .'approvers are shown no provenance for the proposals they authorize. '
                .'Register a ReleasePolicy for that route to disclose declared upstream sources.';
        }

        // Advisory: the shipped default records nothing. It is legal (correct for tests and for
        // applications routing evidence through a custom writer), so this warns rather than errors.
        // The runtime once-per-process warning (ConsequentialActionUnrecorded) is the louder,
        // action-scoped signal; this is the deploy-time one. See #194.
        if (config('verdict.evidence.recorder', NullEvidenceRecorder::class) === NullEvidenceRecorder::class) {
            $warnings[] = 'Evidence is going to a no-op evidence recorder (NullEvidenceRecorder), the shipped default; '
                .'consequential decisions — confirmations and at-most-once claims — are recorded nowhere. '
                .'Configure a durable recorder via verdict.evidence.recorder to retain an audit trail.';
        }

        // Advisory: config/verdict.php states in comments that the in-memory adapters are unsafe
        // outside local development, and until #146 nothing checked — a comment in a published file
        // is read once, at vendor:publish, and never again. Gated on the framework's own local and
        // testing determination rather than on a list of production-looking environment names, so an
        // environment called anything else is covered by default. Advisory rather than fatal because
        // Verdict does not decide deployment topology: an ephemeral preview environment or a smoke
        // test may legitimately run one, and --strict is the opt-in for CI that wants to block.
        //
        // Rendered as a short component line plus a detail line, for the same reason the unaffirmed
        // findings above are: components truncate to the terminal width, and the config key an
        // operator has to change would be the half that gets cut.
        $nonDurable = [];

        if (! $this->laravel->environment(['local', 'testing'])) {
            foreach ($this->nonDurableAdapters() as $adapter) {
                if (config($adapter['key']) !== $adapter['class']) {
                    continue;
                }

                $nonDurable[] = [
                    'class' => class_basename($adapter['class']),
                    'detail' => $adapter['detail']." Set {$adapter['key']} to a durable implementation.",
                ];
            }
        }

        foreach ($errors as $error) {
            $this->components->error($error);
        }

        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        foreach ($unaffirmed as $finding) {
            $this->components->warn("[{$finding['class']}] is an unaffirmed capability definition.");
            $this->line("   {$finding['detail']}");
        }

        foreach ($nonDurable as $finding) {
            $this->components->warn("[{$finding['class']}] is a non-durable adapter configured outside local development.");
            $this->line("   {$finding['detail']}");
        }

        foreach ($information as $item) {
            $this->components->info($item);
        }

        if ($errors === [] && $warnings === [] && $unaffirmed === [] && $nonDurable === [] && $information === []) {
            $this->components->info('Verdict wiring audit found no applicable capability configuration.');
        }

        $failed = $errors !== []
            || ((bool) $this->option('strict') && ($warnings !== [] || $unaffirmed !== [] || $nonDurable !== []));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The adapters whose own config comments call them unsafe outside local development, each with the
     * consequence that is specific to it. One generic sentence repeated five times would tell an operator
     * which key to change and nothing about why it matters, and the remedies differ in urgency: a
     * process-local rate limit multiplies a security bound by the worker count, while a process-local
     * configuration registry only makes retained evidence unreadable later.
     *
     * @return list<array{key: string, class: class-string, detail: string}>
     */
    private function nonDurableAdapters(): array
    {
        return [
            [
                'key' => 'verdict.evidence.recorder',
                'class' => InMemoryEvidenceRecorder::class,
                'detail' => 'Its state is process-local and unbounded: no record survives the process that wrote it, '
                    .'and a long-lived one (Octane, a queue worker) grows until it restarts.',
            ],
            [
                'key' => 'verdict.approvals.store',
                'class' => InMemoryApprovalReceiptStore::class,
                'detail' => 'A receipt issued in one process is invisible to every other, so a human approval cannot '
                    .'be consumed by the process that executes the action.',
            ],
            [
                'key' => 'verdict.rate_limits.store',
                'class' => InMemoryRateLimitStore::class,
                'detail' => 'Counters do not coordinate across requests, workers, or nodes, so the configured limit '
                    .'binds per process: N workers admit up to N times the limit you configured.',
            ],
            [
                'key' => 'verdict.execution_claims.store',
                'class' => InMemoryExecutionClaimStore::class,
                'detail' => 'Claims are process-local, so at-most-once degrades to at-most-once-per-process and '
                    .'cannot prevent duplicate execution across workers or nodes.',
            ],
            [
                'key' => 'verdict.capability_configurations.store',
                'class' => InMemoryCapabilityConfigurationStore::class,
                'detail' => 'Configuration fingerprints recorded in evidence cannot be expanded back into the '
                    .'configuration that produced them once the process ends, leaving retained evidence unreadable.',
            ],
        ];
    }

    /** @param list<string> $errors */
    private function auditStore(
        array &$errors,
        Container $container,
        string $contract,
        string $label,
    ): void {
        try {
            $store = $container->make($contract);
        } catch (Throwable) {
            $errors[] = "Configured {$label} store could not be resolved.";

            return;
        }

        if (! is_a($store, $contract) || ! $store instanceof DatabaseTableStore) {
            return;
        }

        try {
            if (! $store->hasTable()) {
                $table = $store->table();
                $errors[] = "Configured {$label} store requires missing table [{$table}]. Publish and run Verdict's migrations.";
            }
        } catch (Throwable) {
            $errors[] = "Configured {$label} store could not inspect its table.";
        }
    }
}
