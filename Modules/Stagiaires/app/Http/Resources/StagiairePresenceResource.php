<?php

namespace Modules\Stagiaires\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stagiaires\Models\StagiairePresence;

/** @mixin StagiairePresence */
class StagiairePresenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
            'heure_arrivee' => $this->heure_arrivee,
            'heure_depart' => $this->heure_depart,
            'saisi_par' => $this->whenLoaded('saisiPar', fn () => $this->saisiPar?->name),
            // Arrivée pointée mais aucun départ enregistré, pour un jour déjà
            // passé — le jour même n'est pas une anomalie, juste en cours.
            'incomplete' => $this->heure_arrivee !== null
                && $this->heure_depart === null
                && $this->date?->isBefore(now()->startOfDay()),
            'created_at' => $this->created_at,
        ];
    }
}
