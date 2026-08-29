<?php

namespace Modules\Public\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stagiaires\Models\Stagiaire;

/** @mixin Stagiaire */
class AttestationPublicResource extends JsonResource
{
    /**
     * Vue volontairement restreinte : confirme l'authenticité d'une
     * attestation sans exposer d'autres informations du dossier
     * (évaluations, établissement d'origine, direction d'accueil, etc.).
     */
    public function toArray(Request $request): array
    {
        return [
            'numero_attestation' => $this->numero_attestation,
            'nom' => $this->nom,
            'date_debut_stage' => $this->date_debut_stage?->toDateString(),
            'date_fin_stage' => $this->date_fin_stage?->toDateString(),
        ];
    }
}
