<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $evidenceTable = (string) config('verdict.evidence.table', 'verdict_evidence');

        Schema::table($evidenceTable, function (Blueprint $table): void {
            $table->string('review_outcome')->nullable();
        });
    }

    public function down(): void
    {
        $evidenceTable = (string) config('verdict.evidence.table', 'verdict_evidence');

        Schema::table($evidenceTable, function (Blueprint $table): void {
            $table->dropColumn('review_outcome');
        });
    }
};
