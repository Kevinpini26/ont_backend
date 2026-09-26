<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Courrier\Http\Resources\DocumentDossierResource;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DocumentRelation;
use Modules\Courrier\Models\Dossier;

class DossierController extends Controller
{
    public function show(Request $request, Dossier $dossier)
    {
        $documents = Courrier::query()
            ->where('dossier_id', $dossier->id)
            ->with('classement')
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Courrier $document) => Gate::forUser($request->user())->allows('view', $document))
            ->values();

        abort_if($documents->isEmpty(), 404);

        return response()->json(['data' => [
            'id' => $dossier->id,
            'created_at' => $dossier->created_at,
            'statut_archivage' => $dossier->statut_archivage,
            'archivage_decide_at' => $dossier->archivage_decide_at,
            'archive_at' => $dossier->archive_at,
            'documents' => DocumentDossierResource::collection($documents),
        ]]);
    }

    public function relations(Request $request, Courrier $courrier)
    {
        $this->authorize('view', $courrier);

        $relations = DocumentRelation::query()
            ->where(fn ($query) => $query
                ->where('document_source_id', $courrier->id)
                ->orWhere('document_cible_id', $courrier->id))
            ->with(['source', 'cible', 'createur'])
            ->oldest()
            ->get()
            ->filter(function (DocumentRelation $relation) use ($request): bool {
                return $relation->source instanceof Courrier
                    && $relation->cible instanceof Courrier
                    && Gate::forUser($request->user())->allows('view', $relation->source)
                    && Gate::forUser($request->user())->allows('view', $relation->cible);
            })
            ->values()
            ->map(fn (DocumentRelation $relation) => [
                'id' => $relation->id,
                'type_relation' => $relation->type_relation->value,
                'type_relation_label' => $relation->type_relation->label(),
                'source' => new DocumentDossierResource($relation->source),
                'cible' => new DocumentDossierResource($relation->cible),
                'created_by' => $relation->createur?->name,
                'created_at' => $relation->created_at,
            ]);

        return response()->json(['data' => $relations]);
    }
}
