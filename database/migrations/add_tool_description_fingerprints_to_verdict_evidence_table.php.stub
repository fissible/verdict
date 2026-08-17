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
            $table->char('tool_description_fingerprint', 64)->nullable();
            $table->char('invocation_tool_description_fingerprint', 64)->nullable();

            // The comparison, stored rather than left to be recomputed: an operator reviewing an
            // incident should be able to filter for divergences without knowing to compare two
            // columns. Nullable because "never advertised" is not "advertised unchanged".
            $table->boolean('tool_description_matched')->nullable();
            $table->index('tool_description_matched', 'verdict_evidence_tool_description_matched_index');
        });
    }

    public function down(): void
    {
        Schema::table('verdict_evidence', function (Blueprint $table): void {
            $table->dropIndex('verdict_evidence_tool_description_matched_index');
            $table->dropColumn([
                'tool_description_fingerprint',
                'invocation_tool_description_fingerprint',
                'tool_description_matched',
            ]);
        });
    }
};
