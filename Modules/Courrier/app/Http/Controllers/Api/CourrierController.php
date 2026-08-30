<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierClassification;
use Modules\Courrier\Enums\NiveauConfidentialite;
use Modules\Courrier\Http\Requests\EnregistrerCourrierRequest;
use Modules\Courrier\Http\Requests\EnregistrerRemiseRequest;
use Modules\Courrier\Http\Requests\EnvoyerCourrierRequest;
use Modules\Courrier\Http\Requests\ImputerCourrierRequest;
use Modules\Courrier\Http\Requests\InitierCourrierDgRequest;
use Modules\Courrier\Http\Requests\InitierCourrierSortantRequest;
use Modules\Courrier\Http\Requests\InitierReponseSortanteRequest;
use Modules\Courrier\Http\Requests\RendreAvisDgRequest;
use Modules\Courrier\Http\Requests\SoumettreProjetReponseRequest;
use Modules\Courrier\Http\Requests\StoreCourrierRequest;
use Modules\Courrier\Http\Requests\ValiderRelectureRequest;
use Modules\Courrier\Http\Resources\CourrierResource;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierPieceJointe;
use Modules\Courrier\Services\CourrierCircuitService;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Support\CsvExporter;

class CourrierController extends Controller
{
    public function __construct(
        private readonly CourrierCircuitService $circuit,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $query = Courrier::query()->with(['directionOrigine', 'directionDestination', 'transitions']);

        if ($request->filled('statut')) {
            $query->where('statut', $request->string('statut'));
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

        return CourrierResource::collection($query->latest()->paginate(20));
    }

    public function export(Request $request)
    {
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
        unset($donnees['piece_jointe'], $donnees['pieces_jointes']);

        $cheminPiecePrincipale = null;
        if ($pieceJointe) {
            $cheminPiecePrincipale = $pieceJointe->store('courriers', 'local');
            $donnees['piece_jointe_chemin'] = $cheminPiecePrincipale;
        }

        $courrier = $this->circuit->creer($request->user(), $donnees);

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
        DB::transaction(function () use ($request, $courrier) {
            $courrier->imputations()->delete();

            foreach ($request->validated('imputations') as $imputation) {
                $courrier->imputations()->create([
                    'direction_id' => $imputation['direction_id'],
                    'mention' => $imputation['mention'],
                    'est_principale' => $imputation['est_principale'],
                    'imputee_par_id' => $request->user()->id,
                ]);
            }
        });

        return $this->ressource($courrier->fresh());
    }

    public function transmettreProtocole(Request $request, Courrier $courrier)
    {
        $this->authorize('transmettre', $courrier);

        return $this->ressource($this->circuit->transmettreAuProtocole($courrier, $request->user()));
    }

    public function transmettreAvisDg(Request $request, Courrier $courrier)
    {
        $this->authorize('transmettre', $courrier);

        return $this->ressource($this->circuit->transmettreEnAttenteAvisDg($courrier, $request->user()));
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

    public function signer(Request $request, Courrier $courrier)
    {
        $this->authorize('signer', $courrier);

        return $this->ressource($this->circuit->signer($courrier, $request->user()));
    }

    public function telechargerPdf(Courrier $courrier)
    {
        $this->authorize('view', $courrier);

        abort_unless($courrier->pdf_chemin, 404);

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
            'directionOrigine', 'directionDestination', 'relecteur', 'signataire', 'createur', 'avisDgRenduPar',
            'transitions.auteur', 'transitions.destinataireUser', 'transitions.accuseReceptionPar',
            'imputations.direction', 'imputations.imputeePar',
            'piecesJointes', 'reponses', 'courrierOrigine',
        ]));
    }
}
