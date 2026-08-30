<?php

namespace Modules\Stagiaires\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Modules\Stagiaires\Http\Requests\CreerSuiviStagiaireRequest;
use Modules\Stagiaires\Http\Resources\StagiaireSuiviResource;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Services\StagiaireCircuitService;

class StagiaireSuiviController extends Controller
{
    public function __construct(private readonly StagiaireCircuitService $circuit) {}

    public function index(Stagiaire $stagiaire)
    {
        $this->authorize('view', $stagiaire);

        return StagiaireSuiviResource::collection($stagiaire->suivis()->with('redigePar')->get());
    }

    public function store(CreerSuiviStagiaireRequest $request, Stagiaire $stagiaire)
    {
        $suivi = $this->circuit->ajouterSuivi($stagiaire, $request->user(), $request->validated());

        return (new StagiaireSuiviResource($suivi->load('redigePar')))->response()->setStatusCode(201);
    }
}
