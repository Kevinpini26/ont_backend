<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courrier\Http\Requests\AnnulerMissionDocumentaireRequest;
use Modules\Courrier\Http\Requests\CreerMissionDocumentaireRequest;
use Modules\Courrier\Http\Requests\RetournerMissionDocumentaireRequest;
use Modules\Courrier\Http\Resources\MissionDocumentaireResource;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Courrier\Services\MissionDocumentaireService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\User;

class MissionDocumentaireController extends Controller
{
    public function __construct(private readonly MissionDocumentaireService $missions) {}

    public function index(Request $request, Courrier $courrier)
    {
        $this->authorize('view', $courrier);

        $query = $courrier->missionsDocumentaires();
        if (in_array($request->user()->poste, [Poste::ASSISTANT_1, Poste::ASSISTANT_2, Poste::ASSISTANT_DGA], true)) {
            $query->where('assistant_id', $request->user()->id);
        }

        return MissionDocumentaireResource::collection($query
            ->with(['courrier', 'demandeur', 'assistant', 'annuleePar'])
            ->oldest()
            ->get());
    }

    public function mesMissions(Request $request)
    {
        return MissionDocumentaireResource::collection(MissionDocumentaire::query()
            ->where('assistant_id', $request->user()->id)
            ->with(['courrier', 'demandeur', 'assistant', 'annuleePar'])
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
            $request->validated('projet_reponse_contenu'),
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
        return new MissionDocumentaireResource($mission->load(['courrier', 'demandeur', 'assistant', 'annuleePar']));
    }
}
