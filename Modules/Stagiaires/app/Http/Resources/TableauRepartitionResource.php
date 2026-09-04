<?php

namespace Modules\Stagiaires\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Kernel\Http\Resources\DirectionResource;
use Modules\Stagiaires\Models\TableauRepartition;

/** @mixin TableauRepartition */
class TableauRepartitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'statut' => $this->statut->value,
            'statut_label' => $this->statut->label(),
            'modifiable' => $this->modifiable(),
            'direction' => new DirectionResource($this->whenLoaded('direction')),
            'redacteur' => $this->whenLoaded('redacteur', fn () => $this->redacteur?->name),
            'periode_debut' => $this->periode_debut->toDateString(),
            'periode_fin' => $this->periode_fin->toDateString(),
            'approuve_par' => $this->whenLoaded('approuvePar', fn () => $this->approuvePar?->name),
            'approuve_at' => $this->approuve_at,
            'pdf_disponible' => $this->pdf_chemin !== null,
            'tour' => $this->whenLoaded('courrier', fn () => $this->courrier?->tour),
            'avis_dg_commentaire' => $this->whenLoaded('courrier', fn () => $this->courrier?->avis_dg_commentaire),
            'lignes' => TableauRepartitionLigneResource::collection($this->whenLoaded('lignes')),
            'created_at' => $this->created_at,
        ];
    }
}
