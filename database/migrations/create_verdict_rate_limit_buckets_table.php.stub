<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verdict_rate_limit_buckets', function (Blueprint $table): void {
            $table->char('bucket_fingerprint', 64);
            $table->timestamp('window_starts_at');
            $table->timestamp('reset_at');
            $table->unsignedBigInteger('attempts');
            $table->timestamps();

            $table->unique(
                ['bucket_fingerprint', 'window_starts_at'],
                'verdict_rate_limit_bucket_window_unique',
            );
            $table->index('reset_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verdict_rate_limit_buckets');
    }
};
