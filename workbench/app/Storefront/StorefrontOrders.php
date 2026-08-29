<?php

declare(strict_types=1);

namespace Workbench\App\Storefront;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * The database-backed side of the storefront's order fixture. The record-keyed capabilities read
 * the in-memory {@see Catalog}; the set-returning `orders.search` executor must run a real query
 * through a real connection — its executed predicate is captured at the connection and digested
 * (#251), and an in-memory filter would leave nothing to observe. This keeps the two in lockstep
 * by seeding the table from the Catalog itself.
 *
 * The schema builder, not raw DDL: column types render per driver, and this table must exist on
 * every engine in the matrix.
 */
final class StorefrontOrders
{
    public const string TABLE = 'storefront_orders';

    public static function prepare(Connection $connection, ?Catalog $catalog = null): void
    {
        $catalog ??= app(Catalog::class);

        $connection->getSchemaBuilder()->dropIfExists(self::TABLE);
        $connection->getSchemaBuilder()->create(self::TABLE, function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('customer_id');
            $table->string('item');
            $table->string('status');
            $table->integer('version');
        });

        foreach ($catalog->all() as $order) {
            $connection->table(self::TABLE)->insert([
                ...$order->disclosure(),
                'version' => $order->version,
            ]);
        }
    }

    /**
     * The declared ADMISSIBLE predicate shapes for the search case — base scope plus each filter
     * combination, the scope clause present in every one by construction. STRUCTURE is
     * hand-written here (never generated from the executor's builder path: that would make the
     * comparison pass by construction); only identifier quoting comes from the active grammar,
     * because quoting is the engine's spelling, not the predicate's shape.
     *
     * @return non-empty-list<string>
     */
    public static function declaredSearchPredicateShapes(Connection $connection): array
    {
        $wrap = $connection->getQueryGrammar()->wrap(...);
        $base = sprintf(
            'select %s, %s, %s, %s from %s where %s = ?',
            $wrap('id'),
            $wrap('customer_id'),
            $wrap('item'),
            $wrap('status'),
            $wrap(self::TABLE),
            $wrap('customer_id'),
        );
        $suffix = sprintf(' order by %s asc', $wrap('id'));
        $status = sprintf(' and %s = ?', $wrap('status'));
        $item = sprintf(' and %s like ?', $wrap('item'));

        return [
            $base.$suffix,
            $base.$status.$suffix,
            $base.$item.$suffix,
            $base.$status.$item.$suffix,
        ];
    }

    /**
     * The one search implementation both arms run — the guarded executor passes the actor's
     * {@see OrderSearchScope}, the unguarded control mirror passes none, and that argument is the
     * entire difference between the arms. Collapsing the arms onto one body replaces the
     * keep-in-lockstep discipline two copies would need.
     *
     * `%` and `_` in the model-supplied term are escaped so a wildcard cannot widen the match.
     * The backslash escape is honored by MySQL/MariaDB natively; on engines where it is not an
     * escape character, a term containing a literal wildcard simply fails to match — narrowing,
     * never widening, per the prefer-false-failure direction.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function search(Connection $connection, array $filters, ?OrderSearchScope $scope = null): string
    {
        $query = $connection->table(self::TABLE);

        if ($scope !== null) {
            $query = $scope->constrain($query);
        }

        $status = $filters['status'] ?? null;
        $itemContains = $filters['item_contains'] ?? null;

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        if (is_string($itemContains) && $itemContains !== '') {
            $query->where('item', 'like', '%'.addcslashes($itemContains, '%_\\').'%');
        }

        $orders = array_map(
            static fn (object $row): array => [
                'id' => (int) $row->id,
                'customer_id' => (int) $row->customer_id,
                'item' => (string) $row->item,
                'status' => (string) $row->status,
            ],
            $query->orderBy('id')->get(['id', 'customer_id', 'item', 'status'])->all(),
        );

        return json_encode(['orders' => $orders], JSON_THROW_ON_ERROR);
    }
}
