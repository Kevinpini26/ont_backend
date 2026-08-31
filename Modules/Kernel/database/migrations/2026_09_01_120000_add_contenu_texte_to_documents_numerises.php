<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Texte extrait d'un document numérisé (PDF déjà textuel lu directement,
 * ou reconnaissance de caractères — voir
 * Modules\Kernel\Support\ExtracteurTexteDocumentNumerise), rendu
 * cherchable via un tsvector PostgreSQL en français — même principe que
 * Courrier::recherche_tsvector (lot 3 du chantier fonctionnel). Trigger
 * plutôt qu'un calcul en PHP à chaque écriture : garantit qu'aucun point
 * d'écriture ne peut oublier de le tenir à jour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_numerises', function ($table) {
            $table->text('contenu_texte')->nullable();
            $table->text('contenu_tsvector')->nullable();
        });

        DB::statement('ALTER TABLE documents_numerises ALTER COLUMN contenu_tsvector TYPE tsvector USING contenu_tsvector::tsvector');

        // CREATE OR REPLACE, pas CREATE : une fonction Postgres n'est pas
        // liée à la table et ne disparaît donc pas quand migrate:fresh()
        // (RefreshDatabase, utilisé par les tests) supprime les tables
        // entre deux exécutions du processus de test — voir la même leçon
        // déjà tirée pour courriers.recherche_tsvector.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION documents_numerises_contenu_tsvector_update() RETURNS trigger AS $$
            BEGIN
                NEW.contenu_tsvector := to_tsvector('french', coalesce(NEW.contenu_texte, ''));
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER documents_numerises_contenu_tsvector_trigger
            BEFORE INSERT OR UPDATE ON documents_numerises
            FOR EACH ROW EXECUTE FUNCTION documents_numerises_contenu_tsvector_update();
        SQL);

        DB::statement('CREATE INDEX IF NOT EXISTS documents_numerises_contenu_tsvector_index ON documents_numerises USING GIN (contenu_tsvector)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS documents_numerises_contenu_tsvector_index');
        DB::statement('DROP TRIGGER IF EXISTS documents_numerises_contenu_tsvector_trigger ON documents_numerises');
        DB::statement('DROP FUNCTION IF EXISTS documents_numerises_contenu_tsvector_update');

        Schema::table('documents_numerises', function ($table) {
            $table->dropColumn(['contenu_texte', 'contenu_tsvector']);
        });
    }
};
