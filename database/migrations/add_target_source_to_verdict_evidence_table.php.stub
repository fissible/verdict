<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verdict_evidence', function (Blueprint $table): void {
            // Indexed because the column exists to be filtered on: ADR 0025's stated purpose is
            // that an auditor can find proposal-resolved consequential capabilities.
            $table->string('target_source', 16)->nullable();
            $table->index('target_source', 'verdict_evidence_target_source_index');
        });
    }

    public function down(): void
    {
        Schema::table('verdict_evidence', function (Blueprint $table): void {
            $table->dropIndex('verdict_evidence_target_source_index');
            $table->dropColumn('target_source');
        });
    }
};
