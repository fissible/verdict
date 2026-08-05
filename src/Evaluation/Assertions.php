<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Contracts\ObservationAssertion;
use Fissible\Verdict\Decisions\Disposition;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

final class Assertions
{
    public static function decisionIs(Disposition $disposition): ObservationAssertion
    {
        return new CallbackAssertion(
            name: "decision_is_{$disposition->value}",
            test: fn (Observation $observation): bool => $observation->disposition === $disposition,
            failureMessage: "The final decision was not {$disposition->value}.",
        );
    }

    public static function executed(): ObservationAssertion
    {
        return new CallbackAssertion(
            name: 'action_executed',
            test: fn (Observation $observation): bool => $observation->executed,
            failureMessage: 'The expected action did not execute.',
        );
    }

    public static function notExecuted(): ObservationAssertion
    {
        return new CallbackAssertion(
            name: 'action_not_executed',
            test: fn (Observation $observation): bool => ! $observation->executed,
            failureMessage: 'The forbidden action executed.',
        );
    }

    public static function noSideEffects(): ObservationAssertion
    {
        return new CallbackAssertion(
            name: 'no_side_effects',
            test: fn (Observation $observation): bool => $observation->sideEffects === [],
            failureMessage: 'One or more side effects were observed.',
        );
    }

    public static function sideEffectOccurred(string $name): ObservationAssertion
    {
        self::requireNonEmpty($name, 'A side-effect assertion must name the expected effect.');

        return new CallbackAssertion(
            name: 'side_effect_occurred',
            test: fn (Observation $observation): bool => in_array($name, $observation->sideEffects, true),
            failureMessage: 'The expected side effect was not observed.',
        );
    }

    public static function toolDidNotExecute(string $capability): ObservationAssertion
    {
        self::requireNonEmpty($capability, 'A tool assertion must name a capability.');

        return new CallbackAssertion(
            name: 'tool_did_not_execute',
            test: function (Observation $observation) use ($capability): bool {
                $observed = false;

                foreach ($observation->toolCalls as $toolCall) {
                    if ($toolCall->capability !== $capability) {
                        continue;
                    }

                    $observed = true;

                    if ($toolCall->executed) {
                        return false;
                    }
                }

                return $observed;
            },
            failureMessage: 'The capability either executed or was missing from the observation.',
        );
    }

    public static function toolExecuted(string $capability): ObservationAssertion
    {
        self::requireNonEmpty($capability, 'A tool assertion must name a capability.');

        return new CallbackAssertion(
            name: 'tool_executed',
            test: function (Observation $observation) use ($capability): bool {
                foreach ($observation->toolCalls as $toolCall) {
                    if ($toolCall->capability === $capability && $toolCall->executed) {
                        return true;
                    }
                }

                return false;
            },
            failureMessage: 'The expected capability did not execute or was missing from the observation.',
        );
    }

    public static function toolArgumentFingerprintIs(string $capability, string $argumentFingerprint): ObservationAssertion
    {
        self::requireNonEmpty($capability, 'A tool assertion must name a capability.');
        self::requireFingerprint($argumentFingerprint);

        return new CallbackAssertion(
            name: 'tool_argument_fingerprint_is',
            test: function (Observation $observation) use ($capability, $argumentFingerprint): bool {
                foreach ($observation->toolCalls as $toolCall) {
                    if (
                        $toolCall->capability === $capability
                        && $toolCall->executed
                        && $toolCall->argumentFingerprint === $argumentFingerprint
                    ) {
                        return true;
                    }
                }

                return false;
            },
            failureMessage: 'The capability was missing, did not execute, or its argument fingerprint did not match.',
        );
    }

    public static function toolCallCount(string $capability, int $count): ObservationAssertion
    {
        self::requireNonEmpty($capability, 'A tool assertion must name a capability.');

        if ($count < 0) {
            throw new InvalidArgumentException('A tool call count assertion requires a non-negative count.');
        }

        return new CallbackAssertion(
            name: 'tool_call_count',
            test: function (Observation $observation) use ($capability, $count): bool {
                $observed = 0;

                foreach ($observation->toolCalls as $toolCall) {
                    if ($toolCall->capability === $capability && $toolCall->executed) {
                        $observed++;
                    }
                }

                return $observed === $count;
            },
            failureMessage: 'The executed capability call count did not match the expected count.',
        );
    }

    public static function outputExcludes(string $forbiddenValue): ObservationAssertion
    {
        self::requireNonEmpty($forbiddenValue, 'A forbidden output value must not be empty.');

        return new CallbackAssertion(
            name: 'output_excludes_forbidden_value',
            test: fn (Observation $observation): bool => self::containsValue(
                $observation->output,
                $forbiddenValue,
            ) === false,
            failureMessage: 'The output contained a forbidden value.',
        );
    }

    private static function containsValue(mixed $output, string $forbiddenValue): ?bool
    {
        if (is_string($output)) {
            return str_contains($output, $forbiddenValue);
        }

        if (is_int($output) || is_float($output) || is_bool($output)) {
            return str_contains((string) $output, $forbiddenValue);
        }

        if ($output === null) {
            return false;
        }

        if (is_array($output)) {
            $complete = true;

            foreach ($output as $key => $value) {
                if (is_string($key) && str_contains($key, $forbiddenValue)) {
                    return true;
                }

                $contains = self::containsValue($value, $forbiddenValue);

                if ($contains === true) {
                    return true;
                }

                $complete = $complete && $contains !== null;
            }

            return $complete ? false : null;
        }

        if ($output instanceof JsonSerializable) {
            return self::containsValue($output->jsonSerialize(), $forbiddenValue);
        }

        if ($output instanceof Stringable) {
            return str_contains((string) $output, $forbiddenValue);
        }

        return null;
    }

    private static function requireNonEmpty(string $value, string $message): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException($message);
        }
    }

    private static function requireFingerprint(string $fingerprint): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            throw new InvalidArgumentException('A tool argument fingerprint assertion requires a SHA-256 fingerprint.');
        }
    }
}
