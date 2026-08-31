<?php

namespace Modules\Kernel\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rejet d'un document numérisé à l'ingestion (poids par page trop faible,
 * qui trahit une image illisible) — voir GestionnaireDocumentNumerise.
 * Rendue en 422, jamais 500 : c'est un problème de qualité du scan
 * soumis, pas une erreur serveur.
 */
class DocumentNumeriseRejeteException extends Exception
{
    public static function qualiteInsuffisante(): self
    {
        return new self(
            'Le fichier semble illisible (poids par page trop faible). '.
            'Merci de reprendre la capture, si possible sans désactiver le noir et blanc.'
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
