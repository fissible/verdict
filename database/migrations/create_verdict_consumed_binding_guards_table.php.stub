<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verdict_consumed_binding_guards', function (Blueprint $table): void {
            $table->binary('digest', length: 32, fixed: true)->primary();
            $table->timestamp('consumed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verdict_consumed_binding_guards');
    }
};
