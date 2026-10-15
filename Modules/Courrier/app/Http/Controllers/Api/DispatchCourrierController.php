<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Http\Requests\DeciderDispatchRequest;
use Modules\Courrier\Http\Requests\ExecuterDispatchRequest;
use Modules\Courrier\Http\Resources\CourrierResource;
use Modules\Courrier\Http\Resources\DispatchCourrierResource;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Services\DispatchCourrierService;
use Modules\Courrier\Services\DocumentProduitScanOfficielService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Support\DelegationResolver;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DispatchCourrierController extends Controller
{
    public function __construct(
        private readonly DispatchCourrierService $service,
        private readonly DocumentProduitScanOfficielService $scansOfficiels,
    ) {}

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
        $query = DispatchCourrier::query()->with([
            'courrier.directionOrigine',
            'courrier.directionDestination',
            'courrier.documentProduitDirection',
            'courrier.transitions.accuseReceptionPar',
            'direction',
            'decisionnaire',
            'executePar',
        ])->latest('decide_at');
        $this->exclureDispatchsSansDossierInvalides($query);
        $request->validate(['type' => ['nullable', 'in:direction,exterieur,classement'], 'statut' => ['nullable', 'in:en_attente,execute,annule']]);
        if ($request->filled('type')) {
            $query->where('type_destination', $request->string('type')->toString());
        }
        if ($request->filled('statut')) {
            $query->where('statut', $request->string('statut'));
        }

        return DispatchCourrierResource::collection($query->paginate(20));
    }

    public function show(Request $request, DispatchCourrier $dispatch)
    {
        $this->authorize('view', $dispatch);

        return new DispatchCourrierResource($dispatch->load([
            'courrier.directionOrigine',
            'courrier.directionDestination',
            'courrier.documentProduitDirection',
            'courrier.transitions.accuseReceptionPar',
            'direction',
            'decisionnaire',
            'executePar',
            'accuseReceptionPar',
        ]));
    }

    public function boiteDirection(Request $request)
    {
        abort_unless($request->user()->role === UserRole::SECRETARIAT_DIRECTION && $request->user()->direction_id !== null, 403);

        $query = DispatchCourrier::query()
            ->where('type_destination', DispatchTypeDestination::DIRECTION)
            ->where('statut', DispatchStatut::EXECUTE)
            ->where('direction_id', $request->user()->direction_id)
            ->with([
                'courrier.directionOrigine',
                'courrier.directionDestination',
                'courrier.documentProduitDirection',
                'courrier.transitions.accuseReceptionPar',
                'direction',
                'decisionnaire',
                'executePar',
                'accuseReceptionPar',
                'traitementDirection.directeur',
            ])
            ->latest('execute_at');
        $this->exclureDispatchsSansDossierInvalides($query);

        return DispatchCourrierResource::collection($query->paginate(20));
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

    public function consulterScanAutonome(Request $request, DispatchCourrier $dispatch): StreamedResponse
    {
        abort_unless($request->user()->can('consulterScanAutonomeSec2', $dispatch), 404);

        return $this->scansOfficiels->consulterAutonomeSec2($dispatch);
    }

    private function exclureDispatchsSansDossierInvalides(Builder $query): void
    {
        $query->where(function (Builder $dispatchs): void {
            $dispatchs->whereNotNull('dispatchs_courrier.dossier_id')
                ->orWhere(function (Builder $autonomes): void {
                    $autonomes
                        ->whereNull('dispatchs_courrier.dossier_id')
                        ->where('dispatchs_courrier.type_destination', DispatchTypeDestination::DIRECTION)
                        ->whereIn('dispatchs_courrier.decisionnaire_poste', [Poste::DG->value, Poste::DGA->value])
                        ->where('dispatchs_courrier.autorite_poste', Poste::DG->value)
                        ->whereIn('dispatchs_courrier.statut', [DispatchStatut::EN_ATTENTE->value, DispatchStatut::EXECUTE->value])
                        ->whereNotNull('dispatchs_courrier.decisionnaire_id')
                        ->whereNotNull('dispatchs_courrier.decide_at')
                        ->whereHas('courrier', function (Builder $courriers): void {
                            $courriers
                                ->whereNull('dossier_id')
                                ->whereColumn('courriers.direction_destination_id', 'dispatchs_courrier.direction_id')
                                ->whereColumn('courriers.avis_dg_rendu_par_id', 'dispatchs_courrier.decisionnaire_id')
                                ->whereNotNull('courriers.avis_dg_rendu_at')
                                ->where('courriers.avis_dg', AvisDg::FAVORABLE)
                                ->where(function (Builder $etats): void {
                                    $etats
                                        ->where(function (Builder $attente): void {
                                            $attente
                                                ->where('dispatchs_courrier.statut', DispatchStatut::EN_ATTENTE)
                                                ->where('courriers.statut', CourrierStatut::EN_DISPATCH);
                                        })
                                        ->orWhere(function (Builder $transmis): void {
                                            $transmis
                                                ->where('dispatchs_courrier.statut', DispatchStatut::EXECUTE)
                                                ->where('courriers.statut', CourrierStatut::DISPATCH_EXECUTE);
                                        });
                                })
                                ->whereNotNull('courriers.reference_documentaire')
                                ->whereNotNull('courriers.numero_enregistrement')
                                ->whereHas('documentProduitDirection', function (Builder $documents): void {
                                    DocumentProduitDirection::appliquerFiltreAutonome($documents);
                                    $documents
                                        ->where('statut', DocumentProduitStatut::ENTRE_CIRCUIT)
                                        ->whereNull('traitement_direction_id')
                                        ->whereNull('document_source_id')
                                        ->whereNotNull('scan_officiel_document_id')
                                        ->whereNotNull('transmis_reception_par_id')
                                        ->whereNotNull('transmis_reception_at')
                                        ->whereNotNull('recu_par_id')
                                        ->whereNotNull('recu_at')
                                        ->whereColumn('documents_produits_direction.direction_id', 'courriers.direction_origine_id')
                                        ->whereHas('scansOfficiels', fn (Builder $scans) => $scans
                                            ->whereColumn('document_numerise_id', 'documents_produits_direction.scan_officiel_document_id')
                                            ->where('est_actif', true)
                                            ->where('attestation_confirmee', true)
                                            ->whereNotNull('atteste_at'));
                                })
                                ->whereHas('transitions', fn (Builder $transitions) => $transitions
                                    ->where('nouveau_statut', 'en_attente_avis_dg')
                                    ->where('destinataire_poste', Poste::DG->value))
                                ->whereHas('transitions', fn (Builder $transitions) => $transitions
                                    ->where('nouveau_statut', 'en_dispatch')
                                    ->where('destinataire_poste', Poste::SECRETARIAT_2->value)
                                    ->whereColumn('changed_by_id', 'dispatchs_courrier.decisionnaire_id'));
                        });
                });
        });
    }
}
