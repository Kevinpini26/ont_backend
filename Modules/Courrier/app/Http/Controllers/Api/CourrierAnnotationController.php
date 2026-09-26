<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Modules\Courrier\Http\Requests\StoreAnnotationRequest;
use Modules\Courrier\Http\Resources\CourrierAnnotationResource;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Services\CourrierCircuitService;

class CourrierAnnotationController extends Controller
{
    public function __construct(private readonly CourrierCircuitService $circuit) {}

    public function index(Courrier $courrier)
    {
        $this->authorize('view', $courrier);

        return CourrierAnnotationResource::collection($courrier->annotations()->with('auteur')->get());
    }

    public function store(StoreAnnotationRequest $request, Courrier $courrier)
    {
        $this->authorize('view', $courrier);

        $annotation = $this->circuit->ajouterAnnotation($courrier, $request->user(), $request->validated()['contenu']);

        return (new CourrierAnnotationResource($annotation->load('auteur')))->response()->setStatusCode(201);
    }
}
