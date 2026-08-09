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
            $table->string('invocation_id')->nullable();
            $table->index('invocation_id', 'verdict_evidence_invocation_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('verdict_evidence', function (Blueprint $table): void {
            $table->dropIndex('verdict_evidence_invocation_id_index');
            $table->dropColumn('invocation_id');
        });
    }
};
