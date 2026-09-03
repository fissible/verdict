<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v0.15.0's create migration already produced this column. This compatibility migration therefore
 * tolerates it, and its down() leaves it in place: its origin is unknowable, while the downgraded
 * release still declares and writes it. Retained evidence is worth more than removing an unused
 * nullable column. #466
 */
return new class extends Migration
{
    public function up(): void
    {
        $evidenceTable = (string) config('verdict.evidence.table', 'verdict_evidence');

        if (Schema::hasColumn($evidenceTable, 'review_request_fingerprint')) {
            return;
        }

        Schema::table($evidenceTable, function (Blueprint $table): void {
            $table->char('review_request_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        // Do not drop this column: it may have been created by v0.15.0's create migration.
    }
};
