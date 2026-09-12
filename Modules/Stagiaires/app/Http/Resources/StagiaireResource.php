<?php

namespace Modules\Stagiaires\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Kernel\Http\Resources\DirectionResource;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Support\AssiduiteCalculateur;

/** @mixin Stagiaire */
class StagiaireResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'matricule' => $this->matricule,
            'nom' => $this->nom,
            'contact' => $this->contact,
            'etablissement_origine' => $this->etablissement_origine,
            'etablissement_id' => $this->etablissement_id,
            'type_stage' => $this->type_stage?->value,
            'type_stage_label' => $this->type_stage?->label(),
            'lieu_naissance' => $this->lieu_naissance,
            'filiere_formation' => $this->filiere_formation,
            'niveau_formation' => $this->niveau_formation,
            'session_promotion' => $this->session_promotion,
            'maitre_stage' => $this->maitre_stage,
            'maitre_stage_id' => $this->maitre_stage_id,
            'conseiller_stage' => $this->conseiller_stage,
            'periode_debut_demandee' => $this->periode_debut_demandee?->toDateString(),
            'periode_fin_demandee' => $this->periode_fin_demandee?->toDateString(),
            'reference_courrier' => $this->reference_courrier,
            'statut' => $this->statut?->value,
            'statut_label' => $this->statut?->label(),
            'direction' => new DirectionResource($this->whenLoaded('direction')),
            'affecte_at' => $this->affecte_at,
            'affecte_hors_quota' => $this->affecte_hors_quota,
            'date_debut_stage' => $this->date_debut_stage?->toDateString(),
            'date_fin_stage' => $this->date_fin_stage?->toDateString(),
            'jours_restants' => $this->joursRestants(),
            'objectifs' => $this->objectifs,

            // Statut d'avancement de l'évaluation, visible par tous — jamais
            // le contenu (voir bloc "evaluation" ci-dessous, réservé à la DFP).
            'periode_evaluation_ouverte' => $this->periode_evaluation_ouverte_at !== null,
            'evaluation_direction_soumise' => $this->evaluation_direction_at !== null,
            'evaluation_dfp_soumise' => $this->evaluation_dfp_at !== null,

            // Suggestion de ponctualité/régularité pour la grille — deux
            // nombres agrégés, jamais le détail des présences journalières
            // (celui-ci reste réservé à la DFP, voir gererPresence()).
            // Calculée seulement si 'presences' est chargée (show() la
            // charge ; les listes ne la chargent volontairement pas, pour
            // éviter une requête N+1 par ligne).
            'assiduite_suggestion' => $this->when(
                $this->relationLoaded('presences'),
                fn () => AssiduiteCalculateur::suggestion($this->resource),
            ),

            // Détail des deux évaluations + moyenne : strictement réservé à
            // la DFP et à l'administrateur (StagiairePolicy::voirEvaluationFinale) —
            // absent du JSON pour la direction, y compris après sa propre soumission.
            'evaluation' => $this->when(
                $request->user()?->can('voirEvaluationFinale', Stagiaire::class) ?? false,
                fn () => [
                    'direction' => [
                        'grille' => $this->evaluation_direction_grille,
                        'total' => $this->evaluation_direction_total,
                        'at' => $this->evaluation_direction_at,
                    ],
                    'dfp' => [
                        'grille' => $this->evaluation_dfp_grille,
                        'total' => $this->evaluation_dfp_total,
                        'at' => $this->evaluation_dfp_at,
                    ],
                    'note_finale' => $this->note_finale,
                ],
            ),

            'cloture_at' => $this->cloture_at,
            'created_at' => $this->created_at,

            // Lot D : classement retrouvable — la lettre d'origine (via le
            // courrier porteur) et le tableau de répartition qui a tranché
            // son sort, chacun avec sa propre cote, navigables l'un depuis
            // l'autre.
            'courrier_cote_classement' => $this->whenLoaded('courrier', fn () => $this->courrier?->cote_classement),
            'tableau_repartition' => $this->whenLoaded('lignesTableauRepartition', function () {
                $ligne = $this->lignesTableauRepartition->first(fn ($l) => $l->tableau?->statut?->value === 'approuve');

                return $ligne === null ? null : [
                    'id' => $ligne->tableau->id,
                    'cote_classement' => $ligne->tableau->cote_classement,
                ];
            }),

            // Lot B : issue individuelle du dossier après le feu vert.
            'motif_non_retenu' => $this->motif_non_retenu,
            'motif_non_retenu_label' => $this->motif_non_retenu ? (config('stagiaires.motifs_non_retenu')[$this->motif_non_retenu] ?? $this->motif_non_retenu) : null,
            'motif_non_retenu_libre' => $this->motif_non_retenu_libre,
            'non_retenu_at' => $this->non_retenu_at,

            // Détection de doublon : ne bloque jamais, juste un signalement
            // pour que la DFP tranche.
            'doublon_suspecte' => $this->doublon_suspecte,
            'doublon_stagiaire' => $this->when($this->doublon_suspecte && $this->relationLoaded('doublonStagiaire') && $this->doublonStagiaire, fn () => [
                'id' => $this->doublonStagiaire->id,
                'nom' => $this->doublonStagiaire->nom,
                'etablissement_origine' => $this->doublonStagiaire->etablissement_origine,
            ]),

            // Historique des prolongations (stage professionnel) : pas
            // confidentiel, visible par tous ceux qui voient déjà la fiche.
            'prolongations' => $this->whenLoaded('prolongations', fn () => $this->prolongations->map(fn ($p) => [
                'ancienne_date_fin' => $p->ancienne_date_fin->toDateString(),
                'nouvelle_date_fin' => $p->nouvelle_date_fin->toDateString(),
                'motif' => $p->motif,
                'prolonge_par' => $p->relationLoaded('prolongePar') ? $p->prolongePar?->name : null,
                'created_at' => $p->created_at,
            ])->values()),

            'convention' => $this->when($this->convention_chemin !== null, fn () => [
                'genere_at' => $this->convention_genere_at,
                'signee_direction_at' => $this->convention_signee_direction_at,
                'signee_direction_par' => $this->whenLoaded('conventionSigneeDirectionPar', fn () => $this->conventionSigneeDirectionPar?->name),
                'signee_stagiaire_at' => $this->convention_signee_stagiaire_at,
            ]),

            // Liens à usage unique non encore consommés : exposés pour que
            // la direction puisse les retransmettre manuellement si l'envoi
            // par e-mail n'a pas pu être tenté (contact non valide). Ne
            // contient jamais le contenu du retour d'expérience lui-même.
            'liens_publics' => $this->whenLoaded('liensPublics', fn () => $this->liensPublics
                ->whereNull('consomme_at')
                ->map(fn ($lien) => [
                    'type' => $lien->type->value,
                    'token' => $lien->token,
                ])
                ->values()),
        ];
    }
}
