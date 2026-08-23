<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\LiveEvaluationControlArmFactory;
use Fissible\Verdict\Contracts\LiveEvaluationSuiteFactory;
use Fissible\Verdict\Contracts\LiveEvaluationTrialFactory;
use Fissible\Verdict\Support\SystemClock;
use InvalidArgumentException;
use LogicException;

final readonly class LiveEvaluationRunner
{
    public function __construct(
        private bool $liveEnabled,
        private int $maximumTrials,
        private bool $controlEnabled = false,
    ) {}

    public function run(LiveEvaluationSuiteFactory $factory, LiveEvaluationOptions $options, ?Clock $clock = null): LiveEvaluationResult
    {
        $this->assertExecutionIsEnabled($options);
        $this->assertTrialsAreBounded($options);
        $this->assertTrialsAreIsolated($factory, $options);
        $controlFactory = $this->controlFactory($factory, $options);

        $clock ??= new SystemClock;
        $startedAt = $clock->now();

        /** @var array<string,LiveEvaluationScoreCounter> $counters keyed by case id, never by position */
        $counters = [];
        /** @var array<string,LiveEvaluationScoreCounter> $controlCounters */
        $controlCounters = [];
        /** @var array<string,array<string,int>> $pairCounts per security case id, greedy runs only */
        $pairCounts = [];
        /** @var array<string, SafeOutcome> $safeOutcomes */
        $safeOutcomes = [];
        $classifyPairs = $controlFactory !== null && $controlFactory->samplingMode() === ControlSamplingMode::Greedy;
        $identity = null;
        $suite = null;

        // A do/while, not a for: LiveEvaluationOptions rejects trials < 1, so at least one trial
        // always runs and $suite is always assigned before it is read below.
        $trial = 0;
        $haltedAfterTrial = null;

        do {
            // Called before *every* trial, including the first: a process or database already used
            // before this run contaminates trial 0 exactly as it would trial 1.
            $suite = $factory instanceof LiveEvaluationTrialFactory
                ? $factory->makeForTrial($trial)
                : $factory->make();

            if ($identity === null) {
                $identity = TrialSuiteIdentity::of($suite);

                if ($controlFactory !== null) {
                    $this->assertSamplingIsDeclared($suite);
                }

                foreach ($suite->cases as $case) {
                    $counters[$case->id] = new LiveEvaluationScoreCounter;
                    $controlCounters[$case->id] = new LiveEvaluationScoreCounter;

                    if ($classifyPairs && $case->purpose === CasePurpose::Security) {
                        $pairCounts[$case->id] = array_fill_keys(
                            array_map(static fn (ControlPairOutcome $outcome): string => $outcome->value, ControlPairOutcome::cases()),
                            0,
                        );
                        // The identity check every later trial passes covers this too: the safe
                        // outcome read from trial 0's case declaration is the one every trial ran.
                        $safeOutcomes[$case->id] = $case->safeOutcome;
                    }
                }
            } else {
                $identity->assertMatches($suite, $trial);
            }

            $result = $suite->run($clock);

            foreach ($result->cases as $case) {
                $counters[$case->id]->record($case->status, $case->errorClass, $case->assertions, $case->safeOutcome);
            }

            if ($controlFactory !== null) {
                // A fresh build — and therefore a fresh reset — before this arm too: the control
                // arm actually executes the dangerous capability, and its residue leaking into the
                // next guarded observation is ADR 0020's defect one level down.
                $controlSuite = $controlFactory->makeControlForTrial($trial);
                $identity->assertMatches($controlSuite, $trial);

                $controlResult = $controlSuite->run($clock);
                /** @var array<string,CaseResult> $guardedByCase */
                $guardedByCase = [];

                foreach ($result->cases as $case) {
                    $guardedByCase[$case->id] = $case;
                }

                foreach ($controlResult->cases as $case) {
                    $this->assertCaseRanUnguarded($case, $trial);
                    // Always Blocked here, never the case's declared outcome: over-restricted is a
                    // reading of a *guarded* trial (the scope held, the model under-delivered), and
                    // nothing guards the control arm. A mirror trial that fails only the utility
                    // oracle stays failed on the marginal — the pair classifier reads the same
                    // trial as a broken mirror, and the two must not disagree (#276 review).
                    $controlCounters[$case->id]->record($case->status, $case->errorClass, $case->assertions, SafeOutcome::Blocked);

                    if ($classifyPairs && isset($pairCounts[$case->id], $guardedByCase[$case->id])) {
                        $guarded = $guardedByCase[$case->id];
                        $pair = ControlPairOutcome::classify(
                            $guarded->status,
                            $guarded->errorClass,
                            $case->status,
                            $case->errorClass,
                            $safeOutcomes[$case->id] ?? SafeOutcome::Blocked,
                            self::failedFacets($guarded),
                            self::failedFacets($case),
                        );
                        $pairCounts[$case->id][$pair->value]++;
                    }
                }
            }

            // A trial that measured nothing while the harness failed to see something is the one
            // signature an uncooperative model cannot produce: declines and non-attempts are
            // model-side, so a model refusing everything leaves harnessBlind at zero. Stop rather
            // than spend the remaining trials producing nothing. See ADR 0024 §3.
            $blindTrial = $this->trialWasSystematicallyBlind($result);

            $trial++;

            if ($blindTrial !== null) {
                $haltedAfterTrial = $trial;

                break;
            }
        } while ($trial < $options->trials);

        // $suite is the final trial's. Everything read from it below — case metadata, suite name
        // and version, and the reproduction record naming the model and configuration — is covered
        // by the identity check every trial after the first passes, so reporting the last trial's
        // copy reports what all of them ran under.
        $cases = array_map(
            static fn (EvaluationCase $case): LiveEvaluationCaseResult => new LiveEvaluationCaseResult(
                id: $case->id,
                version: $case->version,
                purpose: $case->purpose,
                trustedSetupFingerprint: $case->input->trustedSetupFingerprint(),
                untrustedInputFingerprint: $case->input->untrustedInputFingerprint(),
                score: $counters[$case->id]->score(),
                errorBreakdown: $counters[$case->id]->errorBreakdown(),
                safeOutcome: $case->safeOutcome,
                overRestricted: $counters[$case->id]->overRestricted(),
                failedAssertions: $counters[$case->id]->failedAssertions(),
            ),
            $suite->cases,
        );

        $control = null;

        if ($controlFactory !== null) {
            $control = new LiveEvaluationControlResult(
                samplingMode: $controlFactory->samplingMode(),
                cases: array_map(
                    static fn (EvaluationCase $case): LiveEvaluationControlCaseResult => new LiveEvaluationControlCaseResult(
                        id: $case->id,
                        purpose: $case->purpose,
                        score: $controlCounters[$case->id]->score(),
                        errorBreakdown: $controlCounters[$case->id]->errorBreakdown(),
                        pairCounts: $pairCounts[$case->id] ?? null,
                        safeOutcome: $case->safeOutcome,
                        failedAssertions: $controlCounters[$case->id]->failedAssertions(),
                    ),
                    $suite->cases,
                ),
            );
        }

        return new LiveEvaluationResult(
            suite: $suite->name,
            version: $suite->version,
            reproduction: $suite->reproduction,
            // The count actually run: a halted run must not claim trials it never reached.
            trials: $haltedAfterTrial ?? $options->trials,
            startedAt: $startedAt,
            completedAt: $clock->now(),
            cases: $cases,
            securityThreshold: $this->threshold($cases, CasePurpose::Security, $options->minimumSecurityPassRate, $options->minimumObservations),
            utilityThreshold: $this->threshold($cases, CasePurpose::Utility, $options->minimumUtilityPassRate, $options->minimumObservations),
            control: $control,
            toolShapes: $suite->toolShapes,
            haltedAfterTrial: $haltedAfterTrial,
        );
    }

    /**
     * The control arm's preconditions, refused before any model is invoked: its own configuration
     * gate, and a factory that can actually build the unguarded arm. See ADR 0023.
     */
    private function controlFactory(LiveEvaluationSuiteFactory $factory, LiveEvaluationOptions $options): ?LiveEvaluationControlArmFactory
    {
        if (! $options->controlArm) {
            return null;
        }

        if (! $this->controlEnabled) {
            throw new LogicException(
                'The control arm is disabled by verdict.evaluation.control_enabled. It deliberately '.
                'lets attacks execute unguarded, so it requires its own opt-in in addition to the '.
                'live evaluation gates.'
            );
        }

        if (! $factory instanceof LiveEvaluationControlArmFactory) {
            throw ControlArmRequiresControlFactory::forFactory($factory::class);
        }

        return $factory;
    }

    private function assertSamplingIsDeclared(SecuritySuite $suite): void
    {
        if (! array_key_exists('sampling', $suite->reproduction->components)) {
            throw ControlArmRequiresSamplingDeclaration::forSuite($suite->name);
        }
    }

    /**
     * The one direction of the arm contract Verdict can verify: its own dispositions are the
     * fingerprint of a guarded path, so a control observation carrying one proves the factory
     * built a guarded suite and every pair in the run is invalid. Likewise, a challenge is
     * Verdict-shaped state; its presence on an unguarded arm proves the factory built a guarded
     * suite. See ADR 0023, ADR 0029.
     *
     * Errored cases are checked too, and deliberately: `SecuritySuite::runCase()` keeps the
     * observation evidence when an ASSERTION throws, so a control case that errored on
     * `ExecutionAwaitsApproval` — the outcome most likely to have a challenge behind it — still
     * reaches this check. The count is all the evidence carries; challenge content stays
     * assertion-only per ADR 0029 decision 2.
     */
    /**
     * The facets of an arm's failed assertions, deduplicated — what tells the classifier which
     * side of a filtered-permit case's two-sided oracle failed. An errored arm has no assertion
     * results and yields an empty list, which the classifier reads conservatively.
     *
     * @return list<AssertionFacet>
     */
    private static function failedFacets(CaseResult $case): array
    {
        $facets = [];

        foreach ($case->assertions as $assertion) {
            if (! $assertion->passed) {
                $facets[$assertion->facet->value] = $assertion->facet;
            }
        }

        return array_values($facets);
    }

    private function assertCaseRanUnguarded(CaseResult $case, int $trial): void
    {
        $observation = $case->observation;

        if ($observation === null) {
            return;
        }

        if ($observation->disposition !== null) {
            throw ControlArmAppearsGuarded::forCase($case->id, $trial);
        }

        foreach ($observation->toolCalls as $toolCall) {
            if ($toolCall->disposition !== null) {
                throw ControlArmAppearsGuarded::forCase($case->id, $trial);
            }
        }

        if ($observation->challengeCount > 0) {
            throw ControlArmAppearsGuarded::forCase($case->id, $trial);
        }
    }

    private function assertExecutionIsEnabled(LiveEvaluationOptions $options): void
    {
        if (! $this->liveEnabled) {
            throw new LogicException('Live evaluation is disabled by verdict.evaluation.live_enabled.');
        }

        if (! $options->enabled) {
            throw new LogicException('Live evaluation requires an explicit enabled: true option.');
        }
    }

    /**
     * Refuse a multi-trial run the factory cannot make independent, before any model is invoked.
     *
     * A single trial makes no independence claim, so it needs no reset seam and is left unchanged.
     * See [ADR 0020](../../docs/adr/0020-live-trial-isolation-is-application-owned.md).
     */
    /**
     * Nothing measured this trial, and something the harness could not see. Returns the blind count
     * when that holds, null otherwise. See {@see ThresholdCoverage::isSystematicallyBlind()}.
     */
    private function trialWasSystematicallyBlind(SuiteResult $result): ?int
    {
        $evaluated = 0;
        $blind = 0;
        $blindValues = array_map(
            static fn (LiveErrorCategory $category): string => $category->value,
            ThresholdCoverage::harnessBlindCategories(),
        );

        foreach ($result->cases as $case) {
            if ($case->status === CaseStatus::Passed || $case->status === CaseStatus::Failed) {
                $evaluated++;

                continue;
            }

            $category = LiveErrorCategory::fromErrorClass($case->errorClass);

            if ($category !== null && in_array($category->value, $blindValues, true)) {
                $blind++;
            }
        }

        return $evaluated === 0 && $blind > 0 ? $blind : null;
    }

    private function assertTrialsAreIsolated(LiveEvaluationSuiteFactory $factory, LiveEvaluationOptions $options): void
    {
        if ($options->trials > 1 && ! $factory instanceof LiveEvaluationTrialFactory) {
            throw LiveEvaluationRequiresTrialIsolation::forTrials($options->trials, $factory::class);
        }
    }

    private function assertTrialsAreBounded(LiveEvaluationOptions $options): void
    {
        if ($this->maximumTrials < 1) {
            throw new InvalidArgumentException('The Verdict live evaluation maximum trials configuration must be a positive integer.');
        }

        if ($options->trials > $this->maximumTrials) {
            throw new InvalidArgumentException("Live evaluation trials may not exceed the configured maximum of {$this->maximumTrials}.");
        }
    }

    /** @param list<LiveEvaluationCaseResult> $cases */
    private function threshold(
        array $cases,
        CasePurpose $purpose,
        float $minimumPassRate,
        int $minimumObservations,
    ): LiveEvaluationThreshold {
        $passed = 0;
        $failed = 0;
        $errors = 0;
        $pending = 0;
        /** @var array<string,int> $breakdown */
        $breakdown = [];
        /** @var array<string,ThresholdCoverage> $caseCoverage */
        $caseCoverage = [];

        foreach ($cases as $case) {
            if ($case->purpose !== $purpose) {
                continue;
            }

            $passed += $case->score->passed;
            $failed += $case->score->failed;
            $errors += $case->score->errors;
            $pending += $case->score->pending;

            // Summed across the purpose so coverage can tell an outcome that could have been a
            // measurement apart from one that never could. Sparse, like the per-case map.
            foreach ($case->errorBreakdown as $category => $count) {
                $breakdown[$category] = ($breakdown[$category] ?? 0) + $count;
            }

            // Kept per case as well: identical purpose sums can hide a case that was never
            // measured, which is what the per-case floor exists to see. See ADR 0022.
            $caseCoverage[$case->id] = $case->coverage();
        }

        $score = new Score($passed, $failed, $errors, $pending);

        return new LiveEvaluationThreshold(
            purpose: $purpose,
            minimumPassRate: $minimumPassRate,
            score: $score,
            coverage: ThresholdCoverage::from($score, $breakdown),
            minimumObservations: $minimumObservations,
            caseCoverage: $caseCoverage,
        );
    }
}
