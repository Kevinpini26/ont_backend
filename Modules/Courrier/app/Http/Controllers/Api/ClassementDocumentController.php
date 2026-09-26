<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courrier\Models\ClassementDocument;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Services\ClassementDocumentService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Support\DelegationResolver;

class ClassementDocumentController extends Controller
{
    public function __construct(private readonly ClassementDocumentService $service, private readonly DelegationResolver $delegations) {}

    public function index(Request $request)
    {
        abort_unless($this->delegations->utilisateurHabilite($request->user(), [Poste::SECRETARIAT_2]), 403);

        return response()->json(ClassementDocument::query()->with(['courrier', 'dispatch.decisionnaire', 'classePar', 'archivePar'])->latest()->paginate(20));
    }

    public function classer(Request $request, DispatchCourrier $dispatch)
    {
        $data = $request->validate(['cote' => ['nullable', 'string', 'max:255'], 'emplacement' => ['required', 'string', 'max:255'], 'observation' => ['nullable', 'string', 'max:5000']]);

        return response()->json(['data' => $this->service->classer($dispatch, $request->user(), $data)]);
    }

    public function archiver(Request $request, ClassementDocument $classement)
    {
        $data = $request->validate(['observation' => ['nullable', 'string', 'max:5000']]);

        return response()->json(['data' => $this->service->archiver($classement, $request->user(), $data['observation'] ?? null)]);
    }

    public function corriger(Request $request, ClassementDocument $classement)
    {
        $data = $request->validate(['cote' => ['nullable', 'string', 'max:255'], 'emplacement' => ['nullable', 'string', 'max:255'], 'observation' => ['nullable', 'string', 'max:5000'], 'motif' => ['required', 'string', 'max:1000']]);

        return response()->json(['data' => $this->service->corriger($classement, $request->user(), $data)]);
    }
}
