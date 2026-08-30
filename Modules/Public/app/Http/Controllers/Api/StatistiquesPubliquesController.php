<?php

namespace Modules\Public\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Kernel\Models\Direction;
use Modules\Stagiaires\Enums\StagiaireStatut;

/**
 * Bande de chiffres sous la bannière d'accueil du portail public — trois
 * agrégats globaux, sans aucune donnée nominative (aucun nom de candidat,
 * de stagiaire ou d'agent, aucun identifiant de dossier). Lecture seule,
 * sans authentification, voir Modules/Public/routes/api.php.
 */
class StatistiquesPubliquesController extends Controller
{
    public function show()
    {
        $courriersEnregistres = DB::table('courriers')->where('statut', CourrierStatut::ENREGISTRE->value)->count();
        $stagesClotures = DB::table('stagiaires')->where('statut', StagiaireStatut::CLOTURE->value)->count();

        // Délai moyen (en jours) entre la réception d'un courrier et son
        // enregistrement final — seule mesure de délai disponible qui
        // couvre l'intégralité du circuit plutôt qu'une seule étape.
        $delaiMoyenJours = DB::table('courriers')
            ->where('statut', CourrierStatut::ENREGISTRE->value)
            ->whereNotNull('enregistre_at')
            ->selectRaw('avg(extract(epoch from (enregistre_at - created_at)) / 86400.0) as moyenne')
            ->value('moyenne');

        return response()->json([
            'dossiers_traites' => $courriersEnregistres + $stagesClotures,
            'delai_moyen_jours' => $delaiMoyenJours !== null ? round((float) $delaiMoyenJours, 1) : null,
            // Les huit directions centrales sont toutes des composantes
            // actives et permanentes de l'organigramme de l'Office — pas
            // une mesure d'activité récente qui exposerait indirectement le
            // niveau de charge d'une direction précise.
            'directions_actives' => Direction::query()->count(),
        ]);
    }
}
