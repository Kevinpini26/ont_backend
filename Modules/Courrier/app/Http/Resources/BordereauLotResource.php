<?php

namespace Modules\Courrier\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Courrier\Models\BordereauLot;

/** @mixin BordereauLot */
class BordereauLotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'emetteur' => $this->whenLoaded('emetteur', fn () => $this->emetteur?->name),
            'poste_destinataire' => $this->poste_destinataire?->value,
            'poste_destinataire_label' => $this->poste_destinataire?->label(),
            'accuse_reception_at' => $this->accuse_reception_at,
            'accuse_reception_par' => $this->whenLoaded('accuseReceptionPar', fn () => $this->accuseReceptionPar?->name),
            'acquitte' => $this->acquitte(),
            'dossiers' => $this->whenLoaded('courriers', fn () => $this->courriers->map(fn ($courrier) => [
                'id' => $courrier->id,
                'numero_accuse_reception' => $courrier->numero_accuse_reception,
                'objet' => $courrier->objet,
            ])->values()),
            'created_at' => $this->created_at,
        ];
    }
}
