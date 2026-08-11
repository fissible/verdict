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
            $table->char('configuration_fingerprint', 64)->nullable();
            $table->index('configuration_fingerprint', 'verdict_evidence_configuration_fingerprint_index');
        });
    }

    public function down(): void
    {
        Schema::table('verdict_evidence', function (Blueprint $table): void {
            $table->dropIndex('verdict_evidence_configuration_fingerprint_index');
            $table->dropColumn('configuration_fingerprint');
        });
    }
};
