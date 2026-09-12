<?php

namespace Modules\Stagiaires\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Stagiaires\Http\Resources\StagiaireResource;
use Modules\Stagiaires\Support\StagiaireEnSouffrance;

class StagiaireEnSouffranceController extends Controller
{
    public function __construct(private readonly StagiaireEnSouffrance $enSouffrance) {}

    public function index(Request $request)
    {
        $lignes = $this->enSouffrance->pourUtilisateur($request->user());

        return response()->json([
            'data' => $lignes->map(fn (array $ligne) => [
                'stagiaire' => new StagiaireResource($ligne['stagiaire']->load('direction')),
                'anciennete_heures' => $ligne['anciennete_heures'],
                'seuil_heures' => $ligne['seuil_heures'],
                'niveau' => $ligne['niveau'],
            ])->values(),
        ]);
    }
}
