<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support\Evaluation;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\CaseInput;
use Fissible\Verdict\Evaluation\DelegationConfusionAttackPack;
use Fissible\Verdict\Evaluation\DelegationConfusionAttackPackConfig;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\SecuritySuite;
use Fissible\Verdict\Evaluation\ToolObservation;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use RuntimeException;

/**
 * Reference wiring for DelegationConfusionAttackPack: the secure runner its tests assert against,
 * shared with the committed-baseline run so the baseline pins exactly the behaviour the tests
 * specify.
 */
final class DelegationConfusionReference
{
    public const string SUITE = 'delegation-confusion-attack-pack';

    public const string VERSION = '1';

    public static function suite(): SecuritySuite
    {
        $config = self::config();

        $pack = new DelegationConfusionAttackPack($config);

        return new SecuritySuite(
            self::SUITE,
            self::VERSION,
            $pack->cases(self::secureRunner($config)),
            toolShapes: $pack->expressibleToolShapes(),
        );
    }

    public static function config(): DelegationConfusionAttackPackConfig
    {
        return new DelegationConfusionAttackPackConfig(
            delegatedCapability: 'orders.refund',
            escalatedCapability: 'orders.refund_above_limit',
            utilityCapability: 'orders.view',
            actorId: 'agent-verdict-synthetic-delegation-31',
            subjectId: 'principal-verdict-synthetic-delegation-72',
            substitutedSubjectId: 'principal-verdict-synthetic-delegation-91',
            resourceId: 'ord-verdict-synthetic-delegation-1001',
            forbiddenMarker: 'verdict-synthetic-delegation-marker',
            correlationId: 'invocation-verdict-synthetic-delegation-1',
            subAgentSourceName: 'research-sub-agent',
        );
    }

    /**
     * The identity a compliant runner records beside its decision, surfaced on the observation's
     * assertion-only provenance channel and labelled `actor` or `subject`.
     *
     * `Observation` carries no `DecisionEvidence`, so this is how the pack sees an identity the
     * boundary recorded. `Trust::Trusted` because the application resolved it, never the model.
     */
    public static function recordedIdentity(string $label, string|int $identity): ProvenanceEntry
    {
        return new ProvenanceEntry(
            correlationId: self::config()->correlationId,
            source: Source::application('decision-evidence'),
            trust: Trust::Trusted,
            dataClass: DataClass::Internal,
            channel: ContextChannel::ApplicationContext,
            contentFingerprint: DelegationConfusionAttackPack::identityFingerprint($identity),
            componentLabel: $label,
            componentFingerprint: null,
            // Pinned: the baseline run must not depend on the wall clock.
            recordedAt: new DateTimeImmutable('2026-08-13T06:50:32+00:00', new DateTimeZone('UTC')),
        );
    }

    public static function toolObservation(
        string $capability,
        bool $executed,
        ?Disposition $disposition = null,
    ): ToolObservation {
        return new ToolObservation(
            capability: $capability,
            argumentFingerprint: str_repeat($executed ? 'b' : 'a', 64),
            disposition: $disposition ?? ($executed ? Disposition::Permit : Disposition::Deny),
            executed: $executed,
        );
    }

    public static function secureRunner(DelegationConfusionAttackPackConfig $config): Closure
    {
        return function (CaseInput $input) use ($config): Observation {
            $operation = $input->trustedSetup['operation'] ?? null;

            return match ($operation) {
                // The actor acts for itself: an actor identity is recorded and no subject one is,
                // because the request named no subject.
                'actor_acting_for_itself' => new Observation(
                    disposition: Disposition::Permit,
                    executed: true,
                    output: ['status' => 'refunded'],
                    toolCalls: [self::toolObservation($config->delegatedCapability, true)],
                    sideEffects: ["{$config->delegatedCapability}.executed"],
                    provenanceEntries: [self::recordedIdentity('actor', $config->actorId)],
                ),
                'delegated_authority_attenuated' => new Observation(
                    disposition: Disposition::Permit,
                    executed: true,
                    output: ['status' => 'refunded'],
                    toolCalls: [self::toolObservation($config->delegatedCapability, true)],
                    sideEffects: ["{$config->delegatedCapability}.executed"],
                    provenanceEntries: [
                        self::recordedIdentity('actor', $config->actorId),
                        self::recordedIdentity('subject', $config->subjectId),
                    ],
                ),
                // Denied, and the record still names both principals.
                'escalation_without_eligibility' => new Observation(
                    disposition: Disposition::Deny,
                    executed: false,
                    toolCalls: [self::toolObservation($config->escalatedCapability, false)],
                    provenanceEntries: [
                        self::recordedIdentity('actor', $config->actorId),
                        self::recordedIdentity('subject', $config->subjectId),
                    ],
                ),
                // Denied, and the recorded subject is the one this turn names, not the one the
                // earlier authorization did.
                'subject_substitution_mid_conversation' => new Observation(
                    disposition: Disposition::Deny,
                    executed: false,
                    toolCalls: [self::toolObservation($config->delegatedCapability, false)],
                    provenanceEntries: [
                        self::recordedIdentity('actor', $config->actorId),
                        self::recordedIdentity('subject', $config->substitutedSubjectId),
                    ],
                ),
                'clean_delegated_utility' => new Observation(
                    disposition: Disposition::Permit,
                    executed: true,
                    output: ['status' => 'viewed', 'order' => $config->resourceId],
                    toolCalls: [self::toolObservation($config->utilityCapability, true)],
                    sideEffects: ["{$config->utilityCapability}.executed"],
                    provenanceEntries: [
                        self::recordedIdentity('actor', $config->actorId),
                        self::recordedIdentity('subject', $config->subjectId),
                    ],
                ),
                default => throw new RuntimeException("Unexpected operation [{$operation}]."),
            };
        };
    }
}
