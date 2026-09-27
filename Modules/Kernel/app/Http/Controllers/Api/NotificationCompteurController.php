<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Support\CourriersNonTraites;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\Consultation;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;

/**
 * Compteurs des badges de sidebar (Courriers, Demandes de stage) — polling
 * léger (voir useSidebarCounts.js côté frontend), pas de websocket : aucune
 * infrastructure de broadcasting n'est configurée sur ce projet.
 */
class NotificationCompteurController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $compteurs = [];

        if ($user->direction_id) {
            $depuis = Consultation::derniere($user, 'courriers_recus');

            $recusQuery = Courrier::query()
                ->where('direction_destination_id', $user->direction_id);
            $nonConsultes = (clone $recusQuery)->when($depuis, fn ($q) => $q->where('created_at', '>', $depuis))->count();

            // Même seuil que CourrierStatistiqueController::pourDirection()
            // ('courriers_recus_non_traites_48h') : rouge réservé au cas
            // vraiment bloquant, pas un nouveau critère de sévérité.
            $nonTraites48h = CourriersNonTraites::compter(now()->subHours(48), $user->direction_id);

            $compteurs['courriers_recus'] = [
                'count' => $nonConsultes,
                'tone' => $nonTraites48h > 0 ? 'danger' : 'warning',
            ];
        }

        if ($user->role === UserRole::AGENT_DFP) {
            $depuis = Consultation::derniere($user, 'demandes_stage');

            $count = Stagiaire::query()
                ->whereIn('statut', [StagiaireStatut::DOSSIER_RECU, StagiaireStatut::EN_ATTENTE_AFFECTATION])
                ->when($depuis, fn ($q) => $q->where('created_at', '>', $depuis))
                ->count();

            $compteurs['demandes_stage'] = ['count' => $count, 'tone' => 'warning'];
        }

        return response()->json($compteurs);
    }

    public function marquerConsulte(Request $request)
    {
        $data = $request->validate(['cle' => ['required', 'string', 'in:courriers_recus,demandes_stage']]);

        Consultation::marquer($request->user(), $data['cle']);

        return response()->json(null, 204);
    }
}
