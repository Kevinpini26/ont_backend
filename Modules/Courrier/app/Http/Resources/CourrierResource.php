<?php

namespace Modules\Courrier\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Http\Resources\DirectionResource;
use Modules\Kernel\Http\Resources\UserResource;

/** @mixin Courrier */
class CourrierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero_accuse_reception' => $this->numero_accuse_reception,
            'numero_enregistrement' => $this->numero_enregistrement,
            'objet' => $this->objet,
            'contenu' => $this->contenu,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'statut' => $this->statut?->value,
            'statut_label' => $this->statut?->label(),
            'necessite_avis_dg' => $this->necessite_avis_dg,
            'initie_par_dg' => $this->initie_par_dg,
            'validation_dg_requise' => $this->validation_dg_requise,
            'valide_par_dg_at' => $this->valide_par_dg_at,
            'direction_origine' => new DirectionResource($this->whenLoaded('directionOrigine')),
            'direction_destination' => new DirectionResource($this->whenLoaded('directionDestination')),
            'expediteur_externe_nom' => $this->expediteur_externe_nom,
            'expediteur_externe_email' => $this->expediteur_externe_email,
            'expediteur_externe_telephone' => $this->expediteur_externe_telephone,
            'piece_jointe_disponible' => filled($this->piece_jointe_chemin),
            'candidat' => $this->when($this->type?->value === 'demande_stage', fn () => [
                'nom' => $this->candidat_nom,
                'contact' => $this->candidat_contact,
                'etablissement' => $this->candidat_etablissement,
                'periode_souhaitee_debut' => $this->periode_souhaitee_debut?->toDateString(),
                'periode_souhaitee_fin' => $this->periode_souhaitee_fin?->toDateString(),
                'type_stage' => $this->type_stage,
                'type_stage_label' => $this->type_stage === 'professionnel' ? 'Stage professionnel' : ($this->type_stage === 'academique' ? 'Stage académique' : null),
                'lettre_stage_disponible' => filled($this->lettre_stage_chemin),
                'cv_disponible' => filled($this->cv_chemin),
                'diplome_etat_disponible' => filled($this->diplome_etat_chemin),
                'dernier_diplome_disponible' => filled($this->dernier_diplome_chemin),
                'lettre_demande_disponible' => filled($this->lettre_demande_chemin),
            ]),
            'avis_dg' => $this->avis_dg?->value,
            'avis_dg_commentaire' => $this->avis_dg_commentaire,
            'avis_dg_rendu_par' => $this->whenLoaded('avisDgRenduPar', fn () => $this->avisDgRenduPar?->name),
            // Trace claire de qui a réellement pris la décision quand la DGA
            // intervient en intérim de la DG — visible sur le courrier une
            // fois traité, voir CourrierCircuitService::rendreAvisDg().
            'avis_dg_rendu_en_interim' => $this->avis_dg_rendu_en_interim,
            'anonymise_at' => $this->anonymise_at,
            'projet_reponse_contenu' => $this->projet_reponse_contenu,
            'relecteur' => new UserResource($this->whenLoaded('relecteur')),
            'relecture_validee_at' => $this->relecture_validee_at,
            'relecture_commentaire' => $this->relecture_commentaire,
            'signataire' => new UserResource($this->whenLoaded('signataire')),
            'signe_at' => $this->signe_at,
            'classification' => $this->classification?->value,
            'note_technique' => $this->note_technique,
            'accuse_reception_partenaire' => $this->accuse_reception_partenaire,
            'enregistre_at' => $this->enregistre_at,
            'createur' => new UserResource($this->whenLoaded('createur')),
            'created_at' => $this->created_at,
            // "En transit" tant que le bordereau courant n'est pas
            // acquitté par son destinataire — voir Courrier::enTransit().
            // Nécessite la relation "transitions" chargée.
            'en_transit' => $this->relationLoaded('transitions') ? $this->enTransit() : null,
            'transitions' => $this->when(
                $this->relationLoaded('transitions') && $this->transitions->first()?->relationLoaded('auteur'),
                fn () => $this->transitions->map(fn ($transition) => [
                    'statut' => $transition->statut?->value,
                    'statut_label' => $transition->statut?->label(),
                    'emetteur' => $transition->auteur?->name,
                    'destinataire' => match (true) {
                        $transition->destinataire_user_id !== null => $transition->destinataireUser?->name,
                        $transition->destinataire_poste !== null => Poste::from($transition->destinataire_poste)->label(),
                        default => null,
                    },
                    'created_at' => $transition->created_at,
                    'accuse_reception_par' => $transition->accuseReceptionPar?->name,
                    'accuse_reception_at' => $transition->accuse_reception_at,
                ]),
            ),
        ];
    }
}
