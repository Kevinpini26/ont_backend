<?php

namespace Modules\Courrier\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Services\CycleDecisionnelService;
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
            'dossier_id' => $this->dossier_id,
            'numero_accuse_reception' => $this->numero_accuse_reception,
            'numero_enregistrement' => $this->numero_enregistrement,
            'reference_documentaire' => $this->reference_documentaire,
            'numero_depart' => $this->numero_depart,
            'pdf_sha256' => $this->pdf_sha256,
            // Généré uniquement à la signature (voir CourrierCircuitService::
            // signer()). Les dossiers courts historiques peuvent donc être
            // enregistrés sans disposer d'un PDF signé.
            'pdf_disponible' => filled($this->pdf_chemin),
            'numerisation_statut' => $this->numerisation_statut?->value,
            'numerisation_statut_label' => $this->numerisation_statut?->label(),
            'trouve_dans_contenu_numerise' => (bool) ($this->trouve_dans_contenu_numerise ?? false),
            'cote_classement' => $this->cote_classement,
            'emplacement_physique' => $this->emplacement_physique,
            'sens' => $this->sens?->value,
            'sens_label' => $this->sens?->label(),
            'en_reponse_a_courrier_id' => $this->en_reponse_a_courrier_id,
            'destinataire_externe_nom' => $this->destinataire_externe_nom,
            'destinataire_externe_email' => $this->destinataire_externe_email,
            'mode_expedition' => $this->mode_expedition?->value,
            'mode_expedition_label' => $this->mode_expedition?->label(),
            'date_envoi' => $this->date_envoi?->toDateString(),
            'remis_le' => $this->remis_le,
            'remis_a' => $this->remis_a,
            'mode_remise' => $this->mode_remise?->value,
            'mode_remise_label' => $this->mode_remise?->label(),
            'correspondance' => $this->when(
                $this->relationLoaded('reponses'),
                fn () => $this->reponses->map(fn ($reponse) => [
                    'id' => $reponse->id,
                    'numero_depart' => $reponse->numero_depart,
                    'reference_documentaire' => $reponse->reference_documentaire,
                    'numero_accuse_reception' => $reponse->numero_accuse_reception,
                    'objet' => $reponse->objet,
                    'statut' => $reponse->statut?->value,
                    'statut_label' => $reponse->statut?->label(),
                    'date_envoi' => $reponse->date_envoi?->toDateString(),
                ]),
            ),
            'objet' => $this->objet,
            'contenu' => $this->contenu,
            'date_courrier' => $this->date_courrier?->toDateString(),
            'reference_expediteur' => $this->reference_expediteur,
            'qualite_expediteur' => $this->qualite_expediteur,
            'mode_reception' => $this->mode_reception?->value,
            'mode_reception_label' => $this->mode_reception?->label(),
            'nombre_annexes' => $this->nombre_annexes,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'statut' => $this->statut?->value,
            'statut_label' => $this->statut?->label(),
            // Tour de la boucle interne (Lot 1) — incrémenté uniquement
            // quand la Réception remet à SEC1 un dossier revenu en "réservé".
            'tour' => $this->tour,
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
            'projet_renvoi_observation' => $this->projet_renvoi_observation,
            'projet_renvoye_at' => $this->projet_renvoye_at,
            'projet_renvoye_par' => $this->whenLoaded('projetRenvoyePar', fn () => $this->projetRenvoyePar?->name),
            'signataire' => new UserResource($this->whenLoaded('signataire')),
            'signe_at' => $this->signe_at,
            'classification' => $this->classification?->value,
            'degre_urgence' => $this->degre_urgence?->value,
            'degre_urgence_label' => $this->degre_urgence?->label(),
            // null tant que non trié (voir Courrier::urgenceTriee()) — à
            // distinguer d'un degré "normal" effectivement choisi.
            'urgence_triee_at' => $this->urgence_triee_at,
            'urgence_triee_par' => $this->whenLoaded('urgenceTrieePar', fn () => $this->urgenceTrieePar?->name),
            'niveau_confidentialite' => $this->niveau_confidentialite?->value,
            'niveau_confidentialite_label' => $this->niveau_confidentialite?->label(),
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
                    'ancien_statut' => $transition->ancien_statut?->value,
                    'nouveau_statut' => $transition->nouveau_statut?->value,
                    'tour' => $transition->tour,
                    'emetteur' => $transition->auteur?->name,
                    'expediteur_poste' => $transition->expediteur_poste,
                    'instruction' => $transition->instruction,
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
            'missions_documentaires' => MissionDocumentaireResource::collection($this->whenLoaded(
                'missionsDocumentaires',
                fn () => in_array($request->user()?->poste, [Poste::ASSISTANT_1, Poste::ASSISTANT_2, Poste::ASSISTANT_DGA], true)
                    ? $this->missionsDocumentaires->where('assistant_id', $request->user()->id)->values()
                    : $this->missionsDocumentaires,
            )),
            'dispatchs' => DispatchCourrierResource::collection($this->whenLoaded('dispatchs')),
            'peut_ouvrir_nouveau_cycle' => in_array($this->statut, [CourrierStatut::EN_ATTENTE_AVIS_DG, CourrierStatut::DISPATCH_EXECUTE], true)
                && app(CycleDecisionnelService::class)->peutOuvrir($this->resource),
            'provenance_directionnelle' => $this->whenLoaded('documentProduitDirection', fn () => $this->documentProduitDirection ? [
                'direction_id' => $this->documentProduitDirection->direction_id,
                'document_source_id' => $this->documentProduitDirection->document_source_id,
                'traitement_direction_id' => $this->documentProduitDirection->traitement_direction_id,
            ] : null),
            'classement' => $this->whenLoaded('classement', fn () => $this->classement ? [
                'id' => $this->classement->id, 'statut' => $this->classement->statut?->value,
                'statut_label' => $this->classement->statut?->label(), 'cote' => $this->classement->cote,
                'emplacement' => $this->classement->emplacement, 'observation' => $this->classement->observation,
                'classe_par' => $this->classement->classePar?->name, 'classe_at' => $this->classement->classe_at,
                'archive_par' => $this->classement->archivePar?->name, 'archive_at' => $this->classement->archive_at,
            ] : null),
            'pieces_jointes' => $this->when(
                $this->relationLoaded('piecesJointes'),
                fn () => $this->piecesJointes->map(fn ($piece) => [
                    'id' => $piece->id,
                    'libelle' => $piece->libelle,
                    'type_mime' => $piece->type_mime,
                    'taille_octets' => $piece->taille_octets,
                ]),
            ),
            'numerisations' => $this->when(
                $this->relationLoaded('numerisations'),
                fn () => $this->numerisations->map(fn ($doc) => [
                    'id' => $doc->id,
                    'version' => $doc->version,
                    'etape_circuit' => $doc->etape_circuit,
                    'nombre_pages' => $doc->nombre_pages,
                    'poids_octets' => $doc->poids_octets,
                    'source' => $doc->source?->value,
                    'source_label' => $doc->source?->label(),
                    'sha256' => $doc->sha256,
                    'qualite' => $doc->qualite?->value,
                    'qualite_label' => $doc->qualite?->label(),
                    'capture_par' => $doc->capturePar?->name,
                    'created_at' => $doc->created_at,
                ]),
            ),
            'imputations' => $this->when(
                $this->relationLoaded('imputations'),
                fn () => $this->imputations->map(fn ($imputation) => [
                    'id' => $imputation->id,
                    'direction' => new DirectionResource($imputation->direction),
                    'mention' => $imputation->mention?->value,
                    'mention_label' => $imputation->mention?->label(),
                    'est_principale' => $imputation->est_principale,
                    'imputee_par' => $imputation->imputeePar?->name,
                    'created_at' => $imputation->created_at,
                ]),
            ),
        ];
    }
}
