<?php

namespace Modules\Courrier\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Courrier\Models\Courrier;

/** @mixin Courrier */
class DocumentDossierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dossier_id' => $this->dossier_id,
            'objet' => $this->objet,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'statut' => $this->statut?->value,
            'statut_label' => $this->statut?->label(),
            'relecture_validee_at' => $this->relecture_validee_at,
            'numero_accuse_reception' => $this->numero_accuse_reception,
            'numero_enregistrement' => $this->numero_enregistrement,
            'reference_documentaire' => $this->reference_documentaire,
            'numero_depart' => $this->numero_depart,
            'reference_expediteur' => $this->reference_expediteur,
            'created_at' => $this->created_at,
            'statut_archivistique' => $this->relationLoaded('classement') ? ($this->classement?->statut?->value ?? 'actif') : null,
        ];
    }
}
