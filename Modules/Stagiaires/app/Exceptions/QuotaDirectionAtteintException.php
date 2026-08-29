<?php

namespace Modules\Stagiaires\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Distincte de StagiaireTransitionException : le frontend a besoin de
 * distinguer ce cas précis (quota atteint, dérogation possible) d'un refus
 * définitif, via le champ `quota_atteint` plutôt qu'en parsant le message.
 */
class QuotaDirectionAtteintException extends Exception
{
    public function __construct()
    {
        parent::__construct('Cette direction a atteint sa capacité maximale de stagiaires. Une dérogation justifiée est requise pour poursuivre.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'quota_atteint' => true,
        ], 422);
    }
}
