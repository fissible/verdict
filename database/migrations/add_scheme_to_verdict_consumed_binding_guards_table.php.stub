<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verdict_consumed_binding_guards', function (Blueprint $table): void {
            // Null under the keyless default; a keyed guard records the algorithm and the key version
            // that derived it (validated after a candidate digest matches). Guards are permanent, so
            // the column stays alongside the fixed-width digest for the life of the row.
            if (! Schema::hasColumn('verdict_consumed_binding_guards', 'algorithm')) {
                $table->string('algorithm')->nullable();
            }

            if (! Schema::hasColumn('verdict_consumed_binding_guards', 'key_version')) {
                $table->string('key_version')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('verdict_consumed_binding_guards', function (Blueprint $table): void {
            // Symmetric with up()'s hasColumn guards: only drop what exists, so a rollback when the
            // columns are already absent is a no-op rather than a "no such column" error.
            if (Schema::hasColumn('verdict_consumed_binding_guards', 'algorithm')) {
                $table->dropColumn('algorithm');
            }

            if (Schema::hasColumn('verdict_consumed_binding_guards', 'key_version')) {
                $table->dropColumn('key_version');
            }
        });
    }
};
