<?php

namespace Modules\Kernel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role?->value,
            'role_label' => $this->role?->label(),
            'poste' => $this->poste?->value,
            'poste_label' => $this->poste?->label(),
            'poste_delegue' => $this->when($request->user()?->id === $this->id,
                fn () => app(DelegationResolver::class)->posteDelegueAujourdhui($this->resource)?->value),
            'dg_disponible' => $this->when($this->poste?->value === 'dg', fn () => $this->dg_disponible),
            'direction' => new DirectionResource($this->whenLoaded('direction')),
            'direction_id' => $this->direction_id,
            'doit_changer_mot_de_passe' => $this->doit_changer_mot_de_passe,
            'created_at' => $this->created_at,
        ];
    }
}
