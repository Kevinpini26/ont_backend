<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courrier\Http\Requests\StoreAnnotationRequest;
use Modules\Courrier\Http\Resources\CourrierAnnotationResource;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierAnnotation;
use Modules\Courrier\Services\CourrierCircuitService;

class CourrierAnnotationController extends Controller
{
    public function __construct(private readonly CourrierCircuitService $circuit) {}

    public function index(Request $request, Courrier $courrier)
    {
        $this->authorize('annoter', $courrier);

        $query = $courrier->annotations()
            ->select('courrier_annotations.*')
            ->selectRaw("courrier_annotations.created_at AT TIME ZONE current_setting('TimeZone') AS created_at_with_timezone")
            ->with('auteur');

        return CourrierAnnotationResource::collection($query->get());
    }

    public function store(StoreAnnotationRequest $request, Courrier $courrier)
    {
        $this->authorize('annoter', $courrier);

        $annotation = $this->circuit->ajouterAnnotation($courrier, $request->user(), $request->validated()['contenu']);
        $annotation = CourrierAnnotation::query()
            ->select('courrier_annotations.*')
            ->selectRaw("courrier_annotations.created_at AT TIME ZONE current_setting('TimeZone') AS created_at_with_timezone")
            ->with('auteur')
            ->findOrFail($annotation->id);

        return (new CourrierAnnotationResource($annotation))->response()->setStatusCode(201);
    }
}
