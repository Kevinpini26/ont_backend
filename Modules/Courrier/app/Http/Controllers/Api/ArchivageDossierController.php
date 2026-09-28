<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courrier\Models\Dossier;
use Modules\Courrier\Services\ArchivageDossierService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Support\DelegationResolver;

class ArchivageDossierController extends Controller
{
    public function __construct(private readonly ArchivageDossierService $service) {}

    public function index(Request $request)
    {
        abort_unless(app(DelegationResolver::class)->utilisateurHabilite($request->user(), [Poste::SECRETARIAT_2]), 403);

        return response()->json(Dossier::query()
            ->select(['id', 'libelle', 'statut_archivage', 'archivage_decide_at'])
            ->where('statut_archivage', 'a_archiver')->whereHas('documents')
            ->orderBy('archivage_decide_at')->paginate(20));
    }

    public function decider(Request $request, Dossier $dossier)
    {
        return response()->json(['data' => $this->service->decider($dossier, $request->user())]);
    }

    public function archiver(Request $request, Dossier $dossier)
    {
        return response()->json(['data' => $this->service->archiver($dossier, $request->user())]);
    }
}
