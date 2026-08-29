<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Support\CourriersNonTraites;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Support\PeriodeStatistique;

class CourrierStatistiqueController extends Controller
{
    /**
     * Tableau de bord du circuit courrier : volumétrie par étape, files
     * d'attente les plus chargées, temps moyen de traitement par étape.
     *
     * Vue par nature transverse aux directions (voir CourrierPolicy::
     * voirStatistiques) : les requêtes agrégées ci-dessous interrogent donc
     * volontairement toutes les directions, sans repasser par le Global
     * Scope Eloquent de Courrier (qui n'a de sens que pour un espace de
     * direction).
     */
    public function index(Request $request)
    {
        $this->authorize('voirStatistiques', Courrier::class);

        $periode = new PeriodeStatistique($request->string('periode', '30j')->toString());

        $parStatut = DB::table('courriers')
            ->select('statut', DB::raw('count(*) as total'))
            ->groupBy('statut')
            ->pluck('total', 'statut');

        $enAttenteRelecture = (int) ($parStatut[CourrierStatut::EN_RELECTURE->value] ?? 0);
        $enCoursTotal = (int) collect($parStatut)->except([CourrierStatut::ENREGISTRE->value])->sum();

        // Temps moyen (en heures) entre deux transitions consécutives d'un
        // même courrier, regroupé par statut d'arrivée : mesure combien de
        // temps un courrier passe en moyenne *avant d'atteindre* chaque étape.
        $tempsParEtape = DB::select(<<<'SQL'
            select statut, avg(extract(epoch from (created_at - precedent))) / 3600.0 as moyenne_heures
            from (
                select
                    statut,
                    created_at,
                    lag(created_at) over (partition by courrier_id order by created_at) as precedent
                from courrier_transitions
            ) transitions
            where precedent is not null
            group by statut
        SQL);

        $recus = DB::table('courriers')->whereBetween('created_at', [$periode->debut, $periode->fin])->count();
        $recusPrecedent = DB::table('courriers')->whereBetween('created_at', [$periode->debutPrecedente, $periode->finPrecedente])->count();

        $evolution = DB::table('courriers')
            ->selectRaw('date_trunc(?, created_at) as periode, count(*) as total', [$periode->granulariteSql()])
            ->whereBetween('created_at', [$periode->debut, $periode->fin])
            ->groupBy('periode')
            ->orderBy('periode')
            ->get();

        return response()->json([
            'par_statut' => collect(CourrierStatut::cases())->map(fn (CourrierStatut $statut) => [
                'statut' => $statut->value,
                'label' => $statut->label(),
                'total' => (int) ($parStatut[$statut->value] ?? 0),
            ])->values(),
            'en_cours_total' => $enCoursTotal,
            'en_attente_relecture' => $enAttenteRelecture,
            'courriers_recus_periode' => $recus,
            'courriers_recus_variation' => PeriodeStatistique::variationPourcentage($recus, $recusPrecedent),
            'periode' => [
                'cle' => $periode->cle,
                'depuis' => $periode->debut->toDateString(),
                'jusqua' => $periode->fin->toDateString(),
            ],
            'evolution' => $evolution->map(fn ($ligne) => [
                'periode' => Carbon::parse($ligne->periode)->toDateString(),
                'total' => (int) $ligne->total,
            ])->values(),
            'temps_moyen_par_etape' => collect($tempsParEtape)->map(fn ($ligne) => [
                'statut' => $ligne->statut,
                'label' => CourrierStatut::from($ligne->statut)->label(),
                'moyenne_heures' => round((float) $ligne->moyenne_heures, 1),
            ])->values(),
        ]);
    }

    /**
     * Tableau de bord dédié à la Direction Générale : distinct de la simple
     * file de traitement (CircuitQueuePage) — vue consolidée toutes
     * directions confondues, centrée sur ce qui attend une décision de la DG.
     */
    public function dg(Request $request)
    {
        $this->authorize('voirTableauDeBordDg', Courrier::class);

        $seuilJours = $request->integer('seuil_jours', 5);
        $periode = new PeriodeStatistique($request->string('periode', '30j')->toString());

        $enAttenteDecision = Courrier::query()
            ->withoutGlobalScopes()
            ->whereIn('statut', [CourrierStatut::EN_ATTENTE_AVIS_DG, CourrierStatut::EN_RELECTURE])
            ->count();

        // Courriers dont la dernière transition remonte à plus de
        // `seuil_jours`, tous statuts non clôturés confondus — signal
        // d'alerte générique, indépendant de l'étape précise où ça bloque.
        $enAttenteDepuisLongtemps = CourriersNonTraites::compter(now()->subDays($seuilJours));

        $avisRendus = Courrier::query()->withoutGlobalScopes()->whereBetween('avis_dg_rendu_at', [$periode->debut, $periode->fin])->count();
        $avisRendusPrecedent = Courrier::query()->withoutGlobalScopes()->whereBetween('avis_dg_rendu_at', [$periode->debutPrecedente, $periode->finPrecedente])->count();

        // Distingue les courriers reçus traités (avisRendus ci-dessus) des
        // courriers émis à l'initiative de la DG elle-même (voir
        // CourrierCircuitService::initierParDg()).
        $initiesParDg = Courrier::query()->withoutGlobalScopes()->where('initie_par_dg', true)->whereBetween('created_at', [$periode->debut, $periode->fin])->count();
        $initiesParDgPrecedent = Courrier::query()->withoutGlobalScopes()->where('initie_par_dg', true)->whereBetween('created_at', [$periode->debutPrecedente, $periode->finPrecedente])->count();

        return response()->json([
            'en_attente_decision' => $enAttenteDecision,
            'en_attente_depuis_longtemps' => $enAttenteDepuisLongtemps,
            'seuil_jours' => $seuilJours,
            'avis_rendus_periode' => $avisRendus,
            'avis_rendus_variation' => PeriodeStatistique::variationPourcentage($avisRendus, $avisRendusPrecedent),
            'courriers_initie_par_dg_periode' => $initiesParDg,
            'courriers_initie_par_dg_variation' => PeriodeStatistique::variationPourcentage($initiesParDg, $initiesParDgPrecedent),
            'periode' => [
                'cle' => $periode->cle,
                'depuis' => $periode->debut->toDateString(),
                'jusqua' => $periode->fin->toDateString(),
            ],
        ]);
    }

    /**
     * Tableau de bord d'une direction d'accueil : volumétrie de courriers
     * reçus/émis par SA direction sur la période sélectionnée. Distinct de
     * index() : ici, le Global Scope Eloquent doit s'appliquer (une
     * direction ne doit voir que ses propres courriers), donc pas de
     * withoutGlobalScopes().
     */
    public function pourDirection(Request $request)
    {
        $this->authorize('voirTableauDeBordDirection', Courrier::class);

        $periode = new PeriodeStatistique($request->string('periode', '30j')->toString());
        $directionId = $request->user()->direction_id;

        // La DFP n'a pas de direction_id (voir UserFactory::agentDfp() et
        // DemoAccountsSeeder) : mêmes filtres que pour un responsable de
        // direction, sauf qu'un directionId nul ne restreint rien — vue
        // organisation entière plutôt qu'une direction précise. Même
        // convention déjà établie par CourriersNonTraites::compter().
        $filtrerDestination = fn ($q) => $directionId === null ? $q : $q->where('direction_destination_id', $directionId);
        $filtrerOrigine = fn ($q) => $directionId === null ? $q : $q->where('direction_origine_id', $directionId);

        // Files d'attente courantes, indépendantes de la période
        // sélectionnée ("enregistre" est le statut terminal commun aux deux
        // circuits, court et complet).
        $recusNonTraites = Courrier::query()->withoutGlobalScopes()
            ->tap($filtrerDestination)
            ->where('statut', '!=', CourrierStatut::ENREGISTRE->value)
            ->count();

        // Signal d'alerte "rouge" du dashboard direction : courriers reçus
        // par cette direction dont la dernière transition remonte à plus de
        // 48h — même requête que dg()::en_attente_depuis_longtemps, seuil
        // fixe et filtrée sur cette seule direction.
        $recusNonTraites48h = CourriersNonTraites::compter(now()->subHours(48), $directionId);

        $emisEnCours = Courrier::query()->withoutGlobalScopes()
            ->tap($filtrerOrigine)
            ->where('statut', '!=', CourrierStatut::ENREGISTRE->value)
            ->count();

        $recus = Courrier::query()->withoutGlobalScopes()->tap($filtrerDestination)
            ->whereBetween('created_at', [$periode->debut, $periode->fin])->count();
        $recusPrecedent = Courrier::query()->withoutGlobalScopes()->tap($filtrerDestination)
            ->whereBetween('created_at', [$periode->debutPrecedente, $periode->finPrecedente])->count();

        $emis = Courrier::query()->withoutGlobalScopes()->tap($filtrerOrigine)
            ->whereBetween('created_at', [$periode->debut, $periode->fin])->count();
        $emisPrecedent = Courrier::query()->withoutGlobalScopes()->tap($filtrerOrigine)
            ->whereBetween('created_at', [$periode->debutPrecedente, $periode->finPrecedente])->count();

        $evolution = DB::table('courriers')
            ->selectRaw('date_trunc(?, created_at) as periode, count(*) as total', [$periode->granulariteSql()])
            ->when(
                $directionId !== null,
                fn ($q) => $q->where(fn ($q2) => $q2->where('direction_destination_id', $directionId)->orWhere('direction_origine_id', $directionId)),
            )
            ->whereBetween('created_at', [$periode->debut, $periode->fin])
            ->groupBy('periode')
            ->orderBy('periode')
            ->get();

        // Tendance des candidatures de stage sur 12 mois : n'a de sens
        // qu'org-wide (une demande_stage n'a pas encore de direction avant
        // affectation), donc uniquement pour la DFP — jamais pour un
        // responsable de direction classique, qui n'a rien à en faire ici.
        $tendanceCandidatures = null;
        if ($request->user()->role === UserRole::AGENT_DFP) {
            $debut12Mois = now()->subMonths(11)->startOfMonth();
            $tendanceCandidatures = DB::table('courriers')
                ->selectRaw("date_trunc('month', created_at) as mois, count(*) as total")
                ->where('type', CourrierType::DEMANDE_STAGE->value)
                ->where('created_at', '>=', $debut12Mois)
                ->groupBy('mois')
                ->orderBy('mois')
                ->get()
                ->map(fn ($ligne) => ['mois' => Carbon::parse($ligne->mois)->toDateString(), 'total' => (int) $ligne->total])
                ->values();
        }

        return response()->json([
            'courriers_recus_non_traites' => $recusNonTraites,
            'courriers_recus_non_traites_48h' => $recusNonTraites48h,
            'courriers_emis_en_cours' => $emisEnCours,
            'courriers_recus_periode' => $recus,
            'courriers_recus_variation' => PeriodeStatistique::variationPourcentage($recus, $recusPrecedent),
            'courriers_emis_periode' => $emis,
            'courriers_emis_variation' => PeriodeStatistique::variationPourcentage($emis, $emisPrecedent),
            'periode' => [
                'cle' => $periode->cle,
                'depuis' => $periode->debut->toDateString(),
                'jusqua' => $periode->fin->toDateString(),
            ],
            'evolution' => $evolution->map(fn ($ligne) => [
                'periode' => Carbon::parse($ligne->periode)->toDateString(),
                'total' => (int) $ligne->total,
            ])->values(),
            'tendance_candidatures_12_mois' => $tendanceCandidatures,
        ]);
    }
}
