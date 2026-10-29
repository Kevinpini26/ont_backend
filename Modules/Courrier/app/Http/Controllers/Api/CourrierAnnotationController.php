<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Courrier\Http\Requests\CorrigerAnnotationRequest;
use Modules\Courrier\Http\Requests\StoreAnnotationRequest;
use Modules\Courrier\Http\Resources\CourrierAnnotationResource;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierAnnotation;
use Modules\Courrier\Services\CourrierAnnotationService;

class CourrierAnnotationController extends Controller
{
    public function __construct(private readonly CourrierAnnotationService $notes) {}

    public function index(Request $request, Courrier $courrier)
    {
        $this->authorize('annoter', $courrier);
        $this->masquerSiNecessaire($request, $courrier);

        $annotations = $courrier->annotations()
            ->visiblesPour($request->user())
            ->avecFuseau()
            ->with(['auteur', 'destinataireDirection', 'lectures.user', 'correctionDe'])
            // Une note urgente encore à jour remonte en tête ; le reste, du plus récent au plus ancien (ordre historique de la liste).
            ->reorder()
            ->orderByRaw("case when courrier_annotations.type = 'urgent' and courrier_annotations.annule_par_id is null then 0 else 1 end")
            ->orderByDesc('courrier_annotations.id')
            ->get();

        return CourrierAnnotationResource::collection($annotations);
    }

    public function store(StoreAnnotationRequest $request, Courrier $courrier)
    {
        $this->authorize('annoter', $courrier);
        // L'administrateur ne lit pas le fond d'un courrier confidentiel : il n'y écrit pas non plus.
        $this->authorize('consulterContenu', $courrier);

        $annotation = $this->notes->creer($courrier, $request->user(), $request->validated()['contenu'], $request->safe()->except('contenu'));

        return $this->reponse($request, $courrier, $annotation, 201);
    }

    public function corriger(CorrigerAnnotationRequest $request, Courrier $courrier, CourrierAnnotation $annotation)
    {
        $this->authorize('consulterContenu', $courrier);
        $this->assertAnnotationVisible($request, $courrier, $annotation);

        $nouvelle = $this->notes->corriger($annotation, $request->user(), $request->validated()['contenu']);

        return $this->reponse($request, $courrier, $nouvelle, 201);
    }

    public function lire(Request $request, Courrier $courrier, CourrierAnnotation $annotation)
    {
        $this->authorize('annoter', $courrier);
        $this->authorize('consulterContenu', $courrier);
        $this->assertAnnotationVisible($request, $courrier, $annotation);

        $this->notes->marquerLue($annotation, $request->user());

        return $this->reponse($request, $courrier, $annotation, 200);
    }

    /** Aucune suppression d'une note du circuit : seul un rappel personnel peut être retiré par son auteur. */
    public function retirer(Request $request, Courrier $courrier, CourrierAnnotation $annotation)
    {
        $this->assertAnnotationVisible($request, $courrier, $annotation);

        $this->notes->retirerRappel($annotation, $request->user());

        return response()->json(null, 204);
    }

    public function instructionsNonLues(Request $request)
    {
        $requete = $this->notes->instructionsNonLues($request->user());
        $total = (clone $requete)->count();

        $annotations = $requete
            ->avecFuseau()
            ->with(['auteur', 'destinataireDirection', 'lectures.user', 'courrier'])
            ->latest('courrier_annotations.id')
            ->limit(50)
            ->get();

        $request->attributes->set('annotations_courriers', true);

        return CourrierAnnotationResource::collection($annotations)->additional(['meta' => ['total' => $total]]);
    }

    private function reponse(Request $request, Courrier $courrier, CourrierAnnotation $annotation, int $statut)
    {
        $this->masquerSiNecessaire($request, $courrier);
        $annotation = CourrierAnnotation::query()
            ->avecFuseau()
            ->with(['auteur', 'destinataireDirection', 'lectures.user', 'correctionDe'])
            ->findOrFail($annotation->id);

        return (new CourrierAnnotationResource($annotation))->response()->setStatusCode($statut);
    }

    private function masquerSiNecessaire(Request $request, Courrier $courrier): void
    {
        $request->attributes->set('annotations_masquees', ! Gate::forUser($request->user())->allows('consulterContenu', $courrier));
    }

    /** Une note d'un autre courrier, ou le rappel personnel d'un autre utilisateur, n'existe pas pour l'appelant. */
    private function assertAnnotationVisible(Request $request, Courrier $courrier, CourrierAnnotation $annotation): void
    {
        $visible = $annotation->courrier_id === $courrier->id
            && CourrierAnnotation::query()->visiblesPour($request->user())->whereKey($annotation->id)->exists();

        abort_unless($visible, 404);
    }
}
