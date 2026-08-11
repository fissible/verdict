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
            $table->char('actor_fingerprint', 64)->nullable();
            $table->char('subject_fingerprint', 64)->nullable();
            $table->index('actor_fingerprint', 'verdict_evidence_actor_fingerprint_index');
            $table->index('subject_fingerprint', 'verdict_evidence_subject_fingerprint_index');
        });
    }

    public function down(): void
    {
        Schema::table('verdict_evidence', function (Blueprint $table): void {
            $table->dropIndex('verdict_evidence_actor_fingerprint_index');
            $table->dropIndex('verdict_evidence_subject_fingerprint_index');
            $table->dropColumn(['actor_fingerprint', 'subject_fingerprint']);
        });
    }
};
