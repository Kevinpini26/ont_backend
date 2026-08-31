<?php

namespace Modules\Public\Http\Controllers\Api;

use App\Http\Controllers\Controller;

/**
 * Expose la mention d'information due à toute personne dont les données
 * sont collectées (article 220 de l'ordonnance-loi n°23/010 portant Code
 * du numérique — voir config('kernel.mention_information') et
 * docs/conformite-donnees.md), pour affichage sur les formulaires publics
 * de dépôt (demande de stage, courrier externe).
 */
class MentionInformationController extends Controller
{
    public function show()
    {
        return response()->json(['data' => config('kernel.mention_information')]);
    }
}
