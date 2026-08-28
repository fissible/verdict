<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;

/**
 * Builds the evidence table at a chosen migration lag (#356) by running the published migration
 * stubs and simply not running the ones the lagging install never ran.
 *
 * Two deliberate choices:
 *
 * It runs the real stubs rather than restating the schema, because a hand-copied blueprint drifts
 * from the migrations it stands in for — this fixture's first draft had already drifted
 * (`unsignedInteger` where the shipped migration writes `unsignedBigInteger`), and a fixture that
 * does not match the schema it reproduces cannot prove anything about a real lag.
 *
 * And it omits whole migrations rather than creating the full table and dropping columns. That is
 * what an out-of-date install actually looks like — the migration's indexes are absent too, not
 * just its columns — and dropping an indexed column is not portable anyway.
 */
final class EvidenceTableSchema
{
    /** @var array<string, list<string>>|null */
    private static ?array $columnsByMigration = null;

    /** @var list<string>|null */
    private static ?array $baseListing = null;

    /** Runs the base create migration plus every additive `add_*` evidence migration. */
    public static function createComplete(): void
    {
        self::createSkippingMigrationsFor([]);
    }

    /**
     * The table as it stands on an install that never ran the migrations adding `$without`.
     *
     * Exclusion is migration-granular, because that is the real failure mode: a skipped migration
     * takes every column it adds. Ask {@see missingColumns()} for what is actually absent — one
     * excluded column can bring a sibling with it.
     *
     * @param  list<string>  $without
     */
    public static function createWithout(array $without): void
    {
        self::createSkippingMigrationsFor($without);
    }

    /**
     * The columns actually absent when `$without` is excluded, siblings included.
     *
     * @param  list<string>  $without
     * @return list<string>
     */
    public static function missingColumns(array $without): array
    {
        $missing = [];

        foreach (self::columnsByMigration() as $columns) {
            if (array_intersect($columns, $without) !== []) {
                $missing = [...$missing, ...$columns];
            }
        }

        sort($missing);

        return $missing;
    }

    /**
     * Every column added by an additive migration, derived by running them one at a time.
     *
     * @return list<string>
     */
    public static function additiveColumns(): array
    {
        $columns = array_merge(...array_values(self::columnsByMigration()));
        sort($columns);

        return $columns;
    }

    /**
     * The columns actually absent from the table as it currently stands, measured by diffing a
     * separately built complete listing against the live one.
     *
     * Deliberately independent of {@see missingColumns()}: that method decides which migrations
     * the fixture skips, so using it to also supply a test's expected output would let a wrong
     * migration-to-column map agree with itself. This measures the table instead.
     *
     * @return list<string>
     */
    public static function absentColumns(): array
    {
        $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
        $live = $schema->getColumnListing(verdictTable('evidence'));

        $complete = self::completeColumnListing();

        return array_values(array_diff($complete, $live));
    }

    /**
     * The full column listing: the base migration's columns plus every additive column, both
     * recorded by {@see probe()}.
     *
     * @return list<string>
     */
    public static function completeColumnListing(): array
    {
        self::probe();

        assert(self::$baseListing !== null);

        return [...self::$baseListing, ...self::additiveColumns()];
    }

    /** The table as it stands on an install that never ran one named migration. */
    public static function createWithoutMigration(string $migration): void
    {
        self::drop();
        self::run('create_verdict_evidence_table');

        foreach (self::additiveMigrations() as $candidate) {
            if ($candidate !== $migration) {
                self::run($candidate);
            }
        }
    }

    /**
     * Additive migration names, from the filesystem only — safe to call before the app boots, so
     * it can drive a Pest dataset.
     *
     * @return list<string>
     */
    public static function additiveMigrationNames(): array
    {
        return self::additiveMigrations();
    }

    public static function drop(?string $table = null): void
    {
        app(DatabaseManager::class)->connection()->getSchemaBuilder()
            ->dropIfExists($table ?? verdictTable('evidence'));
    }

    /** @param list<string> $without */
    private static function createSkippingMigrationsFor(array $without): void
    {
        $skip = [];

        foreach (self::columnsByMigration() as $migration => $columns) {
            if (array_intersect($columns, $without) !== []) {
                $skip[] = $migration;
            }
        }

        self::drop();
        self::run('create_verdict_evidence_table');

        foreach (self::additiveMigrations() as $migration) {
            if (! in_array($migration, $skip, true)) {
                self::run($migration);
            }
        }
    }

    /**
     * Which columns each additive migration contributes, built by applying them one at a time and
     * diffing the column listing. Derived, so it cannot fall out of step with the stubs.
     *
     * @return array<string, list<string>>
     */
    private static function columnsByMigration(): array
    {
        self::probe();

        assert(self::$columnsByMigration !== null);

        return self::$columnsByMigration;
    }

    /**
     * Measures the schema once by applying the migrations one at a time and diffing the column
     * listing, recording both the base listing and each migration's contribution.
     *
     * It runs on the real table rather than a scratch one because the migrations create indexes
     * under fixed names, and index names are database-global in SQLite — a scratch copy collides
     * with the live table's indexes. The probe leaves the table fully migrated, and every public
     * builder rebuilds it before use, so that is not a state a caller can observe.
     */
    private static function probe(): void
    {
        if (self::$columnsByMigration !== null) {
            return;
        }

        $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
        $table = verdictTable('evidence');

        self::drop();
        self::run('create_verdict_evidence_table');

        self::$baseListing = $schema->getColumnListing($table);
        $seen = self::$baseListing;
        $map = [];

        foreach (self::additiveMigrations() as $migration) {
            self::run($migration);
            $now = $schema->getColumnListing($table);
            $map[$migration] = array_values(array_diff($now, $seen));
            $seen = $now;
        }

        self::$columnsByMigration = $map;
    }

    /** @return list<string> */
    private static function additiveMigrations(): array
    {
        $files = glob(self::path('add_*_to_verdict_evidence_table')) ?: [];
        sort($files);

        return array_map(
            static fn (string $file): string => basename($file, '.php.stub'),
            $files,
        );
    }

    private static function run(string $migration): void
    {
        $instance = require self::path($migration);

        assert($instance instanceof Migration);

        $instance->up();
    }

    private static function path(string $migration): string
    {
        return dirname(__DIR__, 2).'/database/migrations/'.$migration.'.php.stub';
    }
}
