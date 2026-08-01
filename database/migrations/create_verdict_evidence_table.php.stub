<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verdict_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('record_type', 32);
            $table->string('correlation_id')->nullable();
            $table->string('capability')->nullable();
            $table->string('stage', 32);
            $table->string('disposition', 32);
            $table->text('reason')->nullable();
            $table->string('source')->nullable();
            $table->string('destination')->nullable();
            $table->string('trust_zone')->nullable();
            $table->string('trust', 32)->nullable();
            $table->string('data_class', 32)->nullable();
            $table->char('argument_fingerprint', 64)->nullable();
            $table->char('idempotency_key_fingerprint', 64)->nullable();
            $table->char('approval_receipt_fingerprint', 64)->nullable();
            $table->string('approval_phase', 32)->nullable();
            $table->string('approval_outcome', 32)->nullable();
            $table->string('target_policy')->nullable();
            $table->string('target_strategy', 32)->nullable();
            $table->char('proposal_target_identity_fingerprint', 64)->nullable();
            $table->char('execution_target_identity_fingerprint', 64)->nullable();
            $table->boolean('target_identity_matched')->nullable();
            $table->char('rate_limit_key_fingerprint', 64)->nullable();
            $table->string('rate_limit_policy')->nullable();
            $table->unsignedBigInteger('rate_limit_limit')->nullable();
            $table->unsignedBigInteger('rate_limit_remaining')->nullable();
            $table->timestamp('rate_limit_reset_at')->nullable();
            $table->char('execution_claim_fingerprint', 64)->nullable();
            $table->char('execution_claim_binding_fingerprint', 64)->nullable();
            $table->string('execution_claim_policy')->nullable();
            $table->string('execution_claim_status', 24)->nullable();
            $table->unsignedInteger('execution_claim_attempt')->nullable();
            $table->json('requested_path_fingerprints')->nullable();
            $table->json('released_path_fingerprints')->nullable();
            $table->json('transform_fingerprints')->nullable();
            $table->json('transformed_path_fingerprints')->nullable();
            $table->unsignedInteger('transformation_count')->default(0);
            $table->char('payload_fingerprint', 64)->nullable();
            $table->timestamp('recorded_at');

            $table->index(['record_type', 'recorded_at']);
            $table->index(['disposition', 'recorded_at']);
            $table->index(['capability', 'recorded_at']);
            $table->index(['source', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verdict_evidence');
    }
};
