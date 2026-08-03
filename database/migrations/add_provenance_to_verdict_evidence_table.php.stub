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
            $table->string('channel', 32)->nullable();
            $table->string('component_label')->nullable();
            $table->char('component_fingerprint', 64)->nullable();
            $table->char('content_fingerprint', 64)->nullable();

            $table->index(
                ['record_type', 'correlation_id', 'recorded_at'],
                'verdict_evidence_provenance_correlation_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('verdict_evidence', function (Blueprint $table): void {
            $table->dropIndex('verdict_evidence_provenance_correlation_index');
            $table->dropColumn([
                'channel',
                'component_label',
                'component_fingerprint',
                'content_fingerprint',
            ]);
        });
    }
};
