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
        $catalog ??= new Catalog;

        $connection->getSchemaBuilder()->dropIfExists(self::TABLE);
        $connection->getSchemaBuilder()->create(self::TABLE, function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('customer_id');
            $table->string('item');
            $table->string('status');
        });

        foreach ([1001, 1002, 1003] as $id) {
            $connection->table(self::TABLE)->insert($catalog->order($id)->disclosure());
        }
    }
}
