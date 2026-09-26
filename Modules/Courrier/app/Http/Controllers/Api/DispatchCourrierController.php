<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Http\Requests\DeciderDispatchRequest;
use Modules\Courrier\Http\Requests\ExecuterDispatchRequest;
use Modules\Courrier\Http\Resources\CourrierResource;
use Modules\Courrier\Http\Resources\DispatchCourrierResource;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Services\DispatchCourrierService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Support\DelegationResolver;

class DispatchCourrierController extends Controller
{
    public function __construct(private readonly DispatchCourrierService $service) {}

    public function index(Request $request, Courrier $courrier)
    {
        $this->authorize('view', $courrier);

        return DispatchCourrierResource::collection($courrier->dispatchs()->with(['direction', 'decisionnaire', 'executePar', 'accuseReceptionPar'])->get());
    }

    public function store(DeciderDispatchRequest $request, Courrier $courrier)
    {
        return new CourrierResource(
            $this->service->decider($courrier, $request->user(), $request->validated('destinations'))
        );
    }

    public function centre(Request $request)
    {
        abort_unless(app(DelegationResolver::class)->utilisateurHabilite($request->user(), [Poste::SECRETARIAT_2]), 403);
        $query = DispatchCourrier::query()->with(['courrier', 'direction', 'decisionnaire', 'executePar'])->latest('decide_at');
        if ($request->filled('statut')) {
            $query->where('statut', $request->string('statut'));
        }

        return DispatchCourrierResource::collection($query->paginate(20));
    }

    public function boiteDirection(Request $request)
    {
        abort_unless($request->user()->role === UserRole::SECRETARIAT_DIRECTION && $request->user()->direction_id !== null, 403);

        return DispatchCourrierResource::collection(DispatchCourrier::query()
            ->where('type_destination', DispatchTypeDestination::DIRECTION)
            ->where('statut', DispatchStatut::EXECUTE)
            ->where('direction_id', $request->user()->direction_id)
            ->with(['courrier', 'direction', 'decisionnaire', 'executePar', 'accuseReceptionPar', 'traitementDirection.directeur'])
            ->latest('execute_at')->paginate(20));
    }

    public function executer(ExecuterDispatchRequest $request, DispatchCourrier $dispatch)
    {
        return new DispatchCourrierResource($this->service->executer(
            $dispatch,
            $request->user(),
            $request->file('preuve'),
            $request->validated('reference_transmission'),
        ));
    }

    public function accuserReception(Request $request, DispatchCourrier $dispatch)
    {
        $this->authorize('accuserReception', $dispatch);

        return new DispatchCourrierResource($this->service->accuserReception($dispatch, $request->user()));
    }
}
