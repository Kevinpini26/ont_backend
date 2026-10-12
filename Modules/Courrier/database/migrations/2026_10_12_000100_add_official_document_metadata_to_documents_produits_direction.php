<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_produits_direction', function (Blueprint $table): void {
            $table->foreignId('direction_destination_souhaitee_id')
                ->nullable()
                ->constrained('directions', 'id', 'dpd_destination_direction_fk')
                ->restrictOnDelete();
            $table->string('pdf_officiel_chemin')->nullable();
            $table->string('pdf_officiel_sha256', 64)->nullable();
            $table->unsignedBigInteger('pdf_officiel_version_contenu')->nullable();
            $table->timestampTz('pdf_officiel_genere_at')->nullable();
            $table->foreignId('scan_officiel_document_id')
                ->nullable()
                ->constrained('documents_numerises', 'id', 'dpd_official_scan_document_fk')
                ->restrictOnDelete();

            $table->index('direction_destination_souhaitee_id', 'dpd_destination_direction_idx');
            $table->index('scan_officiel_document_id', 'dpd_official_scan_document_idx');
        });
    }

    public function down(): void
    {
        Schema::table('documents_produits_direction', function (Blueprint $table): void {
            $table->dropForeign('dpd_destination_direction_fk');
            $table->dropForeign('dpd_official_scan_document_fk');
            $table->dropIndex('dpd_destination_direction_idx');
            $table->dropIndex('dpd_official_scan_document_idx');
            $table->dropColumn([
                'direction_destination_souhaitee_id',
                'pdf_officiel_chemin',
                'pdf_officiel_sha256',
                'pdf_officiel_version_contenu',
                'pdf_officiel_genere_at',
                'scan_officiel_document_id',
            ]);
        });
    }
};
