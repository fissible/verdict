<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((string) config('verdict.intents.table', 'verdict_action_intents'), function (Blueprint $table): void {
            // Write-once operational security state (#160): rows are inserted before the mutating
            // phase and never updated — deliberately no updated_at, no status column. Retention is
            // an application-owned compliance decision, in the style of ADR 0009: these rows ARE
            // the record a fail-closed deployment exists to keep, so archive before removing.
            $table->string('id', 64)->primary();
            $table->string('capability');
            $table->char('configuration_fingerprint', 64);
            $table->char('actor_fingerprint', 64)->nullable();
            $table->char('subject_fingerprint', 64)->nullable();
            $table->char('execution_target_identity_fingerprint', 64)->nullable();
            $table->char('argument_fingerprint', 64);
            $table->string('invocation_id')->nullable();
            $table->timestamp('recorded_at');

            $table->index(['capability', 'recorded_at']);
            $table->index('invocation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('verdict.intents.table', 'verdict_action_intents'));
    }
};
