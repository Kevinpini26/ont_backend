<?php

namespace Modules\Courrier\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Courrier\Models\DocumentProduitDirection;

/** @mixin DocumentProduitDirection */
class DocumentProduitDirectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'traitement_direction_id' => $this->traitement_direction_id, 'courrier_id' => $this->courrier_id,
            'document_source_id' => $this->document_source_id, 'direction_id' => $this->direction_id,
            'statut' => $this->statut?->value, 'statut_label' => $this->statut?->label(),
            'courrier' => new CourrierResource($this->whenLoaded('courrier')),
            'document_source' => $this->whenLoaded('documentSource', fn () => ['id' => $this->documentSource->id, 'objet' => $this->documentSource->objet, 'numero_enregistrement' => $this->documentSource->numero_enregistrement]),
            'motif_correction' => $this->motif_correction, 'cree_at' => $this->cree_at, 'soumis_at' => $this->soumis_at,
            'valide_at' => $this->valide_at, 'transmis_reception_at' => $this->transmis_reception_at, 'recu_at' => $this->recu_at,
        ];
    }
}
