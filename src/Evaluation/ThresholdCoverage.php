<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

/**
 * How much of a purpose's live evaluation population actually produced a measurement.
 *
 * This is a **coverage adequacy** model, not a statistical confidence one. It answers "did enough of
 * what could have been measured get measured to support a verdict?" — not "how confident are we in
 * the rate?" A run may have excellent coverage and still be far too small to generalise from; that
 * is what the adopter-controlled absolute floor is for.
 *
 * Three populations, deliberately separated:
 *
 * - **evaluated** — passed or failed. The observations a pass rate is computed from.
 * - **measurable but unmeasured** — the model declined, never attempted the capability, the harness
 *   could not observe the outcome, or the error was uncategorized. Each of these *could* have been a
 *   measurement on a different run, and their presence is what erodes a verdict's support.
 * - **structurally unavailable** — cases that cannot be measured live at all (`not_expressible`) or
 *   were blocked on an unlanded dependency (`pending`). These are permanent properties of the suite,
 *   not signals about this run, so counting them against coverage would make a suite containing any
 *   such case permanently insufficient.
 *
 * See [ADR 0021](../../docs/adr/0021-coverage-adequacy-gates-a-live-verdict.md).
 */
final readonly class ThresholdCoverage
{
    public function __construct(
        public int $evaluated,
        public int $measurableButUnmeasured,
        public int $structurallyUnavailable,
        public int $harnessBlind = 0,
    ) {}

    /**
     * @param  array<string,int>  $errorBreakdown  keyed by LiveErrorCategory value
     */
    public static function from(Score $score, array $errorBreakdown): self
    {
        $unmeasured = 0;

        foreach (self::measurableCategories() as $category) {
            $unmeasured += $errorBreakdown[$category->value] ?? 0;
        }

        $blind = 0;

        foreach (self::harnessBlindCategories() as $category) {
            $blind += $errorBreakdown[$category->value] ?? 0;
        }

        // Pending is a case status rather than an error category, so it is carried on the Score.
        $structural = ($errorBreakdown[LiveErrorCategory::NotExpressible->value] ?? 0) + $score->pending;

        return new self($score->evaluated(), $unmeasured, $structural, $blind);
    }

    /**
     * An outcome where **the model** could have acted and did not.
     *
     * `not_expressible` is deliberately absent: a case that cannot be expressed against a live agent
     * will never produce an observation no matter how the run goes. So are the harness-blind
     * categories — see {@see harnessBlindCategories()} and
     * [ADR 0024](../../docs/adr/0024-integrity-is-gated-before-coverage.md). Pooling the two is what
     * made a blinded run indistinguishable from an uncooperative model in #183.
     *
     * @return list<LiveErrorCategory>
     */
    public static function measurableCategories(): array
    {
        return [
            LiveErrorCategory::Declined,
            LiveErrorCategory::NotAttempted,
        ];
    }

    /**
     * An outcome where **the harness** could not see what happened.
     *
     * `Uncategorized` is included, and that is a judgement rather than a derivation: an
     * unclassified error may originate in an application's case runner rather than in Verdict, but
     * an error the taxonomy could not classify is one the apparatus did not understand. See
     * ADR 0024 §4.
     *
     * @return list<LiveErrorCategory>
     */
    public static function harnessBlindCategories(): array
    {
        return [
            LiveErrorCategory::Unavailable,
            LiveErrorCategory::Uncategorized,
        ];
    }

    /**
     * The apparatus saw less than it measured, so no verdict below it is about the model.
     */
    public function isDominatedByHarnessBlindness(): bool
    {
        return $this->harnessBlind > $this->evaluated;
    }

    /**
     * The signature of systematic blindness: nothing measured, and something the harness could not
     * see. An uncooperative model cannot produce this — declines and non-attempts are model-side, so
     * a model that refuses everything leaves `harnessBlind` at zero.
     */
    public function isSystematicallyBlind(): bool
    {
        return $this->evaluated === 0 && $this->harnessBlind > 0;
    }

    /**
     * More of this purpose went unmeasured than was measured, so the rate rests on a minority of the
     * outcomes that could have supported it.
     */
    public function isDominatedByUnmeasured(): bool
    {
        // Counts harness-blind outcomes too. ADR 0021's question is "was enough of the measurable
        // population measured?", and an outcome the apparatus could not see is still one that was
        // not measured. Splitting the bucket for ADR 0024 must not weaken the coverage rule by
        // shrinking its numerator — integrity is an *additional*, earlier gate, not a partition of
        // the existing one.
        return $this->measurableButUnmeasured + $this->harnessBlind > $this->evaluated;
    }

    /**
     * Whether this population could ever produce an observation. A case that is entirely
     * structurally unavailable has no measurable population, so no coverage rule can oblige it
     * to be measured — requiring that would make any suite containing one permanently
     * insufficient. See [ADR 0022](../../docs/adr/0022-coverage-adequacy-applies-per-case.md).
     */
    public function hasMeasurablePopulation(): bool
    {
        return $this->evaluated + $this->measurableButUnmeasured + $this->harnessBlind > 0;
    }
}
