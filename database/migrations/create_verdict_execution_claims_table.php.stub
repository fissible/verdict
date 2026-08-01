<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verdict_execution_claims', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('capability');
            $table->string('policy');
            $table->char('binding_fingerprint', 64)->unique();
            $table->string('status', 24);
            $table->unsignedInteger('attempt_count');
            $table->timestamp('claimed_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('indeterminate_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('resolved_by')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
            $table->index(['capability', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verdict_execution_claims');
    }
};
