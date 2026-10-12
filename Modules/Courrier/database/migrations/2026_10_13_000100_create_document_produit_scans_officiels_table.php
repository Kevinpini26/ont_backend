<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_produits_direction', function (Blueprint $table): void {
            $table->unique(['id', 'courrier_id'], 'dp_scan_document_courrier_unique');
        });

        Schema::create('document_produit_scans_officiels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_produit_direction_id');
            $table->foreignId('courrier_id')
                ->constrained('courriers', 'id', 'dp_scan_courrier_fk')
                ->restrictOnDelete();
            $table->foreignId('document_numerise_id')
                ->unique('dp_scan_numerise_unique')
                ->constrained('documents_numerises', 'id', 'dp_scan_numerise_fk')
                ->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('idempotency_key');
            $table->char('empreinte_requete', 64);
            $table->foreignId('depose_par_id')
                ->constrained('users', 'id', 'dp_scan_depose_par_fk')
                ->restrictOnDelete();
            $table->boolean('attestation_confirmee');
            $table->unsignedSmallInteger('attestation_version');
            $table->timestampTz('atteste_at');
            $table->boolean('est_actif')->default(true);
            $table->timestampsTz();

            $table->foreign(
                ['document_produit_direction_id', 'courrier_id'],
                'dp_scan_document_courrier_fk',
            )
                ->references(['id', 'courrier_id'])
                ->on('documents_produits_direction')
                ->restrictOnDelete();
            $table->unique(
                ['document_produit_direction_id', 'idempotency_key'],
                'dp_scan_document_idempotency_unique',
            );
            $table->unique(
                ['document_produit_direction_id', 'version'],
                'dp_scan_document_version_unique',
            );
            $table->index(
                ['document_produit_direction_id', 'created_at'],
                'dp_scan_document_history_idx',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE document_produit_scans_officiels
                ADD CONSTRAINT dp_scan_attestation_true_chk
                    CHECK (attestation_confirmee = TRUE),
                ADD CONSTRAINT dp_scan_hash_format_chk
                    CHECK (empreinte_requete ~ '^[a-f0-9]{64}$'),
                ADD CONSTRAINT dp_scan_version_positive_chk
                    CHECK (version > 0),
                ADD CONSTRAINT dp_scan_attestation_version_chk
                    CHECK (attestation_version > 0),
                ADD CONSTRAINT dp_scan_idempotency_nonempty_chk
                    CHECK (length(idempotency_key) > 0)
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX dp_scan_active_document_unique_idx
                ON document_produit_scans_officiels (document_produit_direction_id)
                WHERE est_actif = TRUE
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('document_produit_scans_officiels');

        Schema::table('documents_produits_direction', function (Blueprint $table): void {
            $table->dropUnique('dp_scan_document_courrier_unique');
        });
    }
};
