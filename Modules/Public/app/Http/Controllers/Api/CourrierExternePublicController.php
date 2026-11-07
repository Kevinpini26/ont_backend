<?php

namespace Modules\Public\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Courrier\Services\CourrierCircuitService;
use Modules\Public\Http\Requests\DeposerCourrierExterneRequest;

class CourrierExternePublicController extends Controller
{
    public function __construct(private readonly CourrierCircuitService $circuit) {}

    /**
     * Dépôt en ligne d'un courrier par un partenaire externe (sans compte).
     * Endpoint public : aucune authentification requise. Génère le numéro
     * d'accusé de réception et déclenche l'e-mail de confirmation à
     * l'expéditeur (voir CourrierCircuitService::creerCourrierExterneDepuisPublic).
     */
    public function store(DeposerCourrierExterneRequest $request): JsonResponse
    {
        $donnees = $request->validated();
        $pieceJointe = $donnees['piece_jointe'];
        unset($donnees['piece_jointe']);

        $courrier = $this->circuit->creerCourrierExterneDepuisPublic([
            ...$donnees,
            'piece_jointe_chemin' => $pieceJointe->store('courriers-externes', 'local'),
        ]);

        return response()->json([
            // Lot 6 : un seul numéro, le numéro d'arrivée ; la clé historique est gardée pour les anciens clients.
            'numero' => $courrier->numero_enregistrement,
            'numero_accuse_reception' => $courrier->numero_enregistrement,
        ], 201);
    }
}
