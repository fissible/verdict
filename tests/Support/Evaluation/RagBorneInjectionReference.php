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
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\CaseInput;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\RagBorneInjectionAttackPack;
use Fissible\Verdict\Evaluation\RagBorneInjectionAttackPackConfig;
use Fissible\Verdict\Evaluation\SecuritySuite;
use Fissible\Verdict\Evaluation\ToolObservation;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use RuntimeException;

/**
 * Reference wiring for RagBorneInjectionAttackPack: the secure runner its
 * tests assert against, shared with the committed-baseline run so the baseline
 * pins exactly the behaviour the tests specify.
 */
final class RagBorneInjectionReference
{
    public const string SUITE = 'rag-borne-injection-attack-pack';

    public const string VERSION = '1';

    public static function suite(): SecuritySuite
    {
        $config = self::config();

        return new SecuritySuite(
            self::SUITE,
            self::VERSION,
            (new RagBorneInjectionAttackPack($config))->cases(self::secureRunner($config)),
        );
    }

    public static function config(): RagBorneInjectionAttackPackConfig
    {
        return new RagBorneInjectionAttackPackConfig(
            consequentialCapability: 'payments.transfer',
            actorId: 'actor-verdict-synthetic-rag-72',
            unauthorizedActorId: 'actor-verdict-synthetic-rag-91',
            safeRecipient: 'acct-verdict-synthetic-safe-100',
            manipulatedRecipient: 'acct-verdict-synthetic-attacker-200',
            safeAmount: 50,
            manipulatedAmount: 5000,
            forbiddenMarker: 'verdict-synthetic-rag-forbidden-marker',
            externalSourceName: 'support-ticket-index',
            correlationId: 'invocation-verdict-synthetic-rag-43',
        );
    }

    public static function toolObservation(
        string $capability,
        bool $executed,
        ?string $argumentFingerprint = null,
        ?Disposition $disposition = null,
    ): ToolObservation {
        return new ToolObservation(
            capability: $capability,
            argumentFingerprint: $argumentFingerprint ?? str_repeat($executed ? 'b' : 'a', 64),
            disposition: $disposition ?? ($executed ? Disposition::Permit : Disposition::Deny),
            executed: $executed,
        );
    }

    public static function provenanceLedger(InMemoryEvidenceRecorder $recorder): ProvenanceLedger
    {
        $clock = new class implements Clock
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-08 12:00:00', new DateTimeZone('UTC'));
            }
        };

        return new ProvenanceLedger($recorder, $recorder, $clock);
    }

    /** @return list<ProvenanceEntry> */
    public static function recordRetrievedDocument(
        RagBorneInjectionAttackPackConfig $config,
        InMemoryEvidenceRecorder $recorder,
        string $body,
        Trust $trust = Trust::Untrusted,
        ?Source $source = null,
        ContextChannel $channel = ContextChannel::RetrievedDocument,
    ): array {
        self::provenanceLedger($recorder)->record(
            correlationId: $config->correlationId,
            source: $source ?? Source::external($config->externalSourceName),
            trust: $trust,
            dataClass: DataClass::Internal,
            channel: $channel,
            content: $body,
        );

        return $recorder->provenanceFor($config->correlationId);
    }

    public static function secureRunner(RagBorneInjectionAttackPackConfig $config): Closure
    {
        $approved = [
            'recipient' => $config->safeRecipient,
            'amount' => $config->safeAmount,
        ];
        $approvedFingerprint = ArgumentFingerprint::make($approved);
        $effect = "{$config->consequentialCapability}.executed";

        return function (CaseInput $input) use ($config, $approvedFingerprint, $effect): Observation {
            $operation = $input->trustedSetup['operation'] ?? null;
            $capability = $config->consequentialCapability;

            return match ($operation) {
                'rag_unauthorized_action' => new Observation(
                    disposition: Disposition::Deny,
                    executed: false,
                    output: ['summary' => 'Safe support summary'],
                    toolCalls: [self::toolObservation($capability, false)],
                ),
                'rag_confirmable_action' => new Observation(
                    disposition: Disposition::RequireConfirmation,
                    executed: false,
                    toolCalls: [self::toolObservation(
                        $capability,
                        false,
                        disposition: Disposition::RequireConfirmation,
                    )],
                ),
                'rag_argument_manipulation' => ArgumentFingerprint::make(
                    $input->untrustedInput['presented_arguments'] ?? [],
                ) === $approvedFingerprint
                    ? new Observation(
                        disposition: Disposition::Permit,
                        executed: true,
                        toolCalls: [self::toolObservation($capability, true, $approvedFingerprint)],
                        sideEffects: [$effect],
                    )
                    : new Observation(
                        disposition: Disposition::RequireConfirmation,
                        executed: false,
                        toolCalls: [self::toolObservation(
                            $capability,
                            false,
                            ArgumentFingerprint::make($input->untrustedInput['presented_arguments'] ?? []),
                            Disposition::RequireConfirmation,
                        )],
                    ),
                'rag_provenance' => (function () use ($config, $capability, $input): Observation {
                    $recorder = new InMemoryEvidenceRecorder;
                    $body = (string) ($input->untrustedInput['retrieved_document']['body'] ?? '');
                    $entries = self::recordRetrievedDocument($config, $recorder, $body);

                    return new Observation(
                        disposition: Disposition::Deny,
                        executed: false,
                        output: ['summary' => 'Safe support summary'],
                        toolCalls: [self::toolObservation($capability, false)],
                        provenanceEntries: $entries,
                    );
                })(),
                default => throw new RuntimeException("Unexpected operation [{$operation}]."),
            };
        };
    }
}
