<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verdict_capability_configurations', function (Blueprint $table): void {
            $table->char('configuration_fingerprint', 64)->primary();
            $table->string('capability');
            $table->json('configuration');
            $table->timestamp('first_seen_at');

            $table->index('capability');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verdict_capability_configurations');
    }
};
