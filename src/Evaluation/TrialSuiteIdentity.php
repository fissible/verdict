<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

/**
 * The part of a SecuritySuite that must not change between trials of one live evaluation run.
 *
 * Case *order* is deliberately absent: results are aggregated by case identity, so a factory that
 * returns its cases in a different order each trial is harmless. A case whose identity or immutable
 * metadata changed is not — it means a later trial measured something the earlier trials did not.
 *
 * See [ADR 0020](../../docs/adr/0020-live-trial-isolation-is-application-owned.md).
 *
 * @internal
 */
final readonly class TrialSuiteIdentity
{
    /**
     * @param  array<string,string>  $cases  case id => descriptor of that case's immutable metadata
     * @param  array<string,string>  $reproduction  canonicalized ReproductionMetadata components
     */
    private function __construct(
        private string $suite,
        private string $version,
        private array $cases,
        private array $reproduction,
    ) {}

    public static function of(SecuritySuite $suite): self
    {
        $cases = [];

        foreach ($suite->cases as $case) {
            $cases[$case->id] = implode('|', [
                $case->version,
                $case->purpose->value,
                $case->input->trustedSetupFingerprint(),
                $case->input->untrustedInputFingerprint(),
            ]);
        }

        ksort($cases);

        // Reproduction metadata names the model, provider, prompt configuration, and policy
        // revision a result was produced under. The runner reports one such record for the whole
        // aggregate, so a trial that changed any of it would be averaged into a report claiming a
        // configuration it did not run under. Sorted so declaration order carries no meaning.
        $reproduction = $suite->reproduction->components;
        ksort($reproduction);

        return new self($suite->name, $suite->version, $cases, $reproduction);
    }

    /** @throws TrialSuiteChanged */
    public function assertMatches(SecuritySuite $suite, int $trial): void
    {
        $other = self::of($suite);

        if ($this->suite !== $other->suite || $this->version !== $other->version) {
            throw TrialSuiteChanged::suite($trial, "{$this->suite}@{$this->version}", "{$other->suite}@{$other->version}");
        }

        $expected = array_keys($this->cases);
        $actual = array_keys($other->cases);

        if ($expected !== $actual) {
            throw TrialSuiteChanged::cases(
                $trial,
                array_values(array_diff($expected, $actual)),
                array_values(array_diff($actual, $expected)),
            );
        }

        foreach ($this->cases as $id => $descriptor) {
            if ($other->cases[$id] !== $descriptor) {
                throw TrialSuiteChanged::caseMetadata($trial, $id);
            }
        }

        if ($this->reproduction !== $other->reproduction) {
            throw TrialSuiteChanged::reproduction(
                $trial,
                self::describe($this->reproduction),
                self::describe($other->reproduction),
            );
        }
    }

    /** @param array<string,string> $components */
    private static function describe(array $components): string
    {
        if ($components === []) {
            return '(none)';
        }

        $pairs = [];

        foreach ($components as $name => $version) {
            $pairs[] = "{$name}={$version}";
        }

        return implode(', ', $pairs);
    }
}
