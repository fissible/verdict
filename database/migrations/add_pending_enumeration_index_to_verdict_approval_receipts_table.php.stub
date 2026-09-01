<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = (string) config('verdict.approvals.table', 'verdict_approval_receipts');

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            $table->index(['status', 'created_at', 'id'], $tableName.'_pending_enumeration_index');
        });
    }

    public function down(): void
    {
        $tableName = (string) config('verdict.approvals.table', 'verdict_approval_receipts');

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            $table->dropIndex($tableName.'_pending_enumeration_index');
        });
    }
};
