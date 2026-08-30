<?php

namespace Modules\Stagiaires\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Modules\Stagiaires\Http\Requests\CreerEtablissementFormationRequest;
use Modules\Stagiaires\Models\EtablissementFormation;

/**
 * Référentiel consultable par tout utilisateur authentifié (nécessaire au
 * remplissage du formulaire de dossier stagiaire), mais alimenté seulement
 * par la DFP — voir CreerEtablissementFormationRequest.
 */
class EtablissementFormationController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => EtablissementFormation::query()->where('actif', true)->orderBy('nom')->get(),
        ]);
    }

    public function store(CreerEtablissementFormationRequest $request)
    {
        $etablissement = EtablissementFormation::query()->create($request->validated());

        return response()->json(['data' => $etablissement], 201);
    }
}
