<?php

namespace Modules\Stagiaires\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Support\PeriodeStatistique;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Enums\StagiaireTypeStage;
use Modules\Stagiaires\Models\Stagiaire;

class StagiaireStatistiqueController extends Controller
{
    /**
     * Tableau de bord DFP (et, filtré par direction via le Global Scope,
     * tableau de bord d'une direction d'accueil) : effectifs actifs,
     * répartition par direction, échéances proches, volumétrie et
     * variations sur la période sélectionnée.
     */
    public function index(Request $request)
    {
        $this->authorize('voirStatistiques', Stagiaire::class);

        $periode = new PeriodeStatistique($request->string('periode', '30j')->toString());

        $statutsActifs = [
            StagiaireStatut::AFFECTE,
            StagiaireStatut::STAGE_EN_COURS,
            StagiaireStatut::EVALUATION_EN_COURS,
        ];

        $parStatut = Stagiaire::query()
            ->selectRaw('statut, count(*) as total')
            ->groupBy('statut')
            ->pluck('total', 'statut');

        $parDirection = Stagiaire::query()
            ->whereIn('statut', $statutsActifs)
            ->whereNotNull('direction_id')
            ->join('directions', 'directions.id', '=', 'stagiaires.direction_id')
            ->selectRaw('directions.id as direction_id, directions.nom as direction_nom, count(*) as total')
            ->groupBy('directions.id', 'directions.nom')
            ->orderByDesc('total')
            ->get();

        // Sert à la fois le dashboard "direction" ("stagiaires actuellement
        // affectés à ma direction", Global Scope actif) et le dashboard DFP
        // ("stagiaires actifs toutes directions confondues", scope bypassé) :
        // un seul champ, la portée dépend déjà de qui appelle.
        $stagiairesAffectes = (int) collect($statutsActifs)->sum(fn (StagiaireStatut $s) => $parStatut[$s->value] ?? 0);

        $enAttenteAffectation = (int) ($parStatut[StagiaireStatut::EN_ATTENTE_AFFECTATION->value] ?? 0);

        $echeanceProche = Stagiaire::query()
            ->where('statut', StagiaireStatut::STAGE_EN_COURS)
            ->whereDate('date_fin_stage', '<=', Carbon::now()->addDays(10)->toDateString())
            ->whereDate('date_fin_stage', '>=', Carbon::now()->toDateString())
            ->count();

        $noteMoyenne = Stagiaire::query()
            ->whereNotNull('note_finale')
            ->whereBetween('cloture_at', [$periode->debut, $periode->fin])
            ->avg('note_finale');

        // "Actif à une date donnée" reconstruit à partir des horodatages
        // d'affectation/clôture (pas d'historique de statuts pour
        // Stagiaire, à la différence de Courrier) : proxy raisonnable pour
        // une tendance de tableau de bord, pas une vérité historique exacte.
        $actifsDebutPeriode = Stagiaire::query()
            ->whereNotNull('affecte_at')
            ->where('affecte_at', '<=', $periode->debut)
            ->where(fn ($q) => $q->whereNull('cloture_at')->orWhere('cloture_at', '>', $periode->debut))
            ->count();

        $dossiersRecus = Stagiaire::query()->whereBetween('created_at', [$periode->debut, $periode->fin])->count();
        $dossiersRecusPrecedent = Stagiaire::query()->whereBetween('created_at', [$periode->debutPrecedente, $periode->finPrecedente])->count();

        $stagesClotures = Stagiaire::query()->whereBetween('cloture_at', [$periode->debut, $periode->fin])->count();
        $stagesCloturesPrecedent = Stagiaire::query()->whereBetween('cloture_at', [$periode->debutPrecedente, $periode->finPrecedente])->count();

        $parTypeStage = Stagiaire::query()
            ->selectRaw('type_stage, count(*) as total')
            ->groupBy('type_stage')
            ->pluck('total', 'type_stage');

        $parEtablissement = Stagiaire::query()
            ->whereNotNull('etablissement_id')
            ->join('etablissements_formation', 'etablissements_formation.id', '=', 'stagiaires.etablissement_id')
            ->selectRaw('etablissements_formation.id as etablissement_id, etablissements_formation.nom as etablissement_nom, count(*) as total')
            ->groupBy('etablissements_formation.id', 'etablissements_formation.nom')
            ->orderByDesc('total')
            ->get();

        $evolution = DB::table('stagiaires')
            ->selectRaw('date_trunc(?, created_at) as periode, count(*) as total', [$periode->granulariteSql()])
            ->whereBetween('created_at', [$periode->debut, $periode->fin])
            ->groupBy('periode')
            ->orderBy('periode')
            ->get();

        return response()->json([
            'stagiaires_actifs' => (int) ($parStatut[StagiaireStatut::STAGE_EN_COURS->value] ?? 0),
            'stagiaires_actifs_variation' => PeriodeStatistique::variationPourcentage(
                (int) ($parStatut[StagiaireStatut::STAGE_EN_COURS->value] ?? 0),
                $actifsDebutPeriode,
            ),
            'stagiaires_affectes' => $stagiairesAffectes,
            'en_attente_affectation' => $enAttenteAffectation,
            'total_dossiers' => (int) $parStatut->sum(),
            'dossiers_recus_periode' => $dossiersRecus,
            'dossiers_recus_variation' => PeriodeStatistique::variationPourcentage($dossiersRecus, $dossiersRecusPrecedent),
            'stages_clotures_periode' => $stagesClotures,
            'stages_clotures_variation' => PeriodeStatistique::variationPourcentage($stagesClotures, $stagesCloturesPrecedent),
            'echeance_10_jours' => $echeanceProche,
            'note_moyenne' => $noteMoyenne !== null ? round((float) $noteMoyenne, 1) : null,
            'periode' => [
                'cle' => $periode->cle,
                'depuis' => $periode->debut->toDateString(),
                'jusqua' => $periode->fin->toDateString(),
            ],
            'evolution' => $evolution->map(fn ($ligne) => [
                'periode' => Carbon::parse($ligne->periode)->toDateString(),
                'total' => (int) $ligne->total,
            ])->values(),
            'par_statut' => collect(StagiaireStatut::cases())->map(fn (StagiaireStatut $statut) => [
                'statut' => $statut->value,
                'label' => $statut->label(),
                'total' => (int) ($parStatut[$statut->value] ?? 0),
            ])->values(),
            'par_direction' => $parDirection->map(fn ($ligne) => [
                'direction_id' => $ligne->direction_id,
                'direction_nom' => $ligne->direction_nom,
                'total' => (int) $ligne->total,
            ])->values(),
            'par_type_stage' => collect(StagiaireTypeStage::cases())->map(fn (StagiaireTypeStage $type) => [
                'type_stage' => $type->value,
                'label' => $type->label(),
                'total' => (int) ($parTypeStage[$type->value] ?? 0),
            ])->values(),
            'par_etablissement' => $parEtablissement->map(fn ($ligne) => [
                'etablissement_id' => $ligne->etablissement_id,
                'etablissement_nom' => $ligne->etablissement_nom,
                'total' => (int) $ligne->total,
            ])->values(),
        ]);
    }

    /**
     * Alertes exploitables (pas de simples compteurs) pour le tableau de
     * bord : chaque entrée mène directement au dossier concerné. Le Global
     * Scope Eloquent de Stagiaire s'applique naturellement pour un
     * responsable de direction (DirectionScope, bypassé pour DFP/admin) —
     * les items transverses ci-dessous (demandes en attente, évaluations
     * incomplètes, quota des directions) n'ont de sens que pour la DFP/admin
     * et sont donc explicitement absents pour les autres rôles, pas
     * simplement scopés.
     */
    public function alertes(Request $request)
    {
        $this->authorize('voirStatistiques', Stagiaire::class);

        $evaluationAttenteOuverture = Stagiaire::query()
            ->whereIn('statut', [StagiaireStatut::STAGE_EN_COURS, StagiaireStatut::EVALUATION_EN_COURS])
            ->whereNull('periode_evaluation_ouverte_at')
            ->orderBy('date_fin_stage')
            ->get(['id', 'nom', 'statut', 'date_fin_stage'])
            ->map(fn (Stagiaire $s) => [
                'id' => $s->id, 'nom' => $s->nom, 'statut_label' => $s->statut->label(),
                'date_fin_stage' => $s->date_fin_stage?->toDateString(),
            ])->values();

        $echeance10Jours = Stagiaire::query()
            ->where('statut', StagiaireStatut::STAGE_EN_COURS)
            ->whereDate('date_fin_stage', '<=', now()->addDays(10)->toDateString())
            ->whereDate('date_fin_stage', '>=', now()->toDateString())
            ->orderBy('date_fin_stage')
            ->get(['id', 'nom', 'date_fin_stage'])
            ->map(fn (Stagiaire $s) => ['id' => $s->id, 'nom' => $s->nom, 'date_fin_stage' => $s->date_fin_stage?->toDateString(), 'jours_restants' => $s->joursRestants()])
            ->values();

        $donnees = [
            'evaluation_attente_ouverture' => $evaluationAttenteOuverture,
            'echeance_10_jours' => $echeance10Jours,
        ];

        if (in_array($request->user()->role, [UserRole::AGENT_DFP, UserRole::ADMINISTRATEUR], true)) {
            $seuilJours = $request->integer('seuil_jours', 3);

            $donnees['demandes_en_attente'] = Stagiaire::query()->withoutGlobalScopes()
                ->whereIn('statut', [StagiaireStatut::DOSSIER_RECU, StagiaireStatut::EN_ATTENTE_AFFECTATION])
                ->where('created_at', '<=', now()->subDays($seuilJours))
                ->orderBy('created_at')
                ->get(['id', 'nom', 'statut', 'created_at'])
                ->map(fn (Stagiaire $s) => ['id' => $s->id, 'nom' => $s->nom, 'statut_label' => $s->statut->label(), 'created_at' => $s->created_at])
                ->values();

            $donnees['evaluations_incompletes'] = Stagiaire::query()->withoutGlobalScopes()
                ->where('statut', StagiaireStatut::EVALUATION_EN_COURS)
                ->where(fn ($q) => $q->whereNotNull('evaluation_direction_at')->orWhereNotNull('evaluation_dfp_at'))
                ->where(fn ($q) => $q->whereNull('evaluation_direction_at')->orWhereNull('evaluation_dfp_at'))
                ->get(['id', 'nom', 'evaluation_direction_at', 'evaluation_dfp_at'])
                ->map(fn (Stagiaire $s) => [
                    'id' => $s->id, 'nom' => $s->nom,
                    'manque' => $s->evaluation_direction_at === null ? 'direction' : 'dfp',
                ])->values();

            // Même définition d'occupation que ActiveDirectionsAffectationRules::estSaturee().
            $donnees['directions_proches_quota'] = Direction::query()
                ->whereNotNull('capacite_max')
                ->get()
                ->map(function (Direction $direction) {
                    $occupation = Stagiaire::query()->withoutGlobalScopes()
                        ->where('direction_id', $direction->id)
                        ->whereIn('statut', [StagiaireStatut::AFFECTE, StagiaireStatut::STAGE_EN_COURS])
                        ->count();

                    return [
                        'direction_id' => $direction->id, 'direction_nom' => $direction->nom,
                        'occupation' => $occupation, 'capacite_max' => $direction->capacite_max,
                        'taux' => round($occupation / $direction->capacite_max, 2),
                    ];
                })
                ->filter(fn ($d) => $d['taux'] >= 0.8)
                ->sortByDesc('taux')
                ->values();
        }

        return response()->json($donnees);
    }
}
