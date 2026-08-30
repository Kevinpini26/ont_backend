<?php

namespace Modules\Stagiaires\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stagiaires\Models\StagiaireSuivi;

/** @mixin StagiaireSuivi */
class StagiaireSuiviResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date_suivi' => $this->date_suivi->toDateString(),
            'observations' => $this->observations,
            'difficultes_signalees' => $this->difficultes_signalees,
            'redige_par' => [
                'id' => $this->redigePar->id,
                'nom' => $this->redigePar->name,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
