<?php

namespace Modules\Stagiaires\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stagiaires\Models\StagiaireDocument;

/** @mixin StagiaireDocument */
class StagiaireDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'nom_original' => $this->nom_original,
            'sha256' => $this->sha256,
            'created_at' => $this->created_at,
        ];
    }
}
