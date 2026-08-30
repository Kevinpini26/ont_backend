<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recherche plein texte réelle (PostgreSQL tsvector, configuration
 * française) plutôt qu'un simple ILIKE sur quelques colonnes — porte sur
 * l'objet et les identités des correspondants externes. Les numéros
 * (numero_enregistrement, numero_accuse_reception, numero_depart,
 * cote_classement) restent traités à part par égalité exacte (voir
 * Courrier::scopeRecherchePleinTexte()) : ce sont des codes, pas du texte
 * français à raciniser.
 *
 * Colonne alimentée par un trigger Postgres plutôt qu'en PHP à chaque
 * create()/update() : garantit qu'aucun point d'écriture (y compris un
 * import ou une correction directe en base) ne peut oublier de la tenir à
 * jour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function ($table) {
            $table->text('recherche_tsvector')->nullable();
        });

        DB::statement('ALTER TABLE courriers ALTER COLUMN recherche_tsvector TYPE tsvector USING recherche_tsvector::tsvector');

        // CREATE OR REPLACE, pas CREATE : une fonction Postgres n'est pas
        // liée à la table et ne disparaît donc pas quand
        // RefreshDatabase::migrate:fresh() (utilisé par les tests) supprime
        // les tables entre deux exécutions du processus de test — un
        // simple CREATE FUNCTION entrerait en collision avec elle-même au
        // second lancement.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION courriers_recherche_tsvector_update() RETURNS trigger AS $$
            BEGIN
                NEW.recherche_tsvector := to_tsvector('french',
                    coalesce(NEW.objet, '') || ' ' ||
                    coalesce(NEW.candidat_nom, '') || ' ' ||
                    coalesce(NEW.expediteur_externe_nom, '') || ' ' ||
                    coalesce(NEW.destinataire_externe_nom, '') || ' ' ||
                    coalesce(NEW.reference_expediteur, '')
                );
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER courriers_recherche_tsvector_trigger
            BEFORE INSERT OR UPDATE ON courriers
            FOR EACH ROW EXECUTE FUNCTION courriers_recherche_tsvector_update();
        SQL);

        DB::statement(<<<'SQL'
            UPDATE courriers SET recherche_tsvector = to_tsvector('french',
                coalesce(objet, '') || ' ' ||
                coalesce(candidat_nom, '') || ' ' ||
                coalesce(expediteur_externe_nom, '') || ' ' ||
                coalesce(destinataire_externe_nom, '') || ' ' ||
                coalesce(reference_expediteur, '')
            );
        SQL);

        DB::statement('CREATE INDEX IF NOT EXISTS courriers_recherche_tsvector_index ON courriers USING GIN (recherche_tsvector)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS courriers_recherche_tsvector_index');
        DB::statement('DROP TRIGGER IF EXISTS courriers_recherche_tsvector_trigger ON courriers');
        DB::statement('DROP FUNCTION IF EXISTS courriers_recherche_tsvector_update');

        Schema::table('courriers', function ($table) {
            $table->dropColumn('recherche_tsvector');
        });
    }
};
