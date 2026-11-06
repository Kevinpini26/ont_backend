<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Services\CourrierAnnotationService;
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
            // Un courrier arrive à la direction au dispatch exécuté, pas à sa création à la Réception.
            // Les anciens dossiers sans cette transition gardent leur date de création.
            $nonConsultes = (clone $recusQuery)->when($depuis, fn ($q) => $q->where(fn ($recu) => $recu
                ->whereHas('transitions', fn ($t) => $t->where('statut', CourrierStatut::DISPATCH_EXECUTE)->where('created_at', '>', $depuis))
                ->orWhere(fn ($ancien) => $ancien
                    ->whereDoesntHave('transitions', fn ($t) => $t->where('statut', CourrierStatut::DISPATCH_EXECUTE))
                    ->where('created_at', '>', $depuis))))
                ->count();

            // Même seuil que CourrierStatistiqueController::pourDirection()
            // ('courriers_recus_non_traites_48h') : rouge réservé au cas
            // vraiment bloquant, pas un nouveau critère de sévérité.
            $nonTraites48h = CourriersNonTraites::compter(now()->subHours(48), $user->direction_id);

            $compteurs['courriers_recus'] = [
                'count' => $nonConsultes,
                'tone' => $nonTraites48h > 0 ? 'danger' : 'warning',
            ];
        }

        // Instructions du circuit qui visent cet utilisateur (poste ou direction) et qu'il n'a pas encore lues.
        $compteurs['instructions_non_lues'] = [
            'count' => app(CourrierAnnotationService::class)->instructionsNonLues($user)->count(),
            'tone' => 'warning',
        ];

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
