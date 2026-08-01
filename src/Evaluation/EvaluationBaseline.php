<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

use InvalidArgumentException;

final readonly class EvaluationBaseline
{
    /**
     * @param  array<string, array{purpose: CasePurpose, status: CaseStatus}>  $cases
     */
    private function __construct(
        public string $suite,
        private array $cases,
    ) {}

    public static function fromJson(string $json): self
    {
        $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($report)) {
            throw new InvalidArgumentException('An evaluation baseline must be a JSON object.');
        }

        return self::fromArray($report);
    }

    /** @param array<string, mixed> $report */
    public static function fromArray(array $report): self
    {
        if (($report['schema'] ?? null) !== EvaluationReport::SCHEMA) {
            throw new InvalidArgumentException('The evaluation baseline uses an unsupported report schema.');
        }

        $suite = $report['suite'] ?? null;
        $reportCases = $report['cases'] ?? null;

        if (! is_string($suite) || trim($suite) === '' || ! is_array($reportCases)) {
            throw new InvalidArgumentException('The evaluation baseline is missing its suite or cases.');
        }

        $cases = [];

        foreach ($reportCases as $case) {
            if (! is_array($case)) {
                throw new InvalidArgumentException('Every baseline case must be an object.');
            }

            $id = $case['id'] ?? null;
            $purpose = $case['purpose'] ?? null;
            $status = $case['status'] ?? null;

            if (! is_string($id) || trim($id) === '' || ! is_string($purpose) || ! is_string($status)) {
                throw new InvalidArgumentException('Every baseline case must have an ID, purpose, and status.');
            }

            if (isset($cases[$id])) {
                throw new InvalidArgumentException("The evaluation baseline contains duplicate case [{$id}].");
            }

            $cases[$id] = [
                'purpose' => CasePurpose::from($purpose),
                'status' => CaseStatus::from($status),
            ];
        }

        if ($cases === []) {
            throw new InvalidArgumentException('An evaluation baseline must contain at least one case.');
        }

        return new self($suite, $cases);
    }

    /** @return array<string, array{purpose: CasePurpose, status: CaseStatus}> */
    public function cases(): array
    {
        return $this->cases;
    }
}
