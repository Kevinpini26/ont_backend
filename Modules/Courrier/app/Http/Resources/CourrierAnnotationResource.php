<?php

namespace Modules\Courrier\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Modules\Courrier\Models\CourrierAnnotation;
use Modules\Kernel\Http\Resources\UserResource;

/** @mixin CourrierAnnotation */
class CourrierAnnotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contenu' => $this->contenu,
            'auteur' => new UserResource($this->whenLoaded('auteur')),
            'created_at' => $this->created_at_with_timezone
                ? Carbon::parse($this->created_at_with_timezone)->toJSON()
                : $this->created_at,
        ];
    }
}
