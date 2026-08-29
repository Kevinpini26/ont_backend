<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Index sur les colonnes réellement filtrées par StagiaireController::index()
 * (statut, direction_id, type_stage, échéance sur date_fin_stage, année
 * d'archive sur cloture_at). `courrier_id` a déjà un index via sa
 * contrainte unique (relation 1-1), `stagiaire_id+date` sur
 * stagiaire_presences via la sienne : aucun des deux n'est répété ici.
 *
 * `nom` et `etablissement_origine` sont filtrés par ILIKE '%terme%'
 * (joker en tête) : un index B-tree classique ne sert à rien pour ce
 * genre de recherche, il faut un index GIN + l'extension `pg_trgm`
 * (recherche par trigrammes). Si l'extension ne peut pas être activée
 * (serveur managé sans ce privilège), on se rabat sur un index B-tree
 * simple — inutile pour ILIKE avec joker en tête, mais qui accélère au
 * moins un tri ou une égalité stricte sur ces colonnes ; c'est un repli
 * documenté, pas une solution équivalente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stagiaires', function (Blueprint $table) {
            $table->index('statut');
            $table->index('direction_id');
            $table->index('type_stage');
            $table->index('date_fin_stage');
            $table->index('cloture_at');
        });

        if ($this->activerPgTrgm()) {
            DB::statement('CREATE INDEX stagiaires_nom_trgm_idx ON stagiaires USING GIN (nom gin_trgm_ops)');
            DB::statement('CREATE INDEX stagiaires_etablissement_origine_trgm_idx ON stagiaires USING GIN (etablissement_origine gin_trgm_ops)');
        } else {
            Schema::table('stagiaires', function (Blueprint $table) {
                $table->index('nom');
                $table->index('etablissement_origine');
            });
        }
    }

    public function down(): void
    {
        Schema::table('stagiaires', function (Blueprint $table) {
            $table->dropIndex(['statut']);
            $table->dropIndex(['direction_id']);
            $table->dropIndex(['type_stage']);
            $table->dropIndex(['date_fin_stage']);
            $table->dropIndex(['cloture_at']);
        });

        DB::statement('DROP INDEX IF EXISTS stagiaires_nom_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS stagiaires_etablissement_origine_trgm_idx');

        Schema::table('stagiaires', function (Blueprint $table) {
            if (Schema::hasIndex('stagiaires', 'stagiaires_nom_index')) {
                $table->dropIndex(['nom']);
            }
            if (Schema::hasIndex('stagiaires', 'stagiaires_etablissement_origine_index')) {
                $table->dropIndex(['etablissement_origine']);
            }
        });
    }

    private function activerPgTrgm(): bool
    {
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

            return true;
        } catch (QueryException $e) {
            Log::warning("Extension pg_trgm indisponible (privilège insuffisant sur ce serveur) : repli sur un index B-tree classique, inefficace pour une recherche ILIKE '%terme%'.", [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
};
