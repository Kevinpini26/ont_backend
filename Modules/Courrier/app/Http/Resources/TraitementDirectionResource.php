<?php

namespace Modules\Courrier\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Kernel\Http\Resources\DirectionResource;
use Modules\Kernel\Http\Resources\UserResource;

/** @mixin TraitementDirection */
class TraitementDirectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'courrier_id' => $this->courrier_id, 'dossier_id' => $this->dossier_id,
            'dispatch_courrier_id' => $this->dispatch_courrier_id,
            'courrier' => $this->whenLoaded('courrier', fn () => ['id' => $this->courrier->id, 'objet' => $this->courrier->objet, 'numero_enregistrement' => $this->courrier->numero_enregistrement, 'reference_documentaire' => $this->courrier->reference_documentaire]),
            'dispatch' => new DispatchCourrierResource($this->whenLoaded('dispatch')),
            'direction' => new DirectionResource($this->whenLoaded('direction')),
            'statut' => $this->statut?->value, 'statut_label' => $this->statut?->label(),
            'recu_par_secretariat' => new UserResource($this->whenLoaded('recuParSecretariat')),
            'recu_secretariat_at' => $this->recu_secretariat_at,
            'transmis_par_secretariat' => new UserResource($this->whenLoaded('transmisParSecretariat')),
            'directeur' => new UserResource($this->whenLoaded('directeur')),
            'note_transmission' => $this->note_transmission, 'transmis_directeur_at' => $this->transmis_directeur_at,
            'pris_en_charge_par' => new UserResource($this->whenLoaded('prisEnChargePar')),
            'pris_en_charge_at' => $this->pris_en_charge_at,
            'decision_directeur' => $this->decision_directeur?->value,
            'decision_directeur_label' => $this->decision_directeur?->label(),
            'commentaire_directeur' => $this->commentaire_directeur,
            'decision_par' => new UserResource($this->whenLoaded('decisionPar')), 'decision_at' => $this->decision_at,
            'document_produit' => new DocumentProduitDirectionResource($this->whenLoaded('documentProduit')),
        ];
    }
}
