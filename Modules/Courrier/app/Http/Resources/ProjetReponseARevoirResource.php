<?php

namespace Modules\Courrier\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Courrier\Models\Courrier;

/** @mixin Courrier */
class ProjetReponseARevoirResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'objet' => $this->objet,
            'dossier_id' => $this->dossier_id,
            'en_reponse_a_courrier_id' => $this->en_reponse_a_courrier_id,
            'statut' => $this->statut?->value,
            'statut_label' => $this->statut?->label(),
            'updated_at' => $this->updated_at,
            'courrier_origine' => $this->whenLoaded('courrierOrigine', fn () => $this->courrierOrigine ? [
                'id' => $this->courrierOrigine->id,
                'numero_enregistrement' => $this->courrierOrigine->numero_enregistrement,
                'numero_accuse_reception' => $this->courrierOrigine->numero_accuse_reception,
                'objet' => $this->courrierOrigine->objet,
            ] : null),
            'createur' => $this->whenLoaded('createur', fn () => $this->createur ? [
                'id' => $this->createur->id,
                'name' => $this->createur->name,
            ] : null),
        ];
    }
}
