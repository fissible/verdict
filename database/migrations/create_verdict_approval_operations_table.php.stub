<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $operationsTable = (string) config('verdict.evidence.operations_table', 'verdict_approval_operations');

        Schema::create($operationsTable, function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('lane', 32);
            $table->string('operation', 32);
            $table->string('capability');
            $table->char('identity_fingerprint', 64);
            $table->char('summary_fingerprint', 64)->nullable();
            $table->string('invocation_id')->nullable();
            $table->timestamp('occurred_at');
            $table->index('identity_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('verdict.evidence.operations_table', 'verdict_approval_operations'));
    }
};
