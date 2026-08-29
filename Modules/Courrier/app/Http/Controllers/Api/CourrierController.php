<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierClassification;
use Modules\Courrier\Http\Requests\EnregistrerCourrierRequest;
use Modules\Courrier\Http\Requests\InitierCourrierDgRequest;
use Modules\Courrier\Http\Requests\RendreAvisDgRequest;
use Modules\Courrier\Http\Requests\SoumettreProjetReponseRequest;
use Modules\Courrier\Http\Requests\StoreCourrierRequest;
use Modules\Courrier\Http\Requests\ValiderRelectureRequest;
use Modules\Courrier\Http\Resources\CourrierResource;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Services\CourrierCircuitService;

class CourrierController extends Controller
{
    public function __construct(private readonly CourrierCircuitService $circuit) {}

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

        if ($request->filled('periode_debut')) {
            $query->whereDate('created_at', '>=', $request->string('periode_debut'));
        }

        if ($request->filled('periode_fin')) {
            $query->whereDate('created_at', '<=', $request->string('periode_fin'));
        }

        if ($request->filled('recherche')) {
            $terme = '%'.$request->string('recherche').'%';
            $query->where(fn ($q) => $q->where('objet', 'ilike', $terme)
                ->orWhere('numero_accuse_reception', 'ilike', $terme)
                ->orWhere('candidat_nom', 'ilike', $terme)
                ->orWhere('expediteur_externe_nom', 'ilike', $terme));
        }

        return CourrierResource::collection($query->latest()->paginate(20));
    }

    public function show(Courrier $courrier)
    {
        $this->authorize('view', $courrier);

        return $this->ressource($courrier);
    }

    public function store(StoreCourrierRequest $request)
    {
        $donnees = $request->validated();

        $pieceJointe = $donnees['piece_jointe'] ?? null;
        unset($donnees['piece_jointe']);
        if ($pieceJointe) {
            $donnees['piece_jointe_chemin'] = $pieceJointe->store('courriers', 'local');
        }

        $courrier = $this->circuit->creer($request->user(), $donnees);

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
        ]));
    }
}
