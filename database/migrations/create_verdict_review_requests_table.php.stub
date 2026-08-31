<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $reviewsTable = (string) config('verdict.reviews.table', 'verdict_review_requests');
        $bindingUnique = "{$reviewsTable}_binding_unique";

        Schema::create($reviewsTable, function (Blueprint $table) use ($bindingUnique): void {
            $table->char('id', 64)->primary();
            $table->string('capability');
            $table->char('binding_fingerprint', 64);
            $table->string('status', 24);
            $table->text('reason')->nullable();
            $table->timestamp('expires_at');
            $table->string('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->text('provenance')->nullable();
            $table->text('approval_context')->nullable();
            $table->text('approver_summary')->nullable();
            $table->timestamps();

            $table->unique(['capability', 'binding_fingerprint'], $bindingUnique);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('verdict.reviews.table', 'verdict_review_requests'));
    }
};
