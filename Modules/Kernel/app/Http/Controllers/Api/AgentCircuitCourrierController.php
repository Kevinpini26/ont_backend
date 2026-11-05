<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Http\Resources\AgentCircuitCourrierResource;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;
use Modules\Kernel\Support\DgAuthorityResolver;

class AgentCircuitCourrierController extends Controller
{
    /**
     * Liste minimale des agents du circuit courrier, utilisée notamment
     * pour désigner un relecteur lors de la soumission d'un projet de
     * réponse. Réservée aux postes qui désignent un relecteur ou un assistant (matrice d'accès,
     * proposition H) : DG (et son intérim), DGA, SEC1, assistants, directeurs de direction, et
     * l'administrateur pour la gestion de l'intérim.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $poste = app(DelegationResolver::class)->posteDelegueAujourdhui($user) ?? $user->poste;
        abort_unless(
            $user->role === UserRole::ADMINISTRATEUR
            || $user->role->estDirecteurDirection()
            || app(DgAuthorityResolver::class)->estAutorite($user)
            || ($user->role === UserRole::AGENT_CIRCUIT_COURRIER && in_array($poste, [
                Poste::DG, Poste::DGA, Poste::SECRETARIAT_1, Poste::ASSISTANT_1, Poste::ASSISTANT_2, Poste::ASSISTANT_DGA,
            ], true)),
            403,
        );

        return AgentCircuitCourrierResource::collection(
            User::query()
                ->where('role', UserRole::AGENT_CIRCUIT_COURRIER)
                ->whereNotIn('poste', [Poste::PROTOCOLE->value, Poste::ASSISTANT_PROTOCOLE->value])
                ->orderBy('name')
                ->get()
        );
    }
}
