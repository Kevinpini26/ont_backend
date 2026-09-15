<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lot assistants : renomme deux statuts du circuit 'complet' (colonne
 * `statut`, simple chaîne — voir 2026_02_01_000001_create_courriers_table,
 * aucun type Postgres natif à modifier) pour qu'ils reflètent leur
 * nouveau propriétaire (voir CourrierStatut::PROJET_A_REDIGER/PROJET_A_VALIDER) :
 *   - projet_reponse_en_cours -> projet_a_rediger (n'existe que dans le
 *     circuit 'complet', aucune ambiguïté).
 *   - en_relecture -> projet_a_valider, MAIS seulement pour les dossiers du
 *     circuit 'complet' : en_relecture reste inchangé pour les circuits
 *     dg_initie/sortant (voir ConfigCircuitTransitionRules::circuit()) — un
 *     dossier y est du circuit 'complet' quand necessite_avis_dg est vrai,
 *     initie_par_dg est faux, et sens est 'entrant'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('courriers')
            ->where('statut', 'projet_reponse_en_cours')
            ->update(['statut' => 'projet_a_rediger']);

        DB::table('courriers')
            ->where('statut', 'en_relecture')
            ->where('necessite_avis_dg', true)
            ->where('initie_par_dg', false)
            ->where('sens', 'entrant')
            ->update(['statut' => 'projet_a_valider']);
    }

    public function down(): void
    {
        DB::table('courriers')
            ->where('statut', 'projet_a_valider')
            ->update(['statut' => 'en_relecture']);

        DB::table('courriers')
            ->where('statut', 'projet_a_rediger')
            ->update(['statut' => 'projet_reponse_en_cours']);
    }
};
