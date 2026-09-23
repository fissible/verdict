<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $bindingAdmissionLocksTable = (string) config('verdict.approvals.binding_admission_locks_table', 'verdict_binding_admission_locks');

        Schema::create($bindingAdmissionLocksTable, function (Blueprint $table): void {
            $table->bigInteger('lock_key')->primary();
        });
    }

    public function down(): void
    {
        $bindingAdmissionLocksTable = (string) config('verdict.approvals.binding_admission_locks_table', 'verdict_binding_admission_locks');

        Schema::dropIfExists($bindingAdmissionLocksTable);
    }
};
