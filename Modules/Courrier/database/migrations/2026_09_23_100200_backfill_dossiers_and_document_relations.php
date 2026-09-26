<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $courriers = DB::table('courriers')
            ->select(['id', 'en_reponse_a_courrier_id', 'created_by', 'objet', 'created_at'])
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $dossiersParRacine = [];

        foreach ($courriers as $courrier) {
            $racine = $courrier;
            $visites = [];
            while ($racine->en_reponse_a_courrier_id !== null && isset($courriers[$racine->en_reponse_a_courrier_id])) {
                if (isset($visites[$racine->id])) {
                    // Une anomalie historique cyclique ne doit pas faire
                    // échouer le déploiement : tous les membres du cycle
                    // convergent vers le plus petit identifiant.
                    $racine = $courriers[min(array_keys($visites))];
                    break;
                }
                $visites[$racine->id] = true;
                $racine = $courriers[$racine->en_reponse_a_courrier_id];
            }

            if (! isset($dossiersParRacine[$racine->id])) {
                $dossiersParRacine[$racine->id] = DB::table('dossiers')->insertGetId([
                    'libelle' => $racine->objet,
                    'created_by' => $racine->created_by,
                    'importe_historique' => true,
                    'created_at' => $racine->created_at ?? now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('courriers')->where('id', $courrier->id)->update(['dossier_id' => $dossiersParRacine[$racine->id]]);

            if ($courrier->en_reponse_a_courrier_id !== null && $courrier->id !== $courrier->en_reponse_a_courrier_id) {
                DB::table('document_relations')->insertOrIgnore([
                    'document_source_id' => $courrier->id,
                    'document_cible_id' => $courrier->en_reponse_a_courrier_id,
                    'type_relation' => 'reponse_a',
                    'created_by' => $courrier->created_by,
                    'importe_historique' => true,
                    'created_at' => $courrier->created_at ?? now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Backfill réversible sans toucher aux courriers ni à leur lien historique.
        $dossierIds = DB::table('dossiers')->where('importe_historique', true)->pluck('id');
        DB::table('document_relations')->where('importe_historique', true)->delete();
        DB::table('courriers')->whereIn('dossier_id', $dossierIds)->update(['dossier_id' => null]);
        DB::table('dossiers')->whereIn('id', $dossierIds)->delete();
    }
};
