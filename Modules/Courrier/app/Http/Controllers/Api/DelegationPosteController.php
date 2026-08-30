<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courrier\Http\Requests\CreerDelegationPosteRequest;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\DelegationPoste;

class DelegationPosteController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->role === UserRole::ADMINISTRATEUR, 403);

        return response()->json([
            'data' => DelegationPoste::query()
                ->with(['delegataire', 'creePar'])
                ->latest('id')
                ->get(),
        ]);
    }

    public function store(CreerDelegationPosteRequest $request)
    {
        $delegation = DelegationPoste::query()->create([
            ...$request->validated(),
            'cree_par_id' => $request->user()->id,
        ]);

        return response()->json([
            'data' => $delegation->load(['delegataire', 'creePar']),
        ], 201);
    }
}
