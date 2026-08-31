<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Modules\Kernel\Http\Requests\CreerSiteRequest;
use Modules\Kernel\Models\Site;

/**
 * Référentiel des sites — consultable par tout utilisateur authentifié
 * (formulaire de gestion des directions), alimenté par l'administrateur
 * seul. Voir la migration create_sites_table pour le périmètre de cette
 * préparation multi-site.
 */
class SiteController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => Site::query()->where('actif', true)->orderBy('nom')->get(),
        ]);
    }

    public function store(CreerSiteRequest $request)
    {
        $site = Site::query()->create([
            'actif' => true,
            ...$request->validated(),
        ]);

        return response()->json(['data' => $site], 201);
    }
}
