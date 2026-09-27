<?php

namespace Modules\Courrier\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Courrier\Models\InstructionCourrierDg;

/** @mixin InstructionCourrierDg */
class InstructionCourrierDgResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'donneur_id' => $this->donneur_id,
            'destinataire_user_id' => $this->destinataire_user_id,
            'instruction' => $this->instruction,
            'expire_at' => $this->expire_at,
            'ouvert_at' => $this->ouvert_at,
            'ouvert_par_id' => $this->ouvert_par_id,
            'consomme_at' => $this->consomme_at,
            'consomme_par_id' => $this->consomme_par_id,
            'annule_at' => $this->annule_at,
            'courrier_id' => $this->courrier_id,
            'active' => $this->active(),
            'created_at' => $this->created_at,
        ];
    }
}
