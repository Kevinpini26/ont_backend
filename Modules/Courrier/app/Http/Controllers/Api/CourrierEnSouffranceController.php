<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courrier\Http\Resources\CourrierResource;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Support\CourrierEnSouffrance;

class CourrierEnSouffranceController extends Controller
{
    public function __construct(private readonly CourrierEnSouffrance $enSouffrance) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Courrier::class);

        $lignes = $this->enSouffrance->pourUtilisateur($request->user());

        return response()->json([
            'data' => $lignes->map(fn (array $ligne) => [
                'courrier' => new CourrierResource($ligne['courrier']->load(['directionOrigine', 'directionDestination'])),
                'anciennete_heures' => $ligne['anciennete_heures'],
                'seuil_heures' => $ligne['seuil_heures'],
                'niveau' => $ligne['niveau'],
            ])->values(),
        ]);
    }
}
