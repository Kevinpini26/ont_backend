<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courrier\Enums\DecisionDirection;
use Modules\Courrier\Http\Requests\DecisionDirectionRequest;
use Modules\Courrier\Http\Requests\TransmettreDirecteurRequest;
use Modules\Courrier\Http\Resources\TraitementDirectionResource;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Courrier\Services\TraitementDirectionService;
use Modules\Kernel\Enums\UserRole;

class TraitementDirectionController extends Controller
{
    public function __construct(private readonly TraitementDirectionService $service) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->direction_id !== null && in_array($request->user()->role, [UserRole::SECRETARIAT_DIRECTION, UserRole::DIRECTEUR_DIRECTION, UserRole::RESPONSABLE_DIRECTION], true), 403);
        $query = TraitementDirection::query()->where('direction_id', $request->user()->direction_id)
            ->with(['courrier', 'dispatch.direction', 'direction', 'recuParSecretariat', 'transmisParSecretariat', 'directeur', 'prisEnChargePar', 'decisionPar', 'documentProduit.courrier'])
            ->latest('recu_secretariat_at');
        if ($request->user()->role !== UserRole::SECRETARIAT_DIRECTION) {
            $query->where('directeur_id', $request->user()->id);
        }

        return TraitementDirectionResource::collection($query->paginate(20));
    }

    public function transmettreDirecteur(TransmettreDirecteurRequest $request, TraitementDirection $traitement)
    {
        return new TraitementDirectionResource($this->service->transmettreDirecteur($traitement, $request->user(), $request->validated('note')));
    }

    public function prendreEnCharge(Request $request, TraitementDirection $traitement)
    {
        $this->authorize('prendreEnCharge', $traitement);

        return new TraitementDirectionResource($this->service->prendreEnCharge($traitement, $request->user()));
    }

    public function decider(DecisionDirectionRequest $request, TraitementDirection $traitement)
    {
        return new TraitementDirectionResource($this->service->decider(
            $traitement, $request->user(), DecisionDirection::from($request->validated('decision')), $request->validated('commentaire')
        ));
    }
}
