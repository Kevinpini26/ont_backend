<?php

namespace Modules\Stagiaires\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Kernel\Http\Resources\DirectionResource;
use Modules\Stagiaires\Models\TableauRepartitionLigne;

/** @mixin TableauRepartitionLigne */
class TableauRepartitionLigneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stagiaire' => new StagiaireResource($this->whenLoaded('stagiaire')),
            'direction_accueil_proposee' => new DirectionResource($this->whenLoaded('directionAccueilProposee')),
            'date_debut_proposee' => $this->date_debut_proposee?->toDateString(),
            'date_fin_proposee' => $this->date_fin_proposee?->toDateString(),
            'encadrant_pressenti' => $this->encadrant_pressenti,
        ];
    }
}
