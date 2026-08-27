<?php

declare(strict_types=1);

const DEFAULT_FACTS_PATH = 'compatibility/laravel-ai-matrix-facts.json';

const MATRIX_COLUMNS = [
    'verdict', 'verdict-console', 'laravel/ai', 'php', 'laravel', 'verified', 'date', 'evidence',
];

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");

    exit(1);
}

/** @return array{schema: int, rows: list<array<string, string>>} */
function factsFrom(string $path): array
{
    if (! is_file($path)) {
        fail("Facts file does not exist: {$path}");
    }

    $facts = json_decode((string) file_get_contents($path), true);

    if (! is_array($facts) || ($facts['schema'] ?? null) !== 1 || ! isset($facts['rows']) || ! is_array($facts['rows']) || $facts['rows'] === []) {
        fail("Facts file has an invalid compatibility-matrix schema: {$path}");
    }

    foreach ($facts['rows'] as $index => $row) {
        if (! is_array($row)) {
            fail('Facts row '.($index + 1).' is not an object.');
        }

        foreach (MATRIX_COLUMNS as $column) {
            if (! isset($row[$column]) || ! is_string($row[$column]) || trim($row[$column]) === '') {
                fail('Facts row '.($index + 1)." has no {$column} value.");
            }
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $row['date'], $date) !== 1 || ! checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
            fail('Facts row '.($index + 1).' has an invalid date.');
        }
    }

    /** @var array{schema: int, rows: list<array<string, string>>} $facts */
    return $facts;
}

$factsPath = DEFAULT_FACTS_PATH;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--facts-path') {
        continue;
    }

    if (str_starts_with($argument, '--facts=')) {
        $factsPath = substr($argument, strlen('--facts='));

        continue;
    }

    fail("Unknown argument: {$argument}");
}

if (in_array('--facts-path', $argv, true)) {
    if (count($argv) !== 2) {
        fail('--facts-path cannot be combined with other arguments.');
    }

    echo DEFAULT_FACTS_PATH."\n";

    exit(0);
}

$facts = factsFrom($factsPath);

echo '| '.implode(' | ', MATRIX_COLUMNS)." |\n";
echo '|'.str_repeat('---|', count(MATRIX_COLUMNS))."\n";

foreach ($facts['rows'] as $row) {
    echo '| '.implode(' | ', array_map(static fn (string $column): string => $row[$column], MATRIX_COLUMNS))." |\n";
}
