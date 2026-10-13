<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Http\Requests\CreerDocumentProduitAutonomeRequest;
use Modules\Courrier\Http\Requests\CreerDocumentProduitRequest;
use Modules\Courrier\Http\Requests\DeciderDocumentAutonomeDgRequest;
use Modules\Courrier\Http\Requests\DemanderCorrectionDocumentRequest;
use Modules\Courrier\Http\Requests\DemanderCorrectionReceptionDocumentRequest;
use Modules\Courrier\Http\Requests\DeposerScanOfficielRequest;
use Modules\Courrier\Http\Requests\SauvegarderBrouillonDocumentProduitRequest;
use Modules\Courrier\Http\Requests\SoumettreDocumentProduitRequest;
use Modules\Courrier\Http\Resources\DocumentProduitDirectionResource;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Courrier\Services\CourrierCircuitService;
use Modules\Courrier\Services\DispatchCourrierService;
use Modules\Courrier\Services\DocumentProduitDirectionService;
use Modules\Courrier\Services\DocumentProduitScanOfficielService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentProduitDirectionController extends Controller
{
    private const ACTIONS_HISTORIQUE_AUTONOME = [
        'document_direction.autonome_cree',
        'document_direction.brouillon_modifie',
        'document_direction.soumis',
        'document_direction.correction_demandee',
        'document_direction.valide',
        'document_direction.scan_officiel_depose',
        'document_direction.autonome_transmis_reception',
        'document_direction.autonome_accepte_reception',
        'document_direction.autonome_correction_demandee_reception',
        'document_direction.autonome_pris_en_charge_sec1',
        'document_direction.autonome_transmis_dg_sec1',
        'document_direction.autonome_decide_dg',
    ];

    public function __construct(
        private readonly DocumentProduitDirectionService $service,
        private readonly DocumentProduitScanOfficielService $serviceScanOfficiel,
        private readonly CourrierCircuitService $circuit,
        private readonly DispatchCourrierService $dispatchs,
    ) {}

    public function index(Request $request)
    {
        $q = DocumentProduitDirection::query()->with([
            'courrier.directionOrigine',
            'documentSource',
            'direction',
            'directionDestinationSouhaitee',
            'traitement.directeur',
            'directeur',
            'creePar',
            'modifiePar',
            'soumisPar',
            'correctionDemandeePar',
            'validePar',
            'transmisReceptionPar',
            'recuPar',
        ]);
        if ($request->user()->poste === Poste::RECEPTION) {
            $q->where('origine', '!=', DocumentProduitOrigine::AUTONOME->value)
                ->whereIn('statut', ['transmis_reception', 'entre_circuit']);
        } else {
            abort_unless(
                $request->user()->direction_id !== null
                && in_array($request->user()->role, [UserRole::SECRETARIAT_DIRECTION, UserRole::DIRECTEUR_DIRECTION, UserRole::RESPONSABLE_DIRECTION], true),
                403,
            );
            $q->where('direction_id', $request->user()->direction_id);
        }

        $origine = $request->validate([
            'origine' => ['sometimes', Rule::enum(DocumentProduitOrigine::class)],
        ])['origine'] ?? null;

        if ($origine !== null) {
            $q->where('origine', $origine);
        }

        $user = $request->user();
        if ($user->role->estDirecteurDirection() && $origine !== DocumentProduitOrigine::TRAITEMENT_DIRECTION->value) {
            if ($origine === null) {
                $q->where(static fn ($documents) => $documents
                    ->where('origine', '!=', DocumentProduitOrigine::AUTONOME->value)
                    ->orWhere(static fn ($autonomes) => $autonomes
                        ->where('origine', DocumentProduitOrigine::AUTONOME->value)
                        ->visibleAuDirecteur($user)));
            } else {
                $q->visibleAuDirecteur($user);
            }
        }

        return DocumentProduitDirectionResource::collection(
            $q->latest()->paginate(20)->appends($request->query()),
        );
    }

    public function autonomesTransmisReception(Request $request)
    {
        abort_unless(
            $request->user()->role === UserRole::AGENT_CIRCUIT_COURRIER
            && $request->user()->poste === Poste::RECEPTION,
            403,
        );

        $query = DocumentProduitDirection::query()
            ->where('origine', DocumentProduitOrigine::AUTONOME)
            ->where('statut', 'transmis_reception')
            ->whereNotNull('transmis_reception_par_id')
            ->whereNotNull('transmis_reception_at')
            ->whereNull('recu_par_id')
            ->whereNull('recu_at')
            ->whereNotNull('scan_officiel_document_id')
            ->whereHas('courrier', fn ($courriers) => $courriers
                ->whereNotNull('reference_documentaire')
                ->whereNull('numero_enregistrement'))
            ->whereHas('scansOfficiels', fn ($scans) => $scans
                ->whereColumn('document_numerise_id', 'documents_produits_direction.scan_officiel_document_id')
                ->where('est_actif', true)
                ->where('attestation_confirmee', true)
                ->whereNotNull('atteste_at'))
            ->with([
                'courrier.directionOrigine',
                'direction',
                'directionDestinationSouhaitee',
                'directeur',
                'creePar',
                'soumisPar',
                'correctionDemandeePar',
                'validePar',
                'transmisReceptionPar',
                'recuPar',
                'scansOfficiels' => fn ($scans) => $scans->where('est_actif', true)->with('deposePar'),
                'auditLogs' => fn ($audits) => $audits
                    ->whereIn('action', self::ACTIONS_HISTORIQUE_AUTONOME)
                    ->with('utilisateur')
                    ->orderBy('created_at')
                    ->orderBy('id'),
            ])
            ->latest('transmis_reception_at');

        return DocumentProduitDirectionResource::collection($query->paginate(20));
    }

    public function autonomesAcceptesSec1(Request $request)
    {
        $this->authorize('consulterFileAutonomesSec1', DocumentProduitDirection::class);

        $query = DocumentProduitDirection::query()
            ->where('origine', DocumentProduitOrigine::AUTONOME)
            ->where('statut', 'entre_circuit')
            ->whereNotNull('transmis_reception_par_id')
            ->whereNotNull('transmis_reception_at')
            ->whereNotNull('recu_par_id')
            ->whereNotNull('recu_at')
            ->whereNotNull('scan_officiel_document_id')
            ->whereHas('courrier', fn ($courriers) => $courriers
                ->whereIn('statut', ['recu', 'en_attente_avis_dg'])
                ->whereNotNull('reference_documentaire')
                ->whereNotNull('numero_enregistrement'))
            ->whereHas('scansOfficiels', fn ($scans) => $scans
                ->whereColumn('document_numerise_id', 'documents_produits_direction.scan_officiel_document_id')
                ->where('est_actif', true)
                ->where('attestation_confirmee', true)
                ->whereNotNull('atteste_at'))
            ->with([
                'courrier.directionOrigine',
                'courrier.transitions.auteur',
                'courrier.transitions.accuseReceptionPar',
                'direction',
                'directionDestinationSouhaitee',
                'directeur',
                'transmisReceptionPar',
                'recuPar',
                'scansOfficiels' => fn ($scans) => $scans->where('est_actif', true)->with('deposePar'),
                'auditLogs' => fn ($audits) => $audits
                    ->whereIn('action', self::ACTIONS_HISTORIQUE_AUTONOME)
                    ->with('utilisateur')
                    ->orderBy('created_at')
                    ->orderBy('id'),
            ])
            ->latest('recu_at');

        return DocumentProduitDirectionResource::collection($query->paginate(20));
    }

    public function autonomesEnAttenteDg(Request $request)
    {
        $this->authorize('consulterFileAutonomesDg', DocumentProduitDirection::class);

        $query = DocumentProduitDirection::query()
            ->where('origine', DocumentProduitOrigine::AUTONOME)
            ->where('statut', 'entre_circuit')
            ->whereNotNull('transmis_reception_par_id')
            ->whereNotNull('transmis_reception_at')
            ->whereNotNull('recu_par_id')
            ->whereNotNull('recu_at')
            ->whereNotNull('scan_officiel_document_id')
            ->whereHas('courrier', fn ($courriers) => $courriers
                ->where('statut', 'en_attente_avis_dg')
                ->where('necessite_avis_dg', true)
                ->whereNotNull('reference_documentaire')
                ->whereNotNull('numero_enregistrement')
                ->whereHas('transitions', fn ($transitions) => $transitions
                    ->where('nouveau_statut', 'en_attente_avis_dg')
                    ->where('destinataire_poste', Poste::DG->value))
                ->whereDoesntHave('dispatchs'))
            ->whereHas('scansOfficiels', fn ($scans) => $scans
                ->whereColumn('document_numerise_id', 'documents_produits_direction.scan_officiel_document_id')
                ->where('est_actif', true)
                ->where('attestation_confirmee', true)
                ->whereNotNull('atteste_at'))
            ->with([
                'courrier.directionOrigine',
                'courrier.directionDestination',
                'courrier.transitions.auteur',
                'courrier.dispatchs.direction',
                'courrier.dispatchs.decisionnaire',
                'direction',
                'directionDestinationSouhaitee',
                'directeur',
                'transmisReceptionPar',
                'recuPar',
                'scansOfficiels' => fn ($scans) => $scans->where('est_actif', true)->with('deposePar'),
                'auditLogs' => fn ($audits) => $audits
                    ->whereIn('action', self::ACTIONS_HISTORIQUE_AUTONOME)
                    ->with('utilisateur')
                    ->orderBy('created_at')
                    ->orderBy('id'),
            ])
            ->latest('recu_at');

        return DocumentProduitDirectionResource::collection($query->paginate(20));
    }

    public function etatAutonomeDg(Request $request, DocumentProduitDirection $document)
    {
        $this->authorize('consulterAutonomeDg', $document);

        return new DocumentProduitDirectionResource($this->chargerPourDg($document));
    }

    public function deciderAutonomeDg(DeciderDocumentAutonomeDgRequest $request, DocumentProduitDirection $document)
    {
        $this->authorize('deciderAutonomeDg', $document);
        $documentDecide = $this->dispatchs->deciderDocumentAutonomeDg(
            $document,
            $request->user(),
            $request->validated(),
        );

        return new DocumentProduitDirectionResource($this->chargerPourDg($documentDecide));
    }

    public function etatSec1(Request $request, DocumentProduitDirection $document)
    {
        $this->authorize('consulterAutonomeSec1', $document);

        return new DocumentProduitDirectionResource($this->chargerAutonomeSec1($document));
    }

    public function prendreEnChargeAutonomeSec1(Request $request, DocumentProduitDirection $document)
    {
        $this->authorize('prendreEnChargeAutonomeSec1', $document);
        $this->circuit->prendreEnChargeAutonomeSec1($document, $request->user());

        return new DocumentProduitDirectionResource($this->chargerAutonomeSec1($document));
    }

    public function transmettreAutonomeDgDepuisSec1(Request $request, DocumentProduitDirection $document)
    {
        $this->authorize('transmettreAutonomeSec1', $document);
        $this->circuit->transmettreAutonomeDgDepuisSec1($document, $request->user());

        return new DocumentProduitDirectionResource($this->chargerAutonomeSec1($document));
    }

    public function etatControleReception(Request $request, DocumentProduitDirection $document)
    {
        $this->authorize('consulterControleAutonomeReception', $document);

        $document->load([
            'courrier.directionOrigine',
            'direction',
            'directionDestinationSouhaitee',
            'directeur',
            'correctionDemandeePar',
            'transmisReceptionPar',
            'recuPar',
        ]);

        return new DocumentProduitDirectionResource($this->chargerTracabilite($document, true));
    }

    public function store(CreerDocumentProduitRequest $request, TraitementDirection $traitement)
    {
        return new DocumentProduitDirectionResource($this->service->creer($traitement, $request->user(), $request->validated()));
    }

    public function storeAutonome(CreerDocumentProduitAutonomeRequest $request)
    {
        return (new DocumentProduitDirectionResource($this->chargerTracabilite(
            $this->service->creerAutonome($request->user(), $request->validated()),
            true,
        )))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, DocumentProduitDirection $document)
    {
        $this->authorize('view', $document);

        $document->load(['courrier.directionOrigine', 'documentSource', 'direction', 'directionDestinationSouhaitee', 'traitement.directeur', 'directeur', 'creePar', 'validePar', 'transmisReceptionPar', 'recuPar']);

        return new DocumentProduitDirectionResource($this->chargerTracabilite($document, true));
    }

    public function telechargerPdfOfficiel(Request $request, DocumentProduitDirection $document)
    {
        abort_unless($request->user()->can('downloadPdfOfficiel', $document), 404);

        if ($request->user()->role === UserRole::AGENT_CIRCUIT_COURRIER
            && $request->user()->poste === Poste::SECRETARIAT_1) {
            return $this->service->telechargerPdfOfficielAccepteSec1($document);
        }

        if ($request->user()->can('consulterAutonomeDg', $document)) {
            return $this->service->telechargerPdfOfficielEnAttenteDg($document);
        }

        return $this->service->telechargerPdfOfficiel($document);
    }

    public function consulterScanOfficiel(Request $request, DocumentProduitDirection $document): StreamedResponse
    {
        $user = $request->user();
        if ($user->role === UserRole::AGENT_CIRCUIT_COURRIER && $user->poste === Poste::RECEPTION) {
            abort_unless($user->can('consulterScanOfficiel', $document), 404);

            return $this->serviceScanOfficiel->consulterTransmisReception($document);
        }

        if ($user->role === UserRole::AGENT_CIRCUIT_COURRIER && $user->poste === Poste::SECRETARIAT_1) {
            abort_unless($user->can('consulterScanOfficiel', $document), 404);

            return $this->serviceScanOfficiel->consulterAccepteSec1($document);
        }

        if ($user->can('consulterAutonomeDg', $document)) {
            abort_unless($user->can('consulterScanOfficiel', $document), 404);

            return $this->serviceScanOfficiel->consulterEnAttenteDg($document);
        }

        abort_unless($request->user()->can('consulterScanOfficiel', $document), 404);

        return $this->serviceScanOfficiel->consulter($document);
    }

    public function deposerScanOfficiel(DeposerScanOfficielRequest $request, DocumentProduitDirection $document): JsonResponse
    {
        $resultat = $this->serviceScanOfficiel->deposer(
            $document,
            $request->user(),
            $request->file('scan'),
            $request->validated('idempotency_key'),
        );

        $scan = $resultat['scan'];

        return response()->json([
            'data' => [
                'id' => $scan->id,
                'document_produit_direction_id' => $scan->document_produit_direction_id,
                'courrier_id' => $scan->courrier_id,
                'document_numerise_id' => $scan->document_numerise_id,
                'version' => $scan->version,
                'sha256' => $scan->documentNumerise->sha256,
                'depose_par_id' => $scan->depose_par_id,
                'attestation_confirmee' => $scan->attestation_confirmee,
                'attestation_version' => $scan->attestation_version,
                'attestation_texte' => DocumentProduitScanOfficielService::ATTESTATION_TEXTE_V1,
                'atteste_at' => $scan->atteste_at,
                'est_actif' => $scan->est_actif,
                'created_at' => $scan->created_at,
            ],
        ], $resultat['created'] ? 201 : 200);
    }

    public function modifierBrouillon(SauvegarderBrouillonDocumentProduitRequest $request, DocumentProduitDirection $document)
    {
        return new DocumentProduitDirectionResource($this->chargerTracabilite(
            $this->service->modifierBrouillon($document, $request->user(), $request->validated()),
            true,
        ));
    }

    public function soumettre(SoumettreDocumentProduitRequest $request, DocumentProduitDirection $document)
    {
        return new DocumentProduitDirectionResource($this->chargerTracabilite(
            $this->service->soumettre($document, $request->user(), $request->validated()),
            true,
        ));
    }

    public function correction(DemanderCorrectionDocumentRequest $request, DocumentProduitDirection $document)
    {
        return new DocumentProduitDirectionResource($this->chargerTracabilite(
            $this->service->demanderCorrection($document, $request->user(), $request->validated('motif')),
            true,
        ));
    }

    public function valider(Request $request, DocumentProduitDirection $document)
    {
        $this->authorize('statuer', $document);

        return new DocumentProduitDirectionResource($this->chargerTracabilite(
            $this->service->valider($document, $request->user()),
            true,
        ));
    }

    public function transmettreReception(Request $request, DocumentProduitDirection $document)
    {
        $this->authorize('transmettreReception', $document);

        $transmis = $document->origine === DocumentProduitOrigine::AUTONOME
            ? $this->service->transmettreAutonomeReception($document, $request->user())
            : $this->service->transmettreReception($document, $request->user());

        return new DocumentProduitDirectionResource($this->chargerTracabilite($transmis, true));
    }

    public function recevoir(Request $request, DocumentProduitDirection $document)
    {
        $this->authorize('recevoir', $document);

        return new DocumentProduitDirectionResource($this->service->recevoir($document, $request->user()));
    }

    public function accepterReceptionAutonome(Request $request, DocumentProduitDirection $document)
    {
        $this->authorize('accepterAutonomeReception', $document);

        return new DocumentProduitDirectionResource($this->chargerTracabilite(
            $this->service->accepterAutonomeReception($document, $request->user()),
            true,
        ));
    }

    public function demanderCorrectionReceptionAutonome(
        DemanderCorrectionReceptionDocumentRequest $request,
        DocumentProduitDirection $document,
    ) {
        return new DocumentProduitDirectionResource($this->chargerTracabilite(
            $this->service->demanderCorrectionAutonomeReception(
                $document,
                $request->user(),
                $request->validated('motif'),
            ),
            true,
        ));
    }

    private function chargerTracabilite(DocumentProduitDirection $document, bool $avecHistorique): DocumentProduitDirection
    {
        if ($document->origine !== DocumentProduitOrigine::AUTONOME) {
            return $document;
        }

        $document->load([
            'creePar',
            'modifiePar',
            'directeur',
            'soumisPar',
            'correctionDemandeePar',
            'validePar',
            'transmisReceptionPar',
            'recuPar',
            'directionDestinationSouhaitee',
        ]);

        if ($avecHistorique) {
            $document->load([
                'auditLogs' => fn ($query) => $query
                    ->whereIn('action', self::ACTIONS_HISTORIQUE_AUTONOME)
                    ->with('utilisateur')
                    ->orderBy('created_at')
                    ->orderBy('id'),
            ]);
        }

        return $document;
    }

    private function chargerAutonomeSec1(DocumentProduitDirection $document): DocumentProduitDirection
    {
        $document->load([
            'courrier.directionOrigine',
            'courrier.transitions.auteur',
            'courrier.transitions.accuseReceptionPar',
            'direction',
            'directionDestinationSouhaitee',
            'directeur',
            'transmisReceptionPar',
            'recuPar',
            'auditLogs' => fn ($audits) => $audits
                ->whereIn('action', self::ACTIONS_HISTORIQUE_AUTONOME)
                ->with('utilisateur')
                ->orderBy('created_at')
                ->orderBy('id'),
        ]);

        return $document;
    }

    private function chargerPourDg(DocumentProduitDirection $document): DocumentProduitDirection
    {
        return $document->load([
            'courrier.directionOrigine',
            'courrier.directionDestination',
            'courrier.transitions.auteur',
            'courrier.dispatchs.direction',
            'courrier.dispatchs.decisionnaire',
            'direction',
            'directionDestinationSouhaitee',
            'directeur',
            'transmisReceptionPar',
            'recuPar',
            'scansOfficiels' => fn ($scans) => $scans->where('est_actif', true)->with('deposePar'),
            'auditLogs' => fn ($audits) => $audits
                ->whereIn('action', self::ACTIONS_HISTORIQUE_AUTONOME)
                ->with('utilisateur')
                ->orderBy('created_at')
                ->orderBy('id'),
        ]);
    }
}
