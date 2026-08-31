<?php

namespace Modules\Public\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Kernel\Models\Direction;
use Modules\Stagiaires\Enums\StagiaireStatut;

/**
 * Bande de chiffres sous la bannière d'accueil du portail public — quatre
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

        // "Accueillis cette année" plutôt qu'un taux de respect des délais :
        // ce dernier supposerait un délai réglementaire par étape, qui
        // n'existe pas encore dans le système (voir le chantier fonctionnel,
        // lot 3) — un chiffre de vitrine ne doit jamais être une valeur
        // inventée. L'affectation (pas la simple réception du dossier) est
        // le moment où un stagiaire est réellement accueilli dans une
        // direction.
        $stagiairesAccueillisCetteAnnee = DB::table('stagiaires')
            ->whereNotNull('affecte_at')
            ->whereYear('affecte_at', now()->year)
            ->count();

        return response()->json([
            'dossiers_traites' => $courriersEnregistres + $stagesClotures,
            'delai_moyen_jours' => $delaiMoyenJours !== null ? round((float) $delaiMoyenJours, 1) : null,
            // Les huit directions centrales sont toutes des composantes
            // actives et permanentes de l'organigramme de l'Office — pas
            // une mesure d'activité récente qui exposerait indirectement le
            // niveau de charge d'une direction précise.
            'directions_actives' => Direction::query()->count(),
            'stagiaires_accueillis' => $stagiairesAccueillisCetteAnnee,
        ]);
    }
}
