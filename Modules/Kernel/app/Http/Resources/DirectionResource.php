<?php

namespace Modules\Kernel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Kernel\Models\Direction;

/** @mixin Direction */
class DirectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'nom' => $this->nom,
            'actif' => $this->actif,
            'capacite_max' => $this->capacite_max,
            'site_id' => $this->site_id,
            'site_nom' => $this->whenLoaded('site', fn () => $this->site?->nom),
        ];
    }
}
