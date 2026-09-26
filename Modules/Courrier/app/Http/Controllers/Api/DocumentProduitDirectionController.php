<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courrier\Http\Requests\CreerDocumentProduitRequest;
use Modules\Courrier\Http\Requests\DemanderCorrectionDocumentRequest;
use Modules\Courrier\Http\Requests\SoumettreDocumentProduitRequest;
use Modules\Courrier\Http\Resources\DocumentProduitDirectionResource;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Courrier\Services\DocumentProduitDirectionService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;

class DocumentProduitDirectionController extends Controller
{
    public function __construct(private readonly DocumentProduitDirectionService $service) {}

    public function index(Request $request)
    {
        $q = DocumentProduitDirection::query()->with(['courrier', 'documentSource', 'direction', 'traitement.directeur']);
        if ($request->user()->poste === Poste::RECEPTION) {
            $q->whereIn('statut', ['transmis_reception', 'entre_circuit']);
        } else {
            abort_unless(
                $request->user()->direction_id !== null
                && in_array($request->user()->role, [UserRole::SECRETARIAT_DIRECTION, UserRole::DIRECTEUR_DIRECTION, UserRole::RESPONSABLE_DIRECTION], true),
                403,
            );
            $q->where('direction_id', $request->user()->direction_id);
        }

        return DocumentProduitDirectionResource::collection($q->latest()->paginate(20));
    }

    public function store(CreerDocumentProduitRequest $request, TraitementDirection $traitement)
    {
        return new DocumentProduitDirectionResource($this->service->creer($traitement, $request->user(), $request->validated()));
    }

    public function soumettre(SoumettreDocumentProduitRequest $request, DocumentProduitDirection $document)
    {
        return new DocumentProduitDirectionResource($this->service->soumettre($document, $request->user(), $request->validated()));
    }

    public function correction(DemanderCorrectionDocumentRequest $request, DocumentProduitDirection $document)
    {
        return new DocumentProduitDirectionResource($this->service->demanderCorrection($document, $request->user(), $request->validated('motif')));
    }

    public function valider(Request $request, DocumentProduitDirection $document)
    {
        $this->authorize('statuer', $document);

        return new DocumentProduitDirectionResource($this->service->valider($document, $request->user()));
    }

    public function transmettreReception(Request $request, DocumentProduitDirection $document)
    {
        $this->authorize('transmettreReception', $document);

        return new DocumentProduitDirectionResource($this->service->transmettreReception($document, $request->user()));
    }

    public function recevoir(Request $request, DocumentProduitDirection $document)
    {
        $this->authorize('recevoir', $document);

        return new DocumentProduitDirectionResource($this->service->recevoir($document, $request->user()));
    }
}
