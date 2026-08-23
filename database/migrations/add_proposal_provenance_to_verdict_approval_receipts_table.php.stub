<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verdict_approval_receipts', function (Blueprint $table): void {
            // Nullable because receipts issued before this column existed never captured a payload.
            // That is a third thing, distinct from the ledger having nothing declared (unknown) and
            // from the application registering no approver release policy (unreleased).
            $table->text('provenance')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('verdict_approval_receipts', function (Blueprint $table): void {
            $table->dropColumn('provenance');
        });
    }
};
