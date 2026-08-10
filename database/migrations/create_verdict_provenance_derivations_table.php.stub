<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verdict_provenance_derivations', function (Blueprint $table): void {
            $table->string('correlation_id');
            $table->char('child_content_fingerprint', 64);
            $table->char('parent_content_fingerprint', 64);
            $table->string('kind', 32);
            $table->timestamp('recorded_at');

            $table->primary([
                'correlation_id',
                'child_content_fingerprint',
                'parent_content_fingerprint',
                'kind',
            ], 'verdict_provenance_derivations_primary');
            $table->index(
                ['correlation_id', 'child_content_fingerprint'],
                'verdict_provenance_derivations_backward_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verdict_provenance_derivations');
    }
};
