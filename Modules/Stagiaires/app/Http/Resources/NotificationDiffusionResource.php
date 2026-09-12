<?php

namespace Modules\Stagiaires\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stagiaires\Models\NotificationDiffusion;

/** @mixin NotificationDiffusion */
class NotificationDiffusionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'canal' => $this->canal,
            'destinataire' => $this->destinataire,
            'contenu' => $this->contenu,
            'envoye_at' => $this->envoye_at,
            'statut_remise' => $this->statut_remise,
            'envoye_par' => $this->whenLoaded('envoyePar', fn () => $this->envoyePar?->name),
        ];
    }
}
