<?php

namespace Modules\Stagiaires\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Kernel\Http\Resources\DirectionResource;
use Modules\Stagiaires\Models\TableauRepartitionLigne;
use Modules\Stagiaires\Support\AvertissementsLigneTableau;

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
            'issue_proposee' => $this->issue_proposee?->value,
            'issue_proposee_label' => $this->issue_proposee?->label(),
            'motif_non_retenu' => $this->motif_non_retenu,
            'motif_non_retenu_label' => $this->motif_non_retenu ? (config('stagiaires.motifs_non_retenu')[$this->motif_non_retenu] ?? $this->motif_non_retenu) : null,
            'motif_non_retenu_libre' => $this->motif_non_retenu_libre,
            // Lot A : recalculés à chaque lecture, jamais stockés — reflètent
            // l'état courant (quota, dates, doublon), pas celui au moment de
            // l'ajout.
            'avertissements' => app(AvertissementsLigneTableau::class)->pour($this->resource),
        ];
    }
}
