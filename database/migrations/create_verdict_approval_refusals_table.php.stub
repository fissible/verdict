<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $refusalsTable = (string) config('verdict.evidence.refusals_table', 'verdict_approval_refusals');

        Schema::create($refusalsTable, function (Blueprint $table): void {
            // One row per binding digest (ADR 0039, #15): a replay flood grows attempt_count, not the
            // row count, so the digest is the primary key.
            $table->char('binding_digest', 64)->primary();
            $table->string('lane', 32);
            $table->string('refusal_reason', 32);
            $table->string('capability');
            $table->unsignedInteger('attempt_count')->default(1);
            $table->string('invocation_id')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('verdict.evidence.refusals_table', 'verdict_approval_refusals'));
    }
};
