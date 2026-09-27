<?php

namespace Modules\Courrier\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Kernel\Http\Resources\UserResource;

/** @mixin MissionDocumentaire */
class MissionDocumentaireResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $peutVoirContenuInterne = $request->user() !== null
            && Gate::forUser($request->user())->allows('view', $this->resource);

        return [
            'id' => $this->id,
            'courrier_id' => $this->courrier_id,
            'dossier_id' => $this->dossier_id,
            'courrier' => $this->whenLoaded('courrier', fn () => [
                'id' => $this->courrier->id,
                'objet' => $this->courrier->objet,
                'numero_enregistrement' => $this->courrier->numero_enregistrement,
                'niveau_confidentialite' => $this->courrier->niveau_confidentialite?->value,
                'expediteur_externe_nom' => $this->courrier->expediteur_externe_nom,
                'expediteur_externe_email' => $this->courrier->expediteur_externe_email,
            ]),
            'demandeur' => new UserResource($this->whenLoaded('demandeur')),
            'demandeur_poste' => $this->demandeur_poste?->value,
            'autorite_poste' => $this->autorite_poste?->value,
            'assistant' => new UserResource($this->whenLoaded('assistant')),
            'instruction' => $this->when($peutVoirContenuInterne, $this->instruction),
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'projet_courrier_id' => $this->when($peutVoirContenuInterne, $this->projet_courrier_id),
            'projet_courrier' => $this->when($peutVoirContenuInterne && $this->relationLoaded('projetCourrier'), fn () => $this->projetCourrier ? [
                'id' => $this->projetCourrier->id,
                'objet' => $this->projetCourrier->objet,
                'statut' => $this->projetCourrier->statut?->value,
                'statut_label' => $this->projetCourrier->statut?->label(),
                'projet_reponse_contenu' => $this->projetCourrier->projet_reponse_contenu,
                'destinataire_externe_nom' => $this->projetCourrier->destinataire_externe_nom,
                'destinataire_externe_email' => $this->projetCourrier->destinataire_externe_email,
                'projet_renvoi_observation' => $this->projetCourrier->projet_renvoi_observation,
            ] : null),
            'statut' => $this->statut?->value,
            'statut_label' => $this->statut?->label(),
            'envoyee_at' => $this->envoyee_at,
            'prise_en_charge_at' => $this->prise_en_charge_at,
            'compte_rendu' => $this->when($peutVoirContenuInterne, $this->compte_rendu),
            'projet_reponse_contenu' => $this->when($peutVoirContenuInterne, $this->projet_reponse_contenu),
            'retournee_at' => $this->retournee_at,
            'annulee_par' => new UserResource($this->whenLoaded('annuleePar')),
            'motif_annulation' => $this->when($peutVoirContenuInterne, $this->motif_annulation),
            'annulee_at' => $this->annulee_at,
        ];
    }
}
