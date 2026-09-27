<?php

namespace Modules\Courrier\Support;

use Illuminate\Support\Carbon;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;

/**
 * "Non traité depuis plus de X" : signal d'alerte réutilisé à trois
 * endroits (CourrierStatistiqueController::dg() sans filtre de direction,
 * ::pourDirection() et NotificationCompteurController pour une direction
 * précise) — un seul point de vérité pour la requête plutôt que trois
 * copies de la même jointure sur la dernière transition de chaque courrier.
 */
class CourriersNonTraites
{
    public static function compter(Carbon $seuil, ?int $directionId = null): int
    {
        return Courrier::query()
            ->when($directionId !== null, fn ($query) => $query->where('direction_destination_id', $directionId))
            ->where('statut', '!=', CourrierStatut::ENREGISTRE)
            ->whereHas('transitions', fn ($transition) => $transition
                ->where('created_at', '<', $seuil)
                ->whereNotExists(fn ($plusRecente) => $plusRecente
                    ->selectRaw('1')
                    ->from('courrier_transitions as ct2')
                    ->whereColumn('ct2.courrier_id', 'courrier_transitions.courrier_id')
                    ->whereColumn('ct2.id', '>', 'courrier_transitions.id')))
            ->count();
    }
}
