<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courrier\Http\Requests\StoreAnnotationRequest;
use Modules\Courrier\Http\Resources\CourrierAnnotationResource;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Services\CourrierCircuitService;
use Modules\Kernel\Enums\Poste;

class CourrierAnnotationController extends Controller
{
    public function __construct(private readonly CourrierCircuitService $circuit) {}

    public function index(Request $request, Courrier $courrier)
    {
        $this->authorize('view', $courrier);

        $query = $courrier->annotations()->with('auteur');
        if ($request->user()->poste === Poste::SECRETARIAT_1 && ! $request->user()->can('annoter', $courrier)) {
            $query->where('auteur_id', $request->user()->id);
        }

        return CourrierAnnotationResource::collection($query->get());
    }

    public function store(StoreAnnotationRequest $request, Courrier $courrier)
    {
        $this->authorize('view', $courrier);

        $annotation = $this->circuit->ajouterAnnotation($courrier, $request->user(), $request->validated()['contenu']);

        return (new CourrierAnnotationResource($annotation->load('auteur')))->response()->setStatusCode(201);
    }
}
