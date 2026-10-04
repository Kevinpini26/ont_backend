<?php

namespace Modules\Courrier\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RelectureNonValideeException extends Exception
{
    protected $message = "Action refusée : le relecteur désigné n'a pas encore validé le projet de réponse.";

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
