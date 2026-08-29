<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $evidenceTable = (string) config('verdict.evidence.table', 'verdict_evidence');
        $intentIdIndex = "{$evidenceTable}_intent_id_index";

        Schema::table($evidenceTable, function (Blueprint $table) use ($intentIdIndex): void {
            // The write-ahead intent record this outcome references, when the intent lever is on
            // (#160). The scheduled-verification query joins on it: an intent id no outcome row
            // carries is the gap signal.
            $table->string('intent_id', 64)->nullable();
            $table->index('intent_id', $intentIdIndex);
        });
    }

    public function down(): void
    {
        $evidenceTable = (string) config('verdict.evidence.table', 'verdict_evidence');
        $intentIdIndex = "{$evidenceTable}_intent_id_index";

        Schema::table($evidenceTable, function (Blueprint $table) use ($intentIdIndex): void {
            $table->dropIndex($intentIdIndex);
            $table->dropColumn('intent_id');
        });
    }
};
