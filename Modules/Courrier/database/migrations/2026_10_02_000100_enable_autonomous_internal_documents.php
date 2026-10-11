<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE documents_produits_direction
                ALTER COLUMN traitement_direction_id DROP NOT NULL,
                ALTER COLUMN document_source_id DROP NOT NULL
            SQL);

        Schema::table('documents_produits_direction', function (Blueprint $table): void {
            $table->string('origine')->default('traitement_direction');
            $table->foreignId('directeur_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('version_contenu')->nullable();
            $table->foreignId('modifie_par_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('modifie_at')->nullable();
            $table->index(['origine', 'statut']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE documents_produits_direction
            ADD CONSTRAINT documents_produits_direction_origine_coherente
            CHECK (
                (
                    origine = 'traitement_direction'
                    AND traitement_direction_id IS NOT NULL
                    AND document_source_id IS NOT NULL
                    AND directeur_id IS NULL
                    AND version_contenu IS NULL
                )
                OR
                (
                    origine = 'autonome'
                    AND traitement_direction_id IS NULL
                    AND document_source_id IS NULL
                    AND directeur_id IS NOT NULL
                    AND version_contenu IS NOT NULL
                    AND version_contenu > 0
                )
            )
            SQL);
    }

    public function down(): void
    {
        if (DB::table('documents_produits_direction')->where('origine', 'autonome')->exists()) {
            throw new RuntimeException('Impossible de supprimer les champs autonomes tant que des documents autonomes existent.');
        }

        DB::statement('ALTER TABLE documents_produits_direction DROP CONSTRAINT documents_produits_direction_origine_coherente');

        Schema::table('documents_produits_direction', function (Blueprint $table): void {
            $table->dropIndex(['origine', 'statut']);
            $table->dropConstrainedForeignId('directeur_id');
            $table->dropConstrainedForeignId('modifie_par_id');
            $table->dropColumn(['origine', 'version_contenu', 'modifie_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE documents_produits_direction
                ALTER COLUMN traitement_direction_id SET NOT NULL,
                ALTER COLUMN document_source_id SET NOT NULL
            SQL);
    }
};
