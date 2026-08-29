<?php

namespace Modules\Courrier\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransitionNonAutoriseeException extends Exception
{
    public static function sautDetape(): self
    {
        return new self('Transition refusée : impossible de sauter une étape du circuit courrier.');
    }

    public static function posteNonHabilite(): self
    {
        return new self("Transition refusée : votre poste n'est pas habilité à effectuer cette action à cette étape.");
    }

    public static function dechargeNonDonnee(): self
    {
        return new self('Vous devez accuser réception de ce dossier avant de pouvoir agir dessus.');
    }

    public static function dechargeDejaDonnee(): self
    {
        return new self('La réception de ce dossier a déjà été accusée.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
