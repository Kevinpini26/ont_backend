<?php

namespace Modules\Stagiaires\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Stagiaires\Enums\DocumentType;
use Modules\Stagiaires\Http\Requests\UploadDocumentRequest;
use Modules\Stagiaires\Http\Resources\StagiaireDocumentResource;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Models\StagiaireDocument;
use Modules\Stagiaires\Services\StagiaireCircuitService;

class StagiaireDocumentController extends Controller
{
    public function __construct(private readonly StagiaireCircuitService $circuit) {}

    public function index(Stagiaire $stagiaire)
    {
        $this->authorize('view', $stagiaire);

        return StagiaireDocumentResource::collection($stagiaire->documents()->latest()->get());
    }

    public function store(UploadDocumentRequest $request, Stagiaire $stagiaire)
    {
        $fichier = $request->file('fichier');
        $chemin = $fichier->store("stagiaires/{$stagiaire->id}", 'local');

        $document = $this->circuit->ajouterDocument(
            $stagiaire,
            $request->user(),
            DocumentType::from($request->validated()['type']),
            $fichier->getClientOriginalName(),
            $chemin,
        );

        return (new StagiaireDocumentResource($document))->response()->setStatusCode(201);
    }

    public function download(Stagiaire $stagiaire, StagiaireDocument $document)
    {
        $this->authorize('view', $stagiaire);

        abort_unless($document->stagiaire_id === $stagiaire->id, 404);

        // Accès au dossier (ci-dessus) et accès à cette pièce précise sont
        // deux gardes distinctes : pièce d'identité, diplômes et CV
        // restent réservés à la DFP même pour quelqu'un qui voit par
        // ailleurs le dossier (ex. la direction d'accueil) — voir
        // StagiairePolicy::telechargerDocument().
        $this->authorize('telechargerDocument', [$stagiaire, $document->type]);

        return Storage::disk('local')->download($document->chemin, $document->nom_original);
    }
}
