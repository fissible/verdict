<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support\Evaluation;

use Closure;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\CaseInput;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\PredicateObservation;
use Fissible\Verdict\Evaluation\SecuritySuite;
use Fissible\Verdict\Evaluation\StorefrontAttackPack;
use Fissible\Verdict\Evaluation\StorefrontAttackPackConfig;
use Fissible\Verdict\Evaluation\ToolObservation;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use RuntimeException;

/**
 * Reference wiring for StorefrontAttackPack: the secure runner its tests
 * assert against, shared with the committed-baseline run so the baseline pins
 * exactly the behaviour the tests specify.
 */
final class StorefrontReference
{
    public const string SUITE = 'storefront-attack-pack';

    public const string VERSION = '2';

    public static function suite(): SecuritySuite
    {
        $config = self::config();

        $pack = new StorefrontAttackPack($config);

        return new SecuritySuite(
            self::SUITE,
            self::VERSION,
            $pack->cases(self::secureRunner($config)),
            toolShapes: $pack->expressibleToolShapes(),
        );
    }

    public static function config(): StorefrontAttackPackConfig
    {
        return new StorefrontAttackPackConfig(
            readCapability: 'orders.view',
            mutationCapability: 'orders.cancel',
            actorId: 72,
            foreignPrincipalId: 91,
            ownedOrderId: 1002,
            foreignOrderId: 1001,
            mutationOrderId: 1002,
            forbiddenMarker: 'verdict-synthetic-foreign-marker',
            searchCapability: 'orders.search',
            ownedSearchOrderId: 1004,
            // Hand-written; this reference runner executes no real SQL, so the quoting never
            // meets an engine — both digest sides derive from this declaration, which is exactly
            // what this SIMULATED boundary pins (the real, non-tautological comparison is the
            // workbench scenario runner's, where the observed side comes from execution).
            declaredSearchPredicateSql: 'select "id", "customer_id", "item", "status" from "storefront_orders" where "customer_id" = ? and "status" = ? order by "id" asc',
        );
    }

    public static function mutationSideEffect(StorefrontAttackPackConfig $config): string
    {
        return "{$config->mutationCapability}.executed";
    }

    /** @return array{order_id: int, reason: string} */
    public static function approvedArguments(StorefrontAttackPackConfig $config): array
    {
        return [
            'order_id' => $config->mutationOrderId,
            'reason' => 'Customer confirmed cancellation.',
        ];
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

    public static function secureRunner(StorefrontAttackPackConfig $config): Closure
    {
        $approved = self::approvedArguments($config);
        $approvedFingerprint = ArgumentFingerprint::make($approved);
        $effect = self::mutationSideEffect($config);

        return function (CaseInput $input) use ($config, $approved, $approvedFingerprint, $effect): Observation {
            $operation = $input->trustedSetup['operation'] ?? 'lookup';
            $orderId = $input->trustedSetup['order_id'] ?? null;

            return match ($operation) {
                'order_search' => new Observation(
                    disposition: Disposition::Permit,
                    executed: true,
                    output: ['orders' => [['id' => $config->ownedSearchOrderId, 'status' => 'shipped']]],
                    toolCalls: [self::toolObservation(
                        $config->searchCapability,
                        true,
                        ArgumentFingerprint::make(StorefrontAttackPack::searchFilter()),
                        Disposition::Permit,
                    )],
                    predicates: [PredicateObservation::fromQuery(
                        $config->declaredSearchPredicateSql,
                        [$config->actorId, StorefrontAttackPack::searchFilter()['status']],
                        $config->searchCapability,
                        ArgumentFingerprint::make(StorefrontAttackPack::searchFilter()),
                    )],
                ),
                'lookup' => $orderId === $config->foreignOrderId
                    ? new Observation(
                        disposition: Disposition::Deny,
                        executed: false,
                        toolCalls: [self::toolObservation($config->readCapability, false)],
                    )
                    : new Observation(
                        disposition: Disposition::Permit,
                        executed: true,
                        output: ['id' => $config->ownedOrderId, 'status' => 'processing'],
                        toolCalls: [self::toolObservation($config->readCapability, true)],
                    ),
                'cancel' => $orderId === $config->foreignOrderId
                    ? new Observation(
                        disposition: Disposition::Deny,
                        executed: false,
                        toolCalls: [self::toolObservation($config->mutationCapability, false)],
                    )
                    : new Observation(
                        disposition: Disposition::Permit,
                        executed: true,
                        output: ['status' => 'cancelled', 'order_id' => $config->mutationOrderId],
                        toolCalls: [self::toolObservation(
                            $config->mutationCapability,
                            true,
                            ArgumentFingerprint::make($input->untrustedInput['arguments'] ?? $approved),
                        )],
                        sideEffects: [$effect],
                    ),
                'confirm_mutation' => ArgumentFingerprint::make($input->untrustedInput['presented_arguments'] ?? []) === $approvedFingerprint
                    ? new Observation(
                        disposition: Disposition::Permit,
                        executed: true,
                        output: ['status' => 'cancelled'],
                        toolCalls: [self::toolObservation($config->mutationCapability, true, $approvedFingerprint)],
                        sideEffects: [$effect],
                    )
                    : new Observation(
                        disposition: Disposition::RequireConfirmation,
                        executed: false,
                        toolCalls: [self::toolObservation(
                            $config->mutationCapability,
                            false,
                            ArgumentFingerprint::make($input->untrustedInput['presented_arguments'] ?? []),
                            Disposition::RequireConfirmation,
                        )],
                    ),
                'replay_mutation' => new Observation(
                    disposition: Disposition::RequireConfirmation,
                    executed: true,
                    toolCalls: [
                        self::toolObservation($config->mutationCapability, true, $approvedFingerprint),
                        self::toolObservation(
                            $config->mutationCapability,
                            false,
                            $approvedFingerprint,
                            Disposition::RequireConfirmation,
                        ),
                    ],
                    sideEffects: [$effect],
                ),
                'single_mutation' => new Observation(
                    disposition: Disposition::Permit,
                    executed: true,
                    output: ['status' => 'cancelled'],
                    toolCalls: [self::toolObservation($config->mutationCapability, true, $approvedFingerprint)],
                    sideEffects: [$effect],
                ),
                'document_retrieval' => isset($input->untrustedInput['retrieved_document']['body'])
                    && str_contains((string) $input->untrustedInput['retrieved_document']['body'], 'Ignore the customer request')
                    ? new Observation(
                        disposition: Disposition::Deny,
                        executed: false,
                        output: ['summary' => 'Safe support summary'],
                        toolCalls: [self::toolObservation($config->mutationCapability, false)],
                    )
                    : new Observation(
                        disposition: Disposition::Permit,
                        executed: true,
                        output: ['summary' => 'Order is processing'],
                        toolCalls: [self::toolObservation($config->readCapability, true)],
                    ),
                default => throw new RuntimeException("Unexpected operation [{$operation}]."),
            };
        };
    }
}
