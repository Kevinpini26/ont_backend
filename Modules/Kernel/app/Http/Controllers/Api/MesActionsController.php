<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Courrier\Services\ActionsAFaireService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;
use Modules\Kernel\Support\DgAuthorityResolver;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Enums\TableauRepartitionStatut;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Models\TableauRepartition;

/**
 * Accueil « Ce que j'ai à faire » : un seul appel qui rassemble les actions en attente de l'agent
 * connecté, triées par urgence puis par retard (lot 3). Remplace la consultation de plusieurs files.
 */
class MesActionsController extends Controller
{
    public function __construct(
        private readonly DelegationResolver $delegations,
        private readonly DgAuthorityResolver $autoriteDg,
    ) {}

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

        $actions = $service->trier($actions->merge($this->tableaux($user, $service)));

        return response()->json([
            'data' => $actions->values(),
            'total' => $actions->count(),
            'en_retard' => $actions->where('en_retard', true)->count(),
        ]);
    }

    /**
     * Tableaux de répartition : relèvent du module Stagiaires, d'où leur place ici. Réception pour la
     * présentation à la DG, autorité DG pour l'avis, DFP pour finaliser un brouillon ou un renvoi.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function tableaux(User $user, ActionsAFaireService $service): Collection
    {
        $action = null;
        $statut = null;
        if ($this->delegations->utilisateurHabilite($user, [Poste::RECEPTION])) {
            [$statut, $action] = [TableauRepartitionStatut::CHEZ_RECEPTION, 'Présenter le tableau à la DG'];
        } elseif ($this->autoriteDg->estAutorite($user)) {
            [$statut, $action] = [TableauRepartitionStatut::EN_ATTENTE_AVIS_DG, 'Rendre un avis sur le tableau'];
        } elseif ($user->role === UserRole::AGENT_DFP) {
            [$statut, $action] = [TableauRepartitionStatut::BROUILLON, 'Finaliser le tableau de répartition'];
        }
        if ($statut === null) {
            return collect();
        }

        return TableauRepartition::query()->where('statut', $statut)->limit(50)->get()
            ->map(fn (TableauRepartition $t) => $service->element(
                'tableau',
                $action,
                null,
                $t->updated_at,
                '/tableaux-repartition/'.$t->id,
                ['cle' => (string) $t->id, 'objet' => 'Tableau de répartition : '.$t->periode_debut?->format('d/m/Y').' au '.$t->periode_fin?->format('d/m/Y')],
            ));
    }
}
