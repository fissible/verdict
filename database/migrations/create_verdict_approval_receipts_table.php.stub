<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verdict_approval_receipts', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('tool_call_id');
            $table->string('capability');
            $table->char('binding_fingerprint', 64);
            $table->string('status', 24);
            $table->text('reason')->nullable();
            $table->timestamp('expires_at');
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique(['tool_call_id', 'capability', 'binding_fingerprint'], 'verdict_approval_receipts_binding_unique');
            $table->index(['tool_call_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verdict_approval_receipts');
    }
};
