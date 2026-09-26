<?php

namespace Modules\Courrier\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Kernel\Http\Resources\UserResource;

/** @mixin MissionDocumentaire */
class MissionDocumentaireResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'courrier_id' => $this->courrier_id,
            'dossier_id' => $this->dossier_id,
            'courrier' => $this->whenLoaded('courrier', fn () => [
                'id' => $this->courrier->id,
                'objet' => $this->courrier->objet,
                'numero_enregistrement' => $this->courrier->numero_enregistrement,
                'niveau_confidentialite' => $this->courrier->niveau_confidentialite?->value,
            ]),
            'demandeur' => new UserResource($this->whenLoaded('demandeur')),
            'demandeur_poste' => $this->demandeur_poste?->value,
            'autorite_poste' => $this->autorite_poste?->value,
            'assistant' => new UserResource($this->whenLoaded('assistant')),
            'instruction' => $this->instruction,
            'statut' => $this->statut?->value,
            'statut_label' => $this->statut?->label(),
            'envoyee_at' => $this->envoyee_at,
            'prise_en_charge_at' => $this->prise_en_charge_at,
            'compte_rendu' => $this->compte_rendu,
            'projet_reponse_contenu' => $this->projet_reponse_contenu,
            'retournee_at' => $this->retournee_at,
            'annulee_par' => new UserResource($this->whenLoaded('annuleePar')),
            'motif_annulation' => $this->motif_annulation,
            'annulee_at' => $this->annulee_at,
        ];
    }
}
