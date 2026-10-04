<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Contracts\FeuilleCouvertureGenerator;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierClassification;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DegreUrgence;
use Modules\Courrier\Enums\NiveauConfidentialite;
use Modules\Courrier\Enums\NumerisationStatut;
use Modules\Courrier\Http\Requests\EnregistrerCourrierRequest;
use Modules\Courrier\Http\Requests\EnregistrerRemiseRequest;
use Modules\Courrier\Http\Requests\EnvoyerCourrierRequest;
use Modules\Courrier\Http\Requests\ImporterLotNumerisationRequest;
use Modules\Courrier\Http\Requests\ImputerCourrierRequest;
use Modules\Courrier\Http\Requests\InitierCourrierDgRequest;
use Modules\Courrier\Http\Requests\InitierCourrierSortantRequest;
use Modules\Courrier\Http\Requests\InitierReponseSortanteRequest;
use Modules\Courrier\Http\Requests\RendreAvisDgRequest;
use Modules\Courrier\Http\Requests\RenvoyerAuTriRequest;
use Modules\Courrier\Http\Requests\RenvoyerPourCorrectionRequest;
use Modules\Courrier\Http\Requests\RequalifierUrgenceRequest;
use Modules\Courrier\Http\Requests\SortirOriginalRequest;
use Modules\Courrier\Http\Requests\SoumettreProjetReponseRequest;
use Modules\Courrier\Http\Requests\StoreCourrierRequest;
use Modules\Courrier\Http\Requests\TransmettreAvisDgRequest;
use Modules\Courrier\Http\Requests\TransmettreCourrierRequest;
use Modules\Courrier\Http\Requests\ValiderRelectureRequest;
use Modules\Courrier\Http\Resources\CourrierResource;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierPieceJointe;
use Modules\Courrier\Services\CourrierCircuitService;
use Modules\Courrier\Services\DispatchCourrierService;
use Modules\Courrier\Services\PdfOfficielIntegrity;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Kernel\Contracts\QrCodeService;
use Modules\Kernel\Enums\SourceDocumentNumerise;
use Modules\Kernel\Models\DocumentNumerise;
use Modules\Kernel\Models\JetonCaptureNumerisation;
use Modules\Kernel\Support\CsvExporter;
use Modules\Kernel\Support\EmpreinteFichier;
use Modules\Kernel\Support\GestionnaireDocumentNumerise;

class CourrierController extends Controller
{
    public function __construct(
        private readonly CourrierCircuitService $circuit,
        private readonly DispatchCourrierService $dispatchs,
        private readonly AuditLogger $audit,
        private readonly PdfGenerationService $pdf,
        private readonly QrCodeService $qrCode,
        private readonly NotificationService $notifications,
        private readonly FeuilleCouvertureGenerator $feuilleCouvertureGenerator,
        private readonly GestionnaireDocumentNumerise $gestionnaire,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Courrier::class);

        $query = Courrier::query()->with(['directionOrigine', 'directionDestination', 'transitions']);
        if ($request->filled('sens')) {
            $request->validate(['sens' => ['in:entrant,sortant']]);
            $query->where('sens', $request->string('sens')->toString());
        }

        if ($request->filled('statut')) {
            $query->where('statut', $request->string('statut'));
        }

        if ($request->has('necessite_avis_dg')) {
            $query->where('necessite_avis_dg', $request->boolean('necessite_avis_dg'));
        }

        if ($request->filled('direction_origine_id')) {
            $query->where('direction_origine_id', $request->integer('direction_origine_id'));
        }

        if ($request->filled('direction_destination_id')) {
            $query->where('direction_destination_id', $request->integer('direction_destination_id'));
        }

        if ($request->filled('cote_classement')) {
            $query->where('cote_classement', $request->string('cote_classement'));
        }

        if ($request->filled('emplacement_physique')) {
            $query->where('emplacement_physique', 'ilike', '%'.$request->string('emplacement_physique').'%');
        }

        if ($request->filled('periode_debut')) {
            $query->whereDate('created_at', '>=', $request->string('periode_debut'));
        }

        if ($request->filled('periode_fin')) {
            $query->whereDate('created_at', '<=', $request->string('periode_fin'));
        }

        if ($request->filled('recherche')) {
            $query->recherchePleinTexte($request->string('recherche')->toString());

            return CourrierResource::collection($query->paginate(20));
        }

        // tri=urgence : très urgent en tête, puis urgent, puis normal, puis
        // non encore trié en dernier — explicite (paramètre, pas le
        // comportement par défaut) pour ne rien changer silencieusement à
        // l'ordre existant des appelants qui ne le demandent pas.
        if ($request->string('tri')->toString() === 'urgence') {
            $query->orderByRaw(<<<'SQL'
                case degre_urgence
                    when 'tres_urgent' then 0
                    when 'urgent' then 1
                    when 'normal' then 2
                    else 3
                end asc
                SQL)->latest();

            return CourrierResource::collection($query->paginate(20));
        }

        return CourrierResource::collection($query->latest()->paginate(20));
    }

    public function export(Request $request)
    {
        $this->authorize('viewAny', Courrier::class);

        $query = Courrier::query()->with(['directionOrigine', 'directionDestination']);

        if ($request->filled('statut')) {
            $query->where('statut', $request->string('statut'));
        }

        if ($request->filled('direction_origine_id')) {
            $query->where('direction_origine_id', $request->integer('direction_origine_id'));
        }

        if ($request->filled('direction_destination_id')) {
            $query->where('direction_destination_id', $request->integer('direction_destination_id'));
        }

        if ($request->filled('periode_debut')) {
            $query->whereDate('created_at', '>=', $request->string('periode_debut'));
        }

        if ($request->filled('periode_fin')) {
            $query->whereDate('created_at', '<=', $request->string('periode_fin'));
        }

        $lignes = $query->orderBy('id')->cursor()->map(fn (Courrier $c) => [
            $c->numero_enregistrement,
            $c->numero_accuse_reception,
            $c->objet,
            $c->type->label(),
            $c->statut->label(),
            $c->directionOrigine?->nom,
            $c->directionDestination?->nom,
            $c->degre_urgence?->label(),
            $c->date_courrier?->toDateString(),
            $c->created_at->toDateString(),
        ]);

        return CsvExporter::streamer('courriers.csv', [
            "Numéro d'enregistrement", "Numéro d'accusé de réception", 'Objet', 'Type', 'Statut',
            'Direction origine', 'Direction destination', 'Urgence', 'Date du courrier', 'Date de réception',
        ], $lignes);
    }

    public function show(Request $request, Courrier $courrier)
    {
        $this->authorize('view', $courrier);

        if ($courrier->niveau_confidentialite !== NiveauConfidentialite::ORDINAIRE) {
            $this->audit->enregistrer('courrier.acces_confidentiel', $courrier, $request->user(), [
                'niveau_confidentialite' => $courrier->niveau_confidentialite->value,
            ]);
        }

        return $this->ressource($courrier);
    }

    public function store(StoreCourrierRequest $request)
    {
        $donnees = $request->validated();

        $pieceJointe = $donnees['piece_jointe'] ?? null;
        $piecesSupplementaires = $donnees['pieces_jointes'] ?? [];
        $numerisationImpossible = (bool) ($donnees['numerisation_impossible'] ?? false);
        unset($donnees['piece_jointe'], $donnees['pieces_jointes'], $donnees['numerisation_impossible']);

        $cheminPiecePrincipale = null;
        if ($pieceJointe) {
            $cheminPiecePrincipale = $pieceJointe->store('courriers', 'local');
            $donnees['piece_jointe_chemin'] = $cheminPiecePrincipale;
        }

        $courrier = $this->circuit->creer($request->user(), $donnees, $numerisationImpossible);

        // Alimente courrier_pieces_jointes en plus de piece_jointe_chemin
        // (voir Courrier::piecesJointes()) : la pièce principale devient la
        // première annexe (même fichier déjà stocké ci-dessus, pas une
        // deuxième copie sur disque), suivie des annexes supplémentaires.
        if ($pieceJointe) {
            CourrierPieceJointe::query()->create([
                'courrier_id' => $courrier->id,
                'libelle' => 'Pièce jointe',
                'chemin' => $cheminPiecePrincipale,
                'type_mime' => $pieceJointe->getMimeType(),
                'taille_octets' => $pieceJointe->getSize(),
                'ordre' => 1,
                'uploaded_by_id' => $request->user()?->id,
            ]);

            // Première version du document numérisé — voir
            // docs/numerisation-courrier.md : le tout premier scan pris à la
            // Réception (aujourd'hui encore un dépôt direct, demain la
            // capture mobile du lot 1) devient la version 1, jamais un
            // simple fichier isolé. TELEVERSEMENT ici : ce point d'entrée
            // reste, pour l'instant, un dépôt direct — la capture mobile
            // (source TELEPHONE) créera ses propres versions via son propre
            // point d'entrée au lot 1.
            $courrier->numerisations()->create([
                'version' => 1,
                'etape_circuit' => $courrier->statut->value,
                'chemin' => $cheminPiecePrincipale,
                'poids_octets' => $pieceJointe->getSize(),
                'source' => SourceDocumentNumerise::TELEVERSEMENT,
                'sha256' => EmpreinteFichier::pourFichierStocke($cheminPiecePrincipale),
                'capture_par_id' => $request->user()?->id,
            ]);
        }
        foreach ($piecesSupplementaires as $index => $piece) {
            CourrierPieceJointe::query()->create([
                'courrier_id' => $courrier->id,
                'libelle' => 'Annexe '.($index + 1),
                'chemin' => $piece->store('courriers', 'local'),
                'type_mime' => $piece->getMimeType(),
                'taille_octets' => $piece->getSize(),
                'ordre' => $index + 2,
                'uploaded_by_id' => $request->user()?->id,
            ]);
        }

        return $this->ressource($courrier)->response()->setStatusCode(201);
    }

    public function initierParDg(InitierCourrierDgRequest $request)
    {
        $donnees = $request->validated();

        $pieceJointe = $donnees['piece_jointe'] ?? null;
        unset($donnees['piece_jointe']);
        if ($pieceJointe) {
            $donnees['piece_jointe_chemin'] = $pieceJointe->store('courriers', 'local');
        }

        $courrier = $this->circuit->initierParDg($request->user(), $donnees);

        return $this->ressource($courrier)->response()->setStatusCode(201);
    }

    public function validerAvantDiffusion(Request $request, Courrier $courrier)
    {
        $this->authorize('validerAvantDiffusion', $courrier);

        return $this->ressource($this->circuit->validerAvantDiffusion($courrier, $request->user()));
    }

    public function accuserReception(Request $request, Courrier $courrier)
    {
        $this->authorize('accuserReception', $courrier);

        return $this->ressource($this->circuit->accuserReception($courrier, $request->user()));
    }

    /**
     * Remplace l'ensemble des imputations du courrier (pas un ajout
     * incrémental) : plus simple et plus sûr à raisonner qu'un diff quand
     * le volume par courrier reste faible (quelques directions au plus).
     */
    public function imputer(ImputerCourrierRequest $request, Courrier $courrier)
    {
        throw ValidationException::withMessages(['imputations' => 'Les nouvelles orientations doivent être décidées par le dispatch institutionnel. Les imputations historiques restent consultables sans modification.']);
    }

    public function transmettreTri(TransmettreCourrierRequest $request, Courrier $courrier)
    {
        return $this->ressource($this->circuit->transmettreTri($courrier, $request->user(), $request->validated('instruction')));
    }

    public function transmettreAvisDg(TransmettreAvisDgRequest $request, Courrier $courrier)
    {
        return $this->ressource($this->circuit->transmettreEnAttenteAvisDg(
            $courrier,
            $request->user(),
            DegreUrgence::from($request->validated('degre_urgence')),
            $request->validated('instruction'),
        ));
    }

    public function transmettreDepuisClasseur(TransmettreCourrierRequest $request, Courrier $courrier)
    {
        return $this->ressource($this->circuit->transmettreDepuisClasseur($courrier, $request->user(), $request->validated('instruction')));
    }

    public function requalifierUrgence(RequalifierUrgenceRequest $request, Courrier $courrier)
    {
        return $this->ressource($this->circuit->requalifierUrgence(
            $courrier,
            $request->user(),
            DegreUrgence::from($request->validated('degre_urgence')),
        ));
    }

    public function transmettreSec1(TransmettreCourrierRequest $request, Courrier $courrier)
    {
        return $this->ressource($this->circuit->transmettreRetourVersSec1($courrier, $request->user(), $request->validated('instruction')));
    }

    public function dispatcherVersDirection(TransmettreCourrierRequest $request, Courrier $courrier)
    {
        return $this->ressource($this->dispatchs->executerDepuisImputations($courrier, $request->user()));
    }

    public function rendreAvis(RendreAvisDgRequest $request, Courrier $courrier)
    {
        $data = $request->validated();

        $courrier = $this->circuit->rendreAvisDg(
            $courrier,
            $request->user(),
            AvisDg::from($data['avis_dg']),
            $data['avis_dg_commentaire'] ?? null,
        );

        return $this->ressource($courrier);
    }

    public function renvoyerAuTri(RenvoyerAuTriRequest $request, Courrier $courrier)
    {
        return $this->ressource($this->circuit->renvoyerAuTri(
            $courrier,
            $request->user(),
            $request->validated('motif'),
        ));
    }

    public function soumettreProjetReponse(SoumettreProjetReponseRequest $request, Courrier $courrier)
    {
        $data = $request->validated();

        $courrier = $this->circuit->soumettreProjetReponse(
            $courrier,
            $request->user(),
            $data['projet_reponse_contenu'],
            $data['relecteur_id'],
        );

        return $this->ressource($courrier);
    }

    public function validerRelecture(ValiderRelectureRequest $request, Courrier $courrier)
    {
        $courrier = $this->circuit->validerRelecture(
            $courrier,
            $request->user(),
            $request->validated()['relecture_commentaire'] ?? null,
        );

        return $this->ressource($courrier);
    }

    public function renvoyerPourCorrection(RenvoyerPourCorrectionRequest $request, Courrier $courrier)
    {
        $courrier = $this->circuit->renvoyerPourCorrection(
            $courrier,
            $request->user(),
            $request->validated('observation'),
        );

        return $this->ressource($courrier);
    }

    public function signer(Request $request, Courrier $courrier)
    {
        $this->authorize('signer', $courrier);

        return $this->ressource($this->circuit->signer($courrier, $request->user()));
    }

    public function validerPourSignature(Request $request, Courrier $courrier)
    {
        $this->authorize('validerPourSignature', $courrier);

        return $this->ressource($this->circuit->validerPourSignature($courrier, $request->user()));
    }

    public function telechargerPdfASigner(Courrier $courrier)
    {
        $this->authorize('view', $courrier);
        $this->authorize('telechargerPdfASigner', $courrier);

        app(PdfOfficielIntegrity::class)->verifierFichier(
            $courrier->pdf_a_signer_chemin,
            $courrier->pdf_a_signer_sha256,
            'pdf_a_signer',
        );

        return Storage::disk('local')->download(
            $courrier->pdf_a_signer_chemin,
            "reponse-{$courrier->numero_depart}-a-signer.pdf",
        );
    }

    public function televerserScanSigne(Request $request, Courrier $courrier)
    {
        $this->authorize('televerserScanSigne', $courrier);
        $validated = $request->validate([
            'scan' => [
                'required',
                'file',
                'mimetypes:application/pdf',
                'max:'.(int) config('courrier.signature_physique.taille_max_scan_ko', 10240),
            ],
        ]);

        return $this->ressource($this->circuit->finaliserSignaturePhysique(
            $courrier,
            $request->user(),
            $validated['scan'],
        ));
    }

    /**
     * Impression généralisée : contrairement à telechargerPdf() (le PDF
     * définitif, seulement une fois le courrier signé), cette fiche est
     * générée à la volée depuis l'état courant du dossier, à n'importe
     * quelle étape du circuit — utile en pratique de terrain où
     * l'impression papier reste le principal support de suivi.
     */
    /**
     * Génère le jeton de capture mobile (15 min, usage unique) et son QR
     * code — voir docs/numerisation-courrier.md et JetonCaptureNumerisation.
     * Même garde que la fiche imprimable : quiconque voit le courrier peut
     * demander à le faire numériser, sans restreindre à un seul poste.
     */
    public function genererJetonCapture(Request $request, Courrier $courrier)
    {
        $this->authorize('view', $courrier);

        $jeton = JetonCaptureNumerisation::genererPour($courrier, $request->user());
        $url = rtrim(config('app.frontend_url'), '/')."/capture/{$jeton->token}";

        if ($request->filled('numero_sms')) {
            $this->notifications->notifierParSms(
                $request->string('numero_sms')->toString(),
                "ONT : lien de capture pour numériser le courrier {$courrier->numero_accuse_reception} (valable 15 min) : {$url}",
            );
        }

        return response()->json([
            'token' => $jeton->token,
            'url' => $url,
            'expire_at' => $jeton->expire_at,
            'qr_code_data_uri' => $this->qrCode->genererSvgDataUri($url),
        ]);
    }

    /**
     * Feuille de couverture unitaire — à poser sur la liasse avant
     * numérisation (voir docs/numerisation-courrier.md).
     */
    public function feuilleCouverture(Request $request, Courrier $courrier)
    {
        $this->authorize('view', $courrier);

        $pdf = $this->feuilleCouvertureGenerator->generer($courrier, $request->integer('nombre_pages_attendues') ?: null);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"feuille-couverture-{$courrier->numero_accuse_reception}.pdf\"",
        ]);
    }

    /**
     * Impression groupée pour toute une file : une feuille par page, dans
     * un seul PDF — permet de numériser vingt courriers d'affilée sans
     * rien ressaisir entre deux.
     */
    public function feuilleCouvertureLot(Request $request)
    {
        $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']]);

        $courriers = Courrier::query()->whereIn('id', $request->input('ids'))->with('directionOrigine')->get();
        foreach ($courriers as $courrier) {
            $this->authorize('view', $courrier);
        }

        $pdf = $this->feuilleCouvertureGenerator->genererLot($courriers);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="feuilles-couverture.pdf"',
        ]);
    }

    /**
     * Import par lot depuis une clé USB (Lot 3a) : le navigateur a déjà lu
     * le code-barres de chaque feuille de couverture et découpé le PDF
     * multi-courriers en segments — voir docs/numerisation-courrier.md.
     * Un seul segment par appel, pour qu'un échec n'annule jamais le reste
     * du lot.
     */
    public function importerLot(ImporterLotNumerisationRequest $request)
    {
        $courrier = Courrier::query()->where('numero_accuse_reception', $request->string('numero_accuse_reception'))->firstOrFail();
        $this->authorize('view', $courrier);

        $chemin = $request->file('fichier')->store('numerisations', 'local');

        $document = $this->gestionnaire->enregistrerVersion(
            $courrier,
            $chemin,
            SourceDocumentNumerise::COPIEUR,
            $request->user(),
        );

        $courrier->update(['numerisation_statut' => NumerisationStatut::NUMERISE]);

        return response()->json([
            'message' => 'Segment importé avec succès.',
            'document' => ['version' => $document->version, 'qualite' => $document->qualite?->value],
        ], 201);
    }

    /**
     * Sortie/retour de l'original physique — voir
     * docs/numerisation-courrier.md (Lot 5).
     */
    public function sortirOriginal(SortirOriginalRequest $request, Courrier $courrier)
    {
        $emprunt = $this->circuit->sortirOriginal($courrier, $request->user(), $request->string('motif')->toString());

        return response()->json(['data' => [
            'id' => $emprunt->id,
            'emprunte_par' => $emprunt->empruntePar->name,
            'emprunte_le' => $emprunt->emprunte_le,
            'motif' => $emprunt->motif,
        ]], 201);
    }

    public function restituerOriginal(Request $request, Courrier $courrier)
    {
        $this->authorize('view', $courrier);

        $emprunt = $this->circuit->restituerOriginal($courrier, $request->user());

        return response()->json(['data' => [
            'id' => $emprunt->id,
            'restitue_par' => $emprunt->restituePar->name,
            'restitue_le' => $emprunt->restitue_le,
        ]]);
    }

    public function imprimer(Courrier $courrier)
    {
        $this->authorize('view', $courrier);

        $pdf = $this->pdf->genererDepuisVue('courrier::fiche-imprimable', [
            'courrier' => $courrier->load(['directionOrigine', 'directionDestination', 'transitions.auteur', 'transitions.destinataireUser', 'transitions.accuseReceptionPar']),
            'qr_verification_data_uri' => $this->qrCode->genererSvgDataUri(
                rtrim(config('app.frontend_url'), '/')."/verification-dossier?numero={$courrier->numero_accuse_reception}",
                120,
            ),
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"fiche-{$courrier->numero_accuse_reception}.pdf\"",
        ]);
    }

    public function telechargerPdf(Courrier $courrier)
    {
        $this->authorize('view', $courrier);

        abort_unless($courrier->pdf_chemin, 404);

        if ($courrier->signe_at !== null || $courrier->signataire_id !== null || filled($courrier->pdf_sha256)
            || in_array($courrier->statut, [CourrierStatut::SIGNE, CourrierStatut::ENVOYE], true)) {
            app(PdfOfficielIntegrity::class)->verifier($courrier);
        }

        return Storage::disk('local')->download($courrier->pdf_chemin, "courrier-{$courrier->numero_accuse_reception}.pdf");
    }

    public function telechargerLettreStage(Courrier $courrier)
    {
        $this->authorize('view', $courrier);

        abort_unless($courrier->lettre_stage_chemin, 404);

        $extension = pathinfo($courrier->lettre_stage_chemin, PATHINFO_EXTENSION);

        return Storage::disk('local')->download(
            $courrier->lettre_stage_chemin,
            "lettre-stage-{$courrier->numero_accuse_reception}.{$extension}"
        );
    }

    /**
     * Pièces du stage professionnel (CV, diplôme d'État, dernier diplôme) :
     * même besoin que telechargerLettreStage() ci-dessus pour les
     * relecteurs du circuit courrier (Protocole/DGA/DG) avant de rendre
     * leur avis, mais généralisé pour éviter 3 méthodes quasi identiques.
     */
    public function telechargerPieceCandidat(Courrier $courrier, string $piece)
    {
        $this->authorize('view', $courrier);

        $colonne = match ($piece) {
            'cv' => 'cv_chemin',
            'diplome-etat' => 'diplome_etat_chemin',
            'dernier-diplome' => 'dernier_diplome_chemin',
            'lettre-demande' => 'lettre_demande_chemin',
            default => abort(404),
        };

        $chemin = $courrier->{$colonne};

        abort_unless($chemin, 404);

        $extension = pathinfo($chemin, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($chemin, "{$piece}-{$courrier->numero_accuse_reception}.{$extension}");
    }

    public function telechargerPieceJointe(Courrier $courrier)
    {
        $this->authorize('view', $courrier);

        abort_unless($courrier->piece_jointe_chemin, 404);

        $extension = pathinfo($courrier->piece_jointe_chemin, PATHINFO_EXTENSION);

        return Storage::disk('local')->download(
            $courrier->piece_jointe_chemin,
            "piece-jointe-{$courrier->numero_accuse_reception}.{$extension}"
        );
    }

    /**
     * Téléchargement d'une annexe précise (voir courrier_pieces_jointes) —
     * distinct de telechargerPieceJointe() ci-dessus, qui ne sait servir
     * que la pièce jointe historique unique.
     */
    public function telechargerPiece(Courrier $courrier, CourrierPieceJointe $piece)
    {
        $this->authorize('view', $courrier);

        abort_unless($piece->courrier_id === $courrier->id, 404);

        $extension = pathinfo($piece->chemin, PATHINFO_EXTENSION);

        return Storage::disk('local')->download(
            $piece->chemin,
            "{$piece->libelle}-{$courrier->numero_accuse_reception}.{$extension}"
        );
    }

    /**
     * Consultation d'une version précise du document numérisé (voir
     * docs/numerisation-courrier.md, Lot 2) — jamais la dernière version
     * implicitement : chaque version reste consultable séparément (le
     * papier continue de vivre après son arrivée : annotation DG, cachet
     * Protocole...).
     */
    public function telechargerNumerisation(Courrier $courrier, DocumentNumerise $document)
    {
        $this->authorize('view', $courrier);

        abort_unless(
            $document->numerisable_type === $courrier->getMorphClass() && $document->numerisable_id === $courrier->id,
            404,
        );

        return Storage::disk('local')->download(
            $document->chemin,
            "numerisation-{$courrier->numero_accuse_reception}-v{$document->version}.pdf"
        );
    }

    public function initierReponse(InitierReponseSortanteRequest $request, Courrier $courrier)
    {
        $reponse = $this->circuit->initierReponseSortante($request->user(), $courrier, $request->validated());

        return $this->ressource($reponse)->response()->setStatusCode(201);
    }

    /**
     * Courrier sortant proactif (pas une réponse à un courrier précis) —
     * une direction écrit de sa propre initiative à un partenaire externe.
     */
    public function initierSortant(InitierCourrierSortantRequest $request)
    {
        $courrier = $this->circuit->initierCourrierSortant($request->user(), $request->validated());

        return $this->ressource($courrier)->response()->setStatusCode(201);
    }

    public function envoyer(EnvoyerCourrierRequest $request, Courrier $courrier)
    {
        return $this->ressource($this->circuit->envoyer($courrier, $request->user(), $request->validated()));
    }

    public function enregistrerRemise(EnregistrerRemiseRequest $request, Courrier $courrier)
    {
        $donnees = $request->validated();
        $decharge = $donnees['decharge_remise'] ?? null;
        unset($donnees['decharge_remise']);
        if ($decharge) {
            $donnees['decharge_remise_chemin'] = $decharge->store('courriers-decharges', 'local');
        }

        return $this->ressource($this->circuit->enregistrerRemise($courrier, $request->user(), $donnees));
    }

    public function enregistrer(EnregistrerCourrierRequest $request, Courrier $courrier)
    {
        $data = $request->validated();

        $courrier = $this->circuit->enregistrer(
            $courrier,
            $request->user(),
            CourrierClassification::from($data['classification']),
            $data['note_technique'] ?? null,
            $data['accuse_reception_partenaire'] ?? null,
            $data['emplacement_physique'] ?? null,
        );

        return $this->ressource($courrier);
    }

    /**
     * Toujours recharger les relations d'affichage avant de sérialiser :
     * chaque action de circuit renvoie le Courrier tel que modifié en
     * mémoire par le service, sans ses relations (non chargées à cet
     * instant) — sans ce rechargement, direction_origine/destination,
     * relecteur, signataire et créateur disparaîtraient de la réponse
     * juste après l'action, jusqu'au prochain rechargement de la page.
     */
    private function ressource(Courrier $courrier): CourrierResource
    {
        return new CourrierResource($courrier->load([
            'directionOrigine', 'directionDestination', 'relecteur', 'valideSignaturePar', 'signataire', 'createur', 'avisDgRenduPar', 'urgenceTrieePar', 'projetRenvoyePar',
            'transitions.auteur', 'transitions.destinataireUser', 'transitions.accuseReceptionPar',
            'missionsDocumentaires.demandeur', 'missionsDocumentaires.assistant', 'missionsDocumentaires.annuleePar', 'missionsDocumentaires.projetCourrier',
            'dispatchs.direction', 'dispatchs.decisionnaire', 'dispatchs.executePar', 'dispatchs.accuseReceptionPar',
            'dispatchs.traitementDirection.directeur',
            'documentProduitDirection.documentSource', 'documentProduitDirection.traitement',
            'classement.classePar', 'classement.archivePar',
            'imputations.direction', 'imputations.imputeePar',
            'piecesJointes', 'reponses', 'courrierOrigine', 'numerisations.capturePar',
        ]));
    }
}
