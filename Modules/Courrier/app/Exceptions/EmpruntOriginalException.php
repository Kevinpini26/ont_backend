<?php

namespace Modules\Courrier\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmpruntOriginalException extends Exception
{
    public static function dejaEmprunte(): self
    {
        return new self("L'original de ce courrier est déjà sorti — restituez-le d'abord.");
    }

    public static function aucunEmpruntEnCours(): self
    {
        return new self("Aucune sortie de l'original n'est en cours pour ce courrier.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
