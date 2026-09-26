<?php

namespace Modules\Courrier\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Kernel\Http\Resources\DirectionResource;
use Modules\Kernel\Http\Resources\UserResource;

/** @mixin DispatchCourrier */
class DispatchCourrierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'courrier_id' => $this->courrier_id,
            'dossier_id' => $this->dossier_id,
            'cycle' => $this->cycle,
            'courrier' => $this->whenLoaded('courrier', fn () => [
                'id' => $this->courrier->id,
                'objet' => $this->courrier->objet,
                'numero_enregistrement' => $this->courrier->numero_enregistrement,
            ]),
            'type_destination' => $this->type_destination?->value,
            'type_destination_label' => $this->type_destination?->label(),
            'direction' => new DirectionResource($this->whenLoaded('direction')),
            'destinataire_externe_nom' => $this->destinataire_externe_nom,
            'destinataire_externe_email' => $this->destinataire_externe_email,
            'instruction' => $this->instruction,
            'decisionnaire' => new UserResource($this->whenLoaded('decisionnaire')),
            'decisionnaire_poste' => $this->decisionnaire_poste?->value,
            'autorite_poste' => $this->autorite_poste?->value,
            'decide_at' => $this->decide_at,
            'statut' => $this->statut?->value,
            'statut_label' => $this->statut?->label(),
            'execute_par' => new UserResource($this->whenLoaded('executePar')),
            'execute_at' => $this->execute_at,
            'reference_transmission' => $this->reference_transmission,
            'preuve_disponible' => $this->preuve_piece_jointe_id !== null,
            'accuse_reception_par' => new UserResource($this->whenLoaded('accuseReceptionPar')),
            'accuse_reception_at' => $this->accuse_reception_at,
            'traitement_direction' => new TraitementDirectionResource($this->whenLoaded('traitementDirection')),
        ];
    }
}
