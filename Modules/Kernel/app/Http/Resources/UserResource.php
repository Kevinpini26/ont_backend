<?php

namespace Modules\Kernel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;
use Modules\Kernel\Support\DgAuthorityResolver;
use Modules\Kernel\Support\DgDisponibilite;
use Modules\Kernel\Support\PermissionsUtilisateur;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'initiales_reference' => $this->when(
                $request->user()?->role === UserRole::ADMINISTRATEUR,
                $this->initiales_reference,
            ),
            'email' => $this->email,
            'role' => $this->role?->value,
            'role_label' => $this->role?->label(),
            'poste' => $this->poste?->value,
            'poste_label' => $this->poste?->label(),
            'poste_delegue' => $this->when($request->user()?->id === $this->id,
                fn () => app(DelegationResolver::class)->posteDelegueAujourdhui($this->resource)?->value),
            'permissions' => $this->when($request->user()?->id === $this->id,
                fn () => PermissionsUtilisateur::pour($this->resource)),
            'source_autorite_dg' => $this->when($request->user()?->id === $this->id,
                fn () => app(DgAuthorityResolver::class)->source($this->resource)),
            'dg_disponible' => $this->when($this->poste?->value === 'dg', fn () => DgDisponibilite::estDisponible()),
            'direction' => new DirectionResource($this->whenLoaded('direction')),
            'direction_id' => $this->direction_id,
            'doit_changer_mot_de_passe' => $this->doit_changer_mot_de_passe,
            // Même garde que initiales_reference ci-dessous : l'état actif
            // d'un autre compte n'intéresse que l'administrateur.
            'actif' => $this->when(
                $request->user()?->role === UserRole::ADMINISTRATEUR || $request->user()?->id === $this->id,
                $this->actif,
            ),
            // Même garde que initiales_reference ci-dessus : un verrouillage
            // n'intéresse que l'administrateur qui gère les comptes (voir
            // AuthController::compteVerrouille(), même logique ici).
            'compte_verrouille' => $this->when(
                $request->user()?->role === UserRole::ADMINISTRATEUR,
                fn () => $this->verrouille_jusqu_a !== null && $this->verrouille_jusqu_a->isFuture(),
            ),
            'verrouille_jusqu_a' => $this->when(
                $request->user()?->role === UserRole::ADMINISTRATEUR,
                $this->verrouille_jusqu_a,
            ),
            'created_at' => $this->created_at,
        ];
    }
}
