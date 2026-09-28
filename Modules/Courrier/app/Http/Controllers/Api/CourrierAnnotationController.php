<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courrier\Http\Requests\StoreAnnotationRequest;
use Modules\Courrier\Http\Resources\CourrierAnnotationResource;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Services\CourrierCircuitService;

class CourrierAnnotationController extends Controller
{
    public function __construct(private readonly CourrierCircuitService $circuit) {}

    public function index(Request $request, Courrier $courrier)
    {
        $this->authorize('annoter', $courrier);

        $query = $courrier->annotations()->with('auteur');

        return CourrierAnnotationResource::collection($query->get());
    }

    public function store(StoreAnnotationRequest $request, Courrier $courrier)
    {
        $this->authorize('annoter', $courrier);

        $annotation = $this->circuit->ajouterAnnotation($courrier, $request->user(), $request->validated()['contenu']);

        return (new CourrierAnnotationResource($annotation->load('auteur')))->response()->setStatusCode(201);
    }
}
