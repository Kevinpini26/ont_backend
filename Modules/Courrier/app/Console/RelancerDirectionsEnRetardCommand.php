<?php

namespace Modules\Courrier\Console;

use Illuminate\Console\Command;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Enums\TraitementDirectionStatut;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Notifications\DispatchCourrierNotification;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;

/**
 * Relance les directions qui n'ont pas terminé le traitement d'un courrier
 * dispatché : échéance dépassée, ou — sans échéance — plus de
 * courrier.relance_direction.delai_jours_sans_echeance jours depuis l'envoi
 * par SEC2. Une seule relance par dispatch ; la DG est informée.
 */
class RelancerDirectionsEnRetardCommand extends Command
{
    protected $signature = 'courrier:relancer-directions-en-retard';

    protected $description = 'Relance les directions dont le traitement d’un courrier dispatché est en retard';

    public function __construct(private readonly NotificationService $notifications)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limiteSansEcheance = now()->subDays((int) config('courrier.relance_direction.delai_jours_sans_echeance', 7));

        $dispatchs = DispatchCourrier::query()
            ->where('type_destination', DispatchTypeDestination::DIRECTION->value)
            ->where('statut', DispatchStatut::EXECUTE->value)
            ->whereNull('relance_direction_envoyee_at')
            ->where(fn ($q) => $q
                ->where('echeance_at', '<', now()->startOfDay())
                ->orWhere(fn ($sans) => $sans->whereNull('echeance_at')->where('execute_at', '<', $limiteSansEcheance)))
            ->whereDoesntHave('traitementDirection', fn ($t) => $t->where('statut', TraitementDirectionStatut::TERMINE_DIRECTEUR->value))
            ->get();

        $dg = User::query()->where('role', UserRole::AGENT_CIRCUIT_COURRIER->value)->where('poste', Poste::DG->value)->get();

        foreach ($dispatchs as $dispatch) {
            User::query()
                ->where('direction_id', $dispatch->direction_id)
                ->whereIn('role', [UserRole::DIRECTEUR_DIRECTION->value, UserRole::RESPONSABLE_DIRECTION->value, UserRole::SECRETARIAT_DIRECTION->value])
                ->each(fn (User $user) => $this->notifications->notifier($user, new DispatchCourrierNotification($dispatch, 'relance_direction')));
            foreach ($dg as $destinataire) {
                $this->notifications->notifier($destinataire, new DispatchCourrierNotification($dispatch, 'relance_direction_dg'));
            }
            $dispatch->update(['relance_direction_envoyee_at' => now()]);
        }

        $this->info("{$dispatchs->count()} relance(s) de direction envoyée(s).");

        return self::SUCCESS;
    }
}
