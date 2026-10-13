<?php

namespace Modules\Courrier\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Http\Resources\DirectionResource;
use Modules\Kernel\Http\Resources\UserResource;

/** @mixin DispatchCourrier */
class DispatchCourrierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'courrier_id' => $this->courrier_id,
            'dossier_id' => $this->dossier_id,
            'cycle' => $this->cycle,
            'courrier' => $this->whenLoaded('courrier', fn () => [
                'id' => $this->courrier->id,
                'objet' => $this->courrier->objet,
                'numero_enregistrement' => $this->courrier->numero_enregistrement,
                'reference_documentaire' => $this->courrier->reference_documentaire,
                'direction_origine' => $this->courrier->relationLoaded('directionOrigine')
                    ? $this->courrier->directionOrigine?->nom
                    : null,
                'direction_destination' => $this->courrier->relationLoaded('directionDestination')
                    ? $this->courrier->directionDestination?->nom
                    : null,
            ]),
            'document_autonome' => $this->when(
                $this->relationLoaded('courrier')
                && $this->courrier !== null
                && $this->courrier->relationLoaded('documentProduitDirection')
                && $this->courrier->documentProduitDirection?->origine === DocumentProduitOrigine::AUTONOME,
                fn () => [
                    'document_id' => $this->courrier->documentProduitDirection->id,
                    'scan_officiel_disponible' => $this->courrier->documentProduitDirection->scan_officiel_document_id !== null,
                    'prise_en_charge_sec2' => $this->transitionSec2Accusee(),
                ],
            ),
            'type_destination' => $this->type_destination?->value,
            'type_destination_label' => $this->type_destination?->label(),
            'direction' => new DirectionResource($this->whenLoaded('direction')),
            'destinataire_externe_nom' => $this->destinataire_externe_nom,
            'destinataire_externe_email' => $this->destinataire_externe_email,
            'instruction' => $this->instruction,
            'decisionnaire' => new UserResource($this->whenLoaded('decisionnaire')),
            'decisionnaire_poste' => $this->decisionnaire_poste?->value,
            'autorite_poste' => $this->autorite_poste?->value,
            'decide_at' => $this->decide_at,
            'statut' => $this->statut?->value,
            'statut_label' => $this->statut?->label(),
            'execute_par' => new UserResource($this->whenLoaded('executePar')),
            'execute_at' => $this->execute_at,
            'reference_transmission' => $this->reference_transmission,
            'preuve_disponible' => $this->preuve_piece_jointe_id !== null,
            'accuse_reception_par' => new UserResource($this->whenLoaded('accuseReceptionPar')),
            'accuse_reception_at' => $this->accuse_reception_at,
            'peut_accuser_reception' => $request->user() !== null
                && Gate::forUser($request->user())->allows('accuserReception', $this->resource),
            'traitement_direction' => new TraitementDirectionResource($this->whenLoaded('traitementDirection')),
        ];
    }

    private function transitionSec2Accusee(): ?array
    {
        if (! $this->courrier->relationLoaded('transitions')) {
            return null;
        }

        $transition = $this->courrier->transitions
            ->filter(fn ($transition) => $transition->nouveau_statut === CourrierStatut::EN_DISPATCH
                && $transition->destinataire_poste === Poste::SECRETARIAT_2->value)
            ->last();

        if ($transition === null || $transition->accuse_reception_at === null) {
            return null;
        }

        $acteur = $transition->relationLoaded('accuseReceptionPar')
            ? $transition->accuseReceptionPar
            : null;

        return [
            'acteur' => $acteur?->name,
            'date' => $transition->accuse_reception_at,
        ];
    }
}
