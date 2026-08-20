<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verdict_evidence', function (Blueprint $table): void {
            // The record's semantic identity: what it asserts, as a stable namespaced label.
            // Indexed because it exists to be filtered on — an auditor selecting every
            // execution-claim completion should not have to know which stage/disposition/status
            // tuple produces one.
            $table->string('claim_type', 64)->nullable();

            // The record's content-derived, Attest-independent identity, scheme-tagged
            // (`canonicaljson-sha256:<hash>`). Nullable because context-release, provenance, and
            // chain-gap rows share this table and carry no decision-record digest; not unique,
            // because two records identical in every stable field within one second are the same
            // claim, and the UUID primary key remains what distinguishes rows.
            $table->string('record_digest', 96)->nullable();

            $table->index('claim_type', 'verdict_evidence_claim_type_index');
            $table->index('record_digest', 'verdict_evidence_record_digest_index');
        });
    }

    public function down(): void
    {
        Schema::table('verdict_evidence', function (Blueprint $table): void {
            $table->dropIndex('verdict_evidence_claim_type_index');
            $table->dropIndex('verdict_evidence_record_digest_index');
            $table->dropColumn(['claim_type', 'record_digest']);
        });
    }
};
