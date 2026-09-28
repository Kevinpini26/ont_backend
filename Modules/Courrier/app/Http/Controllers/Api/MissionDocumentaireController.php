<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Courrier\Http\Requests\AnnulerMissionDocumentaireRequest;
use Modules\Courrier\Http\Requests\CreerMissionDocumentaireRequest;
use Modules\Courrier\Http\Requests\CreerProjetReponseMissionRequest;
use Modules\Courrier\Http\Requests\DemanderPreparationReponseRequest;
use Modules\Courrier\Http\Requests\RetournerMissionDocumentaireRequest;
use Modules\Courrier\Http\Requests\SauvegarderProjetReponseRequest;
use Modules\Courrier\Http\Requests\SoumettreProjetReponseMissionRequest;
use Modules\Courrier\Http\Resources\CourrierResource;
use Modules\Courrier\Http\Resources\MissionDocumentaireResource;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Courrier\Services\CourrierCircuitService;
use Modules\Courrier\Services\MissionDocumentaireService;
use Modules\Kernel\Models\User;

class MissionDocumentaireController extends Controller
{
    public function __construct(
        private readonly MissionDocumentaireService $missions,
        private readonly CourrierCircuitService $circuit,
    ) {}

    public function index(Request $request, Courrier $courrier)
    {
        $this->authorize('view', $courrier);

        return MissionDocumentaireResource::collection($courrier->missionsDocumentaires()
            ->with(['courrier', 'projetCourrier', 'demandeur', 'assistant', 'annuleePar'])
            ->oldest()
            ->get()
            ->filter(fn ($mission) => Gate::forUser($request->user())->allows('view', $mission))
            ->values());
    }

    public function mesMissions(Request $request)
    {
        return MissionDocumentaireResource::collection(MissionDocumentaire::query()
            ->where('assistant_id', $request->user()->id)
            ->with(['courrier', 'projetCourrier', 'demandeur', 'assistant', 'annuleePar'])
            ->latest('envoyee_at')
            ->get());
    }

    public function store(CreerMissionDocumentaireRequest $request, Courrier $courrier)
    {
        $mission = $this->missions->creer(
            $courrier,
            $request->user(),
            User::query()->findOrFail($request->integer('assistant_id')),
            $request->string('instruction')->toString(),
        );

        return $this->ressource($mission)->response()->setStatusCode(201);
    }

    public function demanderPreparationReponse(DemanderPreparationReponseRequest $request, Courrier $courrier)
    {
        $mission = $this->missions->creerPreparationReponse(
            $courrier,
            $request->user(),
            User::query()->findOrFail($request->integer('assistant_id')),
            $request->string('instruction')->toString(),
        );

        return $this->ressource($mission)->response()->setStatusCode(201);
    }

    public function creerProjet(CreerProjetReponseMissionRequest $request, MissionDocumentaire $mission)
    {
        $courrier = $this->circuit->creerProjetReponseMission($mission, $request->user(), $request->validated());

        return $this->ressource($mission->fresh())->additional(['projet' => new CourrierResource($courrier)])
            ->response()->setStatusCode(201);
    }

    public function sauvegarderProjet(SauvegarderProjetReponseRequest $request, MissionDocumentaire $mission)
    {
        $courrier = $this->circuit->sauvegarderProjetReponseMission($mission, $request->user(), $request->validated());

        return new CourrierResource($courrier->load(['relecteur', 'transitions.auteur', 'transitions.destinataireUser', 'transitions.accuseReceptionPar']));
    }

    public function soumettreProjet(SoumettreProjetReponseMissionRequest $request, MissionDocumentaire $mission)
    {
        $courrier = $this->circuit->soumettreProjetReponseMission(
            $mission,
            $request->user(),
            $request->validated('projet_reponse_contenu'),
        );

        return new CourrierResource($courrier->load(['relecteur', 'transitions.auteur', 'transitions.destinataireUser', 'transitions.accuseReceptionPar']));
    }

    public function prendreEnCharge(Request $request, MissionDocumentaire $mission)
    {
        $this->authorize('prendreEnCharge', $mission);

        return $this->ressource($this->missions->prendreEnCharge($mission, $request->user()));
    }

    public function retourner(RetournerMissionDocumentaireRequest $request, MissionDocumentaire $mission)
    {
        return $this->ressource($this->missions->retourner(
            $mission,
            $request->user(),
            $request->string('compte_rendu')->toString(),
        ));
    }

    public function annuler(AnnulerMissionDocumentaireRequest $request, MissionDocumentaire $mission)
    {
        return $this->ressource($this->missions->annuler(
            $mission,
            $request->user(),
            $request->string('motif')->toString(),
        ));
    }

    private function ressource(MissionDocumentaire $mission): MissionDocumentaireResource
    {
        return new MissionDocumentaireResource($mission->load(['courrier', 'projetCourrier', 'demandeur', 'assistant', 'annuleePar']));
    }
}
