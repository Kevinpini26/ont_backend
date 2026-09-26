<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Http\Resources\AgentCircuitCourrierResource;
use Modules\Kernel\Models\User;

class AgentCircuitCourrierController extends Controller
{
    /**
     * Liste minimale des agents du circuit courrier, utilisée notamment
     * pour désigner un relecteur lors de la soumission d'un projet de
     * réponse. Accessible à tout utilisateur authentifié (aucune donnée
     * sensible exposée).
     */
    public function index(Request $request)
    {
        return AgentCircuitCourrierResource::collection(
            User::query()
                ->where('role', UserRole::AGENT_CIRCUIT_COURRIER)
                ->whereNotIn('poste', [Poste::PROTOCOLE->value, Poste::ASSISTANT_PROTOCOLE->value])
                ->orderBy('name')
                ->get()
        );
    }
}
