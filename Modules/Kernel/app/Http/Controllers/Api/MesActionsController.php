<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courrier\Services\ActionsAFaireService;
use Modules\Kernel\Enums\UserRole;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;

/**
 * Accueil « Ce que j'ai à faire » : un seul appel qui rassemble les actions en attente de l'agent
 * connecté, triées par urgence puis par retard (lot 3). Remplace la consultation de plusieurs files.
 */
class MesActionsController extends Controller
{
    public function index(Request $request, ActionsAFaireService $service)
    {
        $user = $request->user();
        $actions = $service->pour($user);

        if ($user->role === UserRole::AGENT_DFP) {
            $demandes = Stagiaire::query()
                ->whereIn('statut', [StagiaireStatut::DOSSIER_RECU, StagiaireStatut::EN_ATTENTE_AFFECTATION])
                ->oldest('created_at')
                ->limit(200)
                ->get()
                ->map(fn (Stagiaire $s) => $service->element(
                    'demande_stage',
                    $s->statut === StagiaireStatut::DOSSIER_RECU ? 'Instruire la demande de stage' : 'Affecter le stagiaire',
                    null,
                    $s->statut_change_at ?? $s->created_at,
                    '/stagiaires/'.$s->id,
                    ['cle' => (string) $s->id, 'objet' => 'Demande de stage — '.$s->nom, 'reference' => $s->reference_courrier],
                ));
            $actions = $service->trier($actions->merge($demandes));
        }

        return response()->json([
            'data' => $actions->values(),
            'total' => $actions->count(),
            'en_retard' => $actions->where('en_retard', true)->count(),
        ]);
    }
}
