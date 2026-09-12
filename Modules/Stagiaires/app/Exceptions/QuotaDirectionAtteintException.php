<?php

namespace Modules\Stagiaires\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Distincte de StagiaireTransitionException : le frontend a besoin de
 * distinguer ce cas précis (quota atteint) d'un refus définitif, via le
 * champ `quota_atteint` plutôt qu'en parsant le message. Levée à
 * l'approbation d'un tableau de répartition (voir
 * StagiaireCircuitService::affecter(), appelée depuis
 * TableauRepartitionCircuitService::rendreAvis()) : plus de dérogation
 * "hors quota" depuis le Lot 5 — l'approbation entière échoue "en bloc",
 * la DFP doit composer un nouveau tableau avec une autre direction
 * d'accueil.
 */
class QuotaDirectionAtteintException extends Exception
{
    public function __construct()
    {
        parent::__construct('Cette direction a atteint sa capacité maximale de stagiaires.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'quota_atteint' => true,
        ], 422);
    }
}
