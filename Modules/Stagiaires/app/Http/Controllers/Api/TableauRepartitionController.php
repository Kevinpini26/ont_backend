<?php

namespace Modules\Stagiaires\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Stagiaires\Enums\IssueProposee;
use Modules\Stagiaires\Http\Requests\AjouterLignesEnLotRequest;
use Modules\Stagiaires\Http\Requests\AjouterLigneTableauRequest;
use Modules\Stagiaires\Http\Requests\CreerTableauRepartitionRequest;
use Modules\Stagiaires\Http\Requests\RendreAvisTableauRequest;
use Modules\Stagiaires\Http\Resources\StagiaireResource;
use Modules\Stagiaires\Http\Resources\TableauRepartitionResource;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Models\TableauRepartition;
use Modules\Stagiaires\Models\TableauRepartitionLigne;
use Modules\Stagiaires\Services\TableauRepartitionCircuitService;

class TableauRepartitionController extends Controller
{
    public function __construct(private readonly TableauRepartitionCircuitService $circuit) {}

    private function ressource(TableauRepartition $tableau): TableauRepartitionResource
    {
        return new TableauRepartitionResource($tableau->load([
            'direction', 'redacteur', 'approuvePar', 'courrier',
            'lignes.stagiaire', 'lignes.directionAccueilProposee',
            'scellement.auteur',
        ]));
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', TableauRepartition::class);

        $query = TableauRepartition::query()->with(['direction', 'redacteur'])->latest('id');

        if ($request->filled('statut')) {
            $query->where('statut', $request->string('statut'));
        }

        return TableauRepartitionResource::collection($query->paginate(20));
    }

    public function show(TableauRepartition $tableau)
    {
        $this->authorize('view', $tableau);

        return $this->ressource($tableau);
    }

    public function store(CreerTableauRepartitionRequest $request)
    {
        $data = $request->validated();

        $tableau = $this->circuit->creer($request->user(), $data['periode_debut'], $data['periode_fin']);

        return $this->ressource($tableau);
    }

    /**
     * Lot A : les dossiers proposables à un tableau — jamais toute la
     * base des demandes de stage, seulement ceux dont le courrier a été
     * imputé à la DFP (voir TableauRepartitionCircuitService::dossiersEligibles()).
     */
    public function dossiersEligibles(Request $request)
    {
        $this->authorize('creer', TableauRepartition::class);

        return StagiaireResource::collection($this->circuit->dossiersEligibles());
    }

    /**
     * Lot B : liste paramétrable (config('stagiaires.motifs_non_retenu')),
     * jamais figée côté frontend — un changement de config doit se
     * refléter sans redéploiement.
     */
    public function motifsNonRetenu()
    {
        return response()->json(['data' => config('stagiaires.motifs_non_retenu')]);
    }

    public function ajouterLigne(AjouterLigneTableauRequest $request, TableauRepartition $tableau)
    {
        $data = $request->validated();

        $stagiaire = Stagiaire::query()->findOrFail($data['stagiaire_id']);

        $this->circuit->ajouterLigne(
            $tableau,
            $stagiaire,
            $request->user(),
            $data['direction_accueil_proposee_id'],
            $data['date_debut_proposee'],
            $data['date_fin_proposee'],
            $data['encadrant_pressenti'],
            IssueProposee::from($data['issue_proposee'] ?? IssueProposee::RETENU->value),
            $data['motif_non_retenu'] ?? null,
            $data['motif_non_retenu_libre'] ?? null,
        );

        return $this->ressource($tableau);
    }

    /**
     * Lot A : "un seul geste enregistre toutes les lignes cochées" — même
     * validation, mêmes avertissements non bloquants que l'ajout unitaire,
     * mais en une transaction (voir
     * TableauRepartitionCircuitService::ajouterLignesEnLot()).
     */
    public function ajouterLignesEnLot(AjouterLignesEnLotRequest $request, TableauRepartition $tableau)
    {
        $this->circuit->ajouterLignesEnLot($tableau, $request->user(), $request->validated('lignes'));

        return $this->ressource($tableau);
    }

    public function retirerLigne(Request $request, TableauRepartition $tableau, TableauRepartitionLigne $ligne)
    {
        $this->authorize('modifier', $tableau);

        $this->circuit->retirerLigne($tableau, $ligne);

        return $this->ressource($tableau);
    }

    public function soumettre(Request $request, TableauRepartition $tableau)
    {
        $this->authorize('soumettre', $tableau);

        $tableau = $this->circuit->soumettre($tableau, $request->user());

        return $this->ressource($tableau);
    }

    public function representerDg(Request $request, TableauRepartition $tableau)
    {
        $this->authorize('representerDg', $tableau);

        $tableau = $this->circuit->representerDg($tableau, $request->user());

        return $this->ressource($tableau);
    }

    public function rendreAvis(RendreAvisTableauRequest $request, TableauRepartition $tableau)
    {
        $data = $request->validated();

        $tableau = $this->circuit->rendreAvis($tableau, $request->user(), $data['approuve'], $data['observations'] ?? null);

        return $this->ressource($tableau);
    }

    public function telechargerPdf(TableauRepartition $tableau)
    {
        $this->authorize('view', $tableau);

        if ($tableau->pdf_chemin === null) {
            abort(404, "Ce tableau n'a pas encore été approuvé.");
        }

        return Storage::disk('local')->download($tableau->pdf_chemin, "tableau-repartition-{$tableau->id}.pdf");
    }
}
