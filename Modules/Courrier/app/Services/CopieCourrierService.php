<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierCopie;
use Modules\Courrier\Notifications\CopieCourrierNotification;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * Copie « pour information » (ampliation) : la DG met d'autres directions en
 * copie d'un courrier. Elles le consultent mais n'ont rien à traiter, et la
 * copie ne modifie ni le statut ni le circuit du courrier.
 */
class CopieCourrierService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array<int, int>  $directionIds
     * @return array<int, CourrierCopie> copies réellement ajoutées (les doublons sont ignorés)
     */
    public function ajouter(Courrier $courrier, User $acteur, array $directionIds, ?string $note = null): array
    {
        $directionIds = array_values(array_unique(array_map('intval', $directionIds)));

        $ajoutees = DB::transaction(function () use ($courrier, $acteur, $directionIds, $note) {
            $ajoutees = [];
            foreach ($directionIds as $directionId) {
                $direction = Direction::query()->find($directionId);
                if ($direction === null || ! $direction->actif) {
                    throw ValidationException::withMessages(['direction_ids' => 'Une des directions choisies est introuvable ou inactive.']);
                }
                if ($courrier->dispatchs()->where('direction_id', $directionId)->exists()
                    || $courrier->direction_destination_id === $directionId) {
                    continue; // déjà destinataire : une copie n'apporterait rien
                }
                $copie = CourrierCopie::query()->firstOrCreate(
                    ['courrier_id' => $courrier->id, 'direction_id' => $directionId],
                    ['ajoute_par_id' => $acteur->id, 'note' => $note],
                );
                if ($copie->wasRecentlyCreated) {
                    $ajoutees[] = $copie;
                    $this->audit->enregistrer('courrier.copie_ajoutee', $courrier, $acteur, ['direction_id' => $directionId]);
                }
            }

            return $ajoutees;
        });

        DB::afterCommit(function () use ($ajoutees, $courrier) {
            foreach ($ajoutees as $copie) {
                User::query()
                    ->where('direction_id', $copie->direction_id)
                    ->whereIn('role', [UserRole::DIRECTEUR_DIRECTION->value, UserRole::RESPONSABLE_DIRECTION->value, UserRole::SECRETARIAT_DIRECTION->value])
                    ->each(fn (User $user) => $this->notifications->notifier($user, new CopieCourrierNotification($courrier)));
            }
        });

        return $ajoutees;
    }
}
