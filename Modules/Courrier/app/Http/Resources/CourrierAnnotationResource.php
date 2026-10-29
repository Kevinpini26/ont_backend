<?php

namespace Modules\Courrier\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Modules\Courrier\Enums\CourrierAnnotationType;
use Modules\Courrier\Enums\CourrierAnnotationVisibilite;
use Modules\Courrier\Models\CourrierAnnotation;
use Modules\Courrier\Services\CourrierAnnotationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Http\Resources\UserResource;

/** @mixin CourrierAnnotation */
class CourrierAnnotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $moi = $request->user();
        $masque = (bool) $request->attributes->get('annotations_masquees', false);
        $personnelle = $this->visibilite === CourrierAnnotationVisibilite::PERSONNELLE;
        $estInstruction = $this->type === CourrierAnnotationType::INSTRUCTION;
        $posteAuteur = $this->auteur?->poste;
        $poste = $this->destinataire_poste !== null ? Poste::tryFrom($this->destinataire_poste) : null;
        $lectureMoi = $this->relationLoaded('lectures') ? $this->lectures->firstWhere('user_id', $moi?->id) : null;

        return [
            'id' => $this->id,
            'courrier_id' => $this->courrier_id,
            'courrier_objet' => $request->attributes->get('annotations_courriers') && $this->relationLoaded('courrier') ? $this->courrier?->objet : null,
            'contenu' => $masque ? null : $this->contenu,
            'contenu_masque' => $masque,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'couleur' => $this->type->couleur(),
            'visibilite' => $this->visibilite->value,
            'personnelle' => $personnelle,
            'auteur' => new UserResource($this->whenLoaded('auteur')),
            'auteur_poste' => $posteAuteur?->value,
            'auteur_poste_label' => $posteAuteur?->label(),
            'destinataire' => $poste === null && $this->destinataire_direction_id === null ? null : [
                'poste' => $poste?->value,
                'poste_label' => $poste?->label(),
                'direction' => $this->destinataire_direction_id === null ? null : [
                    'id' => $this->destinataire_direction_id,
                    'nom' => $this->whenLoaded('destinataireDirection', fn () => $this->destinataireDirection?->nom),
                ],
            ],
            'vise_moi' => $moi !== null && app(CourrierAnnotationService::class)->vise($this->resource, $moi),
            'lue_par_moi' => $lectureMoi !== null,
            'lectures' => $estInstruction && $this->relationLoaded('lectures')
                ? $this->lectures->map(fn ($l) => ['user' => $l->user?->name, 'lu_at' => $l->lu_at])->values()
                : [],
            'corrigee_par_id' => $this->annule_par_id,
            'corrige_la_note_id' => $this->relationLoaded('correctionDe') ? $this->correctionDe?->id : null,
            'peut_corriger' => $moi !== null && ! $masque && $this->auteur_id === $moi->id && ! $personnelle && $this->annule_par_id === null,
            'peut_retirer' => $moi !== null && $this->auteur_id === $moi->id && $this->type === CourrierAnnotationType::RAPPEL,
            'created_at' => $this->created_at_with_timezone
                ? Carbon::parse($this->created_at_with_timezone)->toJSON()
                : $this->created_at,
        ];
    }
}
