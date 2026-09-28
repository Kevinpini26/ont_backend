<?php

namespace Modules\Courrier\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Courrier\Models\ClassementDocument;

/** @mixin ClassementDocument */
class ClassementDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'courrier_id' => $this->courrier_id,
            'dossier_id' => $this->dossier_id, 'dispatch_courrier_id' => $this->dispatch_courrier_id,
            'statut' => $this->statut?->value, 'cote' => $this->cote,
            'emplacement' => $this->emplacement, 'observation' => $this->observation,
            'classe_par_id' => $this->classe_par_id, 'classe_at' => $this->classe_at,
            'archive_par_id' => $this->archive_par_id, 'archive_at' => $this->archive_at,
            'courrier' => $this->whenLoaded('courrier', fn () => $this->courrier === null ? null : [
                'id' => $this->courrier->id, 'objet' => $this->courrier->objet,
                'numero_enregistrement' => $this->courrier->numero_enregistrement,
                'reference_documentaire' => $this->courrier->reference_documentaire,
                'numero_depart' => $this->courrier->numero_depart,
            ]),
        ];
    }
}
