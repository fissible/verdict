<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table((string) config('verdict.approvals.table', 'verdict_approval_receipts'), function (Blueprint $table): void {
            $table->text('approver_summary')->nullable();
            $table->string('approver_summary_release')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table((string) config('verdict.approvals.table', 'verdict_approval_receipts'), function (Blueprint $table): void {
            $table->dropColumn(['approver_summary', 'approver_summary_release']);
        });
    }
};
