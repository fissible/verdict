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
            // Application-owned binding identifiers (tenant, conversation, ...) captured verbatim
            // from the ActionContext when the receipt was issued, so per-receipt authorization has
            // something to check. Nullable because receipts issued before this column existed never
            // captured a binding — distinct from a receipt whose application supplied none ("[]").
            $table->text('approval_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table((string) config('verdict.approvals.table', 'verdict_approval_receipts'), function (Blueprint $table): void {
            $table->dropColumn('approval_context');
        });
    }
};
