<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support\Evaluation;

use Closure;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\Verdict\Evaluation\AccountRecoveryAttackPack;
use Fissible\Verdict\Evaluation\AccountRecoveryAttackPackConfig;
use Fissible\Verdict\Evaluation\CaseInput;
use Fissible\Verdict\Evaluation\Observation;
use Fissible\Verdict\Evaluation\SecuritySuite;
use Fissible\Verdict\Evaluation\ToolObservation;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use RuntimeException;

/**
 * Reference wiring for AccountRecoveryAttackPack: the secure runner its tests
 * assert against, shared with the committed-baseline run so the baseline pins
 * exactly the behaviour the tests specify.
 */
final class AccountRecoveryReference
{
    public const string SUITE = 'account-recovery-attack-pack';

    public const string VERSION = '1';

    public static function suite(): SecuritySuite
    {
        $config = self::config();

        return new SecuritySuite(
            self::SUITE,
            self::VERSION,
            (new AccountRecoveryAttackPack($config))->cases(self::secureRunner($config)),
        );
    }

    public static function config(): AccountRecoveryAttackPackConfig
    {
        return new AccountRecoveryAttackPackConfig(
            identityVerificationCapability: 'identity.verify',
            accountUnlockCapability: 'accounts.unlock',
            mfaResetCapability: 'accounts.mfa_reset',
            accountId: 'acct-verdict-synthetic-recovery-9001',
            sessionId: 'sess-verdict-synthetic-recovery-4400',
        );
    }

    public static function sideEffect(string $recoveryCapability): string
    {
        return "{$recoveryCapability}.executed";
    }

    public static function toolObservation(
        string $capability,
        bool $executed,
        ?string $argumentFingerprint = null,
        ?Disposition $disposition = null,
    ): ToolObservation {
        $disposition ??= $executed ? Disposition::Permit : Disposition::Deny;

        return new ToolObservation(
            capability: $capability,
            argumentFingerprint: $argumentFingerprint ?? ArgumentFingerprint::make([
                'capability' => $capability,
                'executed' => $executed,
                'disposition' => $disposition->value,
            ]),
            disposition: $disposition,
            executed: $executed,
        );
    }

    public static function secureRunner(AccountRecoveryAttackPackConfig $config): Closure
    {
        return function (CaseInput $input) use ($config): Observation {
            $operation = $input->trustedSetup['operation'] ?? null;
            $verified = ($input->trustedSetup['identity_verified'] ?? false) === true;
            $recoveryCapability = match ($operation) {
                'account_unlock' => $config->accountUnlockCapability,
                'mfa_reset' => $config->mfaResetCapability,
                default => throw new RuntimeException("Unexpected operation [{$operation}]."),
            };

            if (! $verified) {
                return new Observation(
                    disposition: Disposition::Deny,
                    executed: false,
                    toolCalls: [
                        self::toolObservation(
                            $config->identityVerificationCapability,
                            false,
                            disposition: Disposition::Deny,
                        ),
                        self::toolObservation($recoveryCapability, false, disposition: Disposition::Deny),
                    ],
                );
            }

            return new Observation(
                disposition: Disposition::Permit,
                executed: true,
                output: ['status' => 'recovered', 'operation' => $operation],
                toolCalls: [
                    self::toolObservation(
                        $config->identityVerificationCapability,
                        true,
                        disposition: Disposition::Permit,
                    ),
                    self::toolObservation($recoveryCapability, true, disposition: Disposition::Permit),
                ],
                sideEffects: [self::sideEffect($recoveryCapability)],
            );
        };
    }
}
