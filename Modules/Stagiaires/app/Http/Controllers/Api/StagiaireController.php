<?php

namespace Modules\Stagiaires\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Kernel\Enums\UserRole;
use Modules\Stagiaires\Contracts\BadgeStagiairePdfGenerator;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Http\Requests\AffecterStagiaireRequest;
use Modules\Stagiaires\Http\Requests\DefinirInformationsComplementairesRequest;
use Modules\Stagiaires\Http\Requests\DefinirObjectifsRequest;
use Modules\Stagiaires\Http\Requests\EvaluerDfpRequest;
use Modules\Stagiaires\Http\Requests\EvaluerDirectionRequest;
use Modules\Stagiaires\Http\Requests\ModifierDatesStageRequest;
use Modules\Stagiaires\Http\Requests\OuvrirPeriodeEvaluationRequest;
use Modules\Stagiaires\Http\Requests\ProlongerStageRequest;
use Modules\Stagiaires\Http\Requests\ReaffecterStagiaireRequest;
use Modules\Stagiaires\Http\Requests\ValiderArriveeRequest;
use Modules\Stagiaires\Http\Resources\StagiaireResource;
use Modules\Stagiaires\Http\Resources\StagiaireRetourResource;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Services\StagiaireCircuitService;
use Modules\Stagiaires\Support\VisibiliteStagiairePourCircuitCourrier;

class StagiaireController extends Controller
{
    public function __construct(
        private readonly StagiaireCircuitService $circuit,
        private readonly VisibiliteStagiairePourCircuitCourrier $visibiliteCircuitCourrier,
        private readonly BadgeStagiairePdfGenerator $badges,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Stagiaire::class);

        $query = Stagiaire::query()->with('direction');

        // Le DirectionScope global filtre déjà les responsables de
        // direction sur leur propre direction. Un agent du circuit
        // courrier (poste central, donc hors périmètre de ce scope) ne
        // doit voir que les dossiers dont le courrier d'origine est
        // encore dans sa propre file — voir StagiairePolicy::view() pour
        // la même règle appliquée fiche par fiche.
        if ($request->user()->role === UserRole::AGENT_CIRCUIT_COURRIER) {
            $this->visibiliteCircuitCourrier->appliquerFiltre($query, $request->user()->poste);
        }

        // Opt-in explicite : charge la relation 'presences' seulement pour
        // l'aperçu présences (voir PresencesApercuPage.jsx côté frontend),
        // toujours combiné à en_cours donc borné aux stages actifs — jamais
        // sur la liste par défaut, pour ne pas introduire de N+1 silencieux.
        if ($request->boolean('avec_assiduite')) {
            $query->with('presences');
        }

        if ($request->filled('direction_id')) {
            $query->where('direction_id', $request->integer('direction_id'));
        }

        if ($request->filled('statut')) {
            $query->where('statut', $request->string('statut'));
        }

        if ($request->boolean('echeance_proche')) {
            $query->where('statut', StagiaireStatut::STAGE_EN_COURS)
                ->whereDate('date_fin_stage', '<=', now()->addDays(10)->toDateString())
                ->whereDate('date_fin_stage', '>=', now()->toDateString());
        }

        // Séparation en cours / à venir / historique (déjà couvert par
        // statut=cloture) : le dashboard et les listes par défaut ne
        // doivent jamais mélanger l'actif avec les archives.
        if ($request->boolean('en_cours')) {
            $query->whereIn('statut', [StagiaireStatut::STAGE_EN_COURS, StagiaireStatut::EVALUATION_EN_COURS]);
        }

        if ($request->boolean('a_venir')) {
            $query->where('statut', StagiaireStatut::AFFECTE);
        }

        // Pré-affectation : jamais "actif", vit sur sa propre page "Demandes
        // de stage" plutôt que mélangé aux listes de stagiaires en cours.
        if ($request->boolean('en_attente_traitement')) {
            $query->whereIn('statut', [StagiaireStatut::DOSSIER_RECU, StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        }

        // Filtres utilisés par la vue "Historique des stagiaires" (archives
        // DFP) : la liste n'est jamais purgée, ces filtres doivent donc
        // rester exploitables même après plusieurs années d'accumulation.
        if ($request->filled('annee')) {
            $query->whereYear('cloture_at', $request->integer('annee'));
        }

        if ($request->filled('etablissement_origine')) {
            $query->where('etablissement_origine', 'ilike', '%'.$request->string('etablissement_origine').'%');
        }

        if ($request->filled('nom')) {
            $query->where('nom', 'ilike', '%'.$request->string('nom').'%');
        }

        if ($request->filled('type_stage')) {
            $query->where('type_stage', $request->string('type_stage'));
        }

        if ($request->filled('maitre_stage')) {
            $query->where('maitre_stage', 'ilike', '%'.$request->string('maitre_stage').'%');
        }

        if ($request->filled('conseiller_stage')) {
            $query->where('conseiller_stage', 'ilike', '%'.$request->string('conseiller_stage').'%');
        }

        // Recherche libre (nom, prénom, numéro de dossier) : remplace le
        // filtrage client historique de DfpStagiairesPage.jsx, qui ne
        // portait que sur la page déjà chargée.
        if ($request->filled('recherche')) {
            $terme = '%'.$request->string('recherche').'%';
            $query->where(fn ($q) => $q->where('nom', 'ilike', $terme)->orWhere('reference_courrier', 'ilike', $terme));
        }

        return StagiaireResource::collection($query->latest()->paginate(20));
    }

    public function show(Stagiaire $stagiaire)
    {
        $this->authorize('view', $stagiaire);

        return new StagiaireResource($stagiaire->load([
            'direction', 'presences', 'documents', 'doublonStagiaire', 'liensPublics', 'conventionSigneeDirectionPar',
            'prolongations.prolongePar',
        ]));
    }

    public function examinerDossier(Stagiaire $stagiaire)
    {
        $this->authorize('gererDossier', Stagiaire::class);

        return new StagiaireResource($this->circuit->examinerDossier($stagiaire)->load(['direction', 'conventionSigneeDirectionPar']));
    }

    public function affecter(AffecterStagiaireRequest $request, Stagiaire $stagiaire)
    {
        $data = $request->validated();

        $stagiaire = $this->circuit->affecter(
            $stagiaire,
            $request->user(),
            $data['direction_id'],
            $data['forcer'] ?? false,
            $data['justification'] ?? null,
        );

        return new StagiaireResource($stagiaire->load(['direction', 'conventionSigneeDirectionPar']));
    }

    public function reaffecter(ReaffecterStagiaireRequest $request, Stagiaire $stagiaire)
    {
        $data = $request->validated();

        $stagiaire = $this->circuit->reaffecter(
            $stagiaire,
            $request->user(),
            $data['direction_id'],
            $data['justification'],
            $data['forcer'] ?? false,
        );

        return new StagiaireResource($stagiaire->load(['direction', 'conventionSigneeDirectionPar']));
    }

    public function validerArrivee(ValiderArriveeRequest $request, Stagiaire $stagiaire)
    {
        $data = $request->validated();

        $stagiaire = $this->circuit->validerArrivee(
            $stagiaire,
            Carbon::parse($data['date_debut_stage']),
            isset($data['date_fin_stage']) ? Carbon::parse($data['date_fin_stage']) : null,
        );

        return new StagiaireResource($stagiaire->load(['direction', 'conventionSigneeDirectionPar', 'liensPublics']));
    }

    public function terminerStage(Stagiaire $stagiaire)
    {
        $this->authorize('terminerStage', $stagiaire);

        return new StagiaireResource($this->circuit->terminerStage($stagiaire)->load(['direction', 'conventionSigneeDirectionPar']));
    }

    public function modifierDatesStage(ModifierDatesStageRequest $request, Stagiaire $stagiaire)
    {
        $data = $request->validated();

        $stagiaire = $this->circuit->modifierDatesStage(
            $stagiaire,
            $request->user(),
            Carbon::parse($data['date_debut_stage']),
            Carbon::parse($data['date_fin_stage']),
        );

        return new StagiaireResource($stagiaire->load(['direction', 'conventionSigneeDirectionPar', 'prolongations.prolongePar']));
    }

    public function prolonger(ProlongerStageRequest $request, Stagiaire $stagiaire)
    {
        $data = $request->validated();

        $stagiaire = $this->circuit->prolongerStage(
            $stagiaire,
            $request->user(),
            Carbon::parse($data['nouvelle_date_fin']),
            $data['motif'],
        );

        return new StagiaireResource($stagiaire->load(['direction', 'conventionSigneeDirectionPar', 'prolongations.prolongePar']));
    }

    public function definirObjectifs(DefinirObjectifsRequest $request, Stagiaire $stagiaire)
    {
        $stagiaire = $this->circuit->definirObjectifs($stagiaire, $request->validated()['objectifs']);

        return new StagiaireResource($stagiaire->load(['direction', 'conventionSigneeDirectionPar']));
    }

    public function definirInformationsComplementaires(DefinirInformationsComplementairesRequest $request, Stagiaire $stagiaire)
    {
        $stagiaire = $this->circuit->definirInformationsComplementaires($stagiaire, $request->validated());

        return new StagiaireResource($stagiaire->load(['direction', 'conventionSigneeDirectionPar']));
    }

    public function signerConventionDirection(Stagiaire $stagiaire, Request $request)
    {
        $this->authorize('signerConventionDirection', $stagiaire);

        $stagiaire = $this->circuit->signerConventionDirection($stagiaire, $request->user());

        return new StagiaireResource($stagiaire->load(['direction', 'conventionSigneeDirectionPar']));
    }

    public function telechargerConvention(Stagiaire $stagiaire)
    {
        $this->authorize('view', $stagiaire);

        abort_unless($stagiaire->convention_chemin, 404);

        return Storage::disk('local')->download($stagiaire->convention_chemin, "convention-stage-{$stagiaire->nom}.pdf");
    }

    public function badge(Stagiaire $stagiaire)
    {
        $this->authorize('view', $stagiaire);

        // Un badge n'a de sens qu'une fois affecté (matricule et direction
        // connus) — voir Stagiaire::matricule, attribué à l'affectation.
        abort_unless($stagiaire->matricule !== null, 422);

        $pdf = $this->badges->generer($stagiaire->load('documents'));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"badge-{$stagiaire->matricule}.pdf\"",
        ]);
    }

    public function retour(Stagiaire $stagiaire)
    {
        $this->authorize('voirRetour', Stagiaire::class);

        $stagiaire->loadMissing('retour');

        if (! $stagiaire->retour) {
            return response()->json(['message' => "Aucun retour d'expérience soumis pour ce dossier."], 404);
        }

        return new StagiaireRetourResource($stagiaire->retour);
    }

    public function evaluerDirection(EvaluerDirectionRequest $request, Stagiaire $stagiaire)
    {
        $data = $request->validated();

        $stagiaire = $this->circuit->evaluerParDirection($stagiaire, $request->user(), $data['grille']);

        return new StagiaireResource($stagiaire->load(['direction', 'conventionSigneeDirectionPar', 'liensPublics']));
    }

    public function evaluerDfp(EvaluerDfpRequest $request, Stagiaire $stagiaire)
    {
        $data = $request->validated();

        $stagiaire = $this->circuit->evaluerParDfp($stagiaire, $request->user(), $data['grille']);

        return new StagiaireResource($stagiaire->load(['direction', 'conventionSigneeDirectionPar', 'liensPublics']));
    }

    public function ouvrirPeriodeEvaluation(OuvrirPeriodeEvaluationRequest $request, Stagiaire $stagiaire)
    {
        $stagiaire = $this->circuit->ouvrirPeriodeEvaluation($stagiaire, $request->user());

        return new StagiaireResource($stagiaire->load(['direction', 'conventionSigneeDirectionPar']));
    }
}
