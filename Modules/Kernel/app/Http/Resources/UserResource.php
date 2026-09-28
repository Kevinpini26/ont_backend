<?php

namespace Modules\Kernel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;
use Modules\Kernel\Support\DgAuthorityResolver;
use Modules\Kernel\Support\DgDisponibilite;

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
            'source_autorite_dg' => $this->when($request->user()?->id === $this->id,
                fn () => app(DgAuthorityResolver::class)->source($this->resource)),
            'dg_disponible' => $this->when($this->poste?->value === 'dg', fn () => DgDisponibilite::estDisponible()),
            'direction' => new DirectionResource($this->whenLoaded('direction')),
            'direction_id' => $this->direction_id,
            'doit_changer_mot_de_passe' => $this->doit_changer_mot_de_passe,
            'created_at' => $this->created_at,
        ];
    }
}
