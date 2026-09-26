<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courrier\Models\Dossier;
use Modules\Courrier\Services\ArchivageDossierService;

class ArchivageDossierController extends Controller
{
    public function __construct(private readonly ArchivageDossierService $service) {}

    public function decider(Request $request, Dossier $dossier)
    {
        return response()->json(['data' => $this->service->decider($dossier, $request->user())]);
    }

    public function archiver(Request $request, Dossier $dossier)
    {
        return response()->json(['data' => $this->service->archiver($dossier, $request->user())]);
    }
}
