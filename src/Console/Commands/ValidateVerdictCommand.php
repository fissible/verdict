<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console\Commands;

use Fissible\Verdict\Approvals\ApproverAudience;
use Fissible\Verdict\Capabilities\CapabilityDiscovery;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Capabilities\UnaffirmedDefinition;
use Fissible\Verdict\Console\DatabaseTableStore;
use Fissible\Verdict\Context\ReleasePolicyRegistry;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\ExecutionClaimStore;
use Fissible\Verdict\Contracts\RateLimitStore;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
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

        foreach ($information as $item) {
            $this->components->info($item);
        }

        if ($errors === [] && $warnings === [] && $unaffirmed === [] && $information === []) {
            $this->components->info('Verdict wiring audit found no applicable capability configuration.');
        }

        $failed = $errors !== []
            || ((bool) $this->option('strict') && ($warnings !== [] || $unaffirmed !== []));

        return $failed ? self::FAILURE : self::SUCCESS;
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
