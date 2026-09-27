<?php

namespace Modules\Courrier\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Courrier\Contracts\CircuitTransitionRules;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Enums\NiveauConfidentialite;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;
use Modules\Kernel\Support\DgDisponibilite;

/** Source de vérité unique de la visibilité documentaire interne. */
class CourrierVisibilityService
{
    public function __construct(
        private readonly DelegationResolver $delegations,
        private readonly CircuitTransitionRules $regles,
    ) {}

    /** @param Builder<covariant Courrier> $query */
    public function scopeVisiblePour(Builder $query, User $user): Builder
    {
        if ($this->aVisionInstitutionnelle($user)) {
            return $query;
        }

        $poste = $this->posteEffectif($user);

        return $query->where(function (Builder $visible) use ($user, $poste): void {
            $this->ajouterRelationsNominatives($visible, $user);

            if ($user->role === UserRole::AGENT_DFP) {
                $visible->orWhere('courriers.type', CourrierType::DEMANDE_STAGE);
            }

            if ($user->direction_id !== null && in_array($user->role, [
                UserRole::DIRECTEUR_DIRECTION,
                UserRole::RESPONSABLE_DIRECTION,
                UserRole::SECRETARIAT_DIRECTION,
            ], true)) {
                $this->ajouterRelationsDirectionnelles($visible, $user->direction_id);
            }

            if ($poste !== null && $this->estAssistant($poste)) {
                $visible->orWhereHas('transitions', fn (Builder $transition) => $transition
                    ->where('destinataire_poste', $poste->value)
                    ->whereColumn('courrier_transitions.statut', 'courriers.statut'));
            }

            if ($poste !== null && ! $this->estAssistant($poste)) {
                $this->ajouterRelationsCircuit($visible, $poste);
                $this->ajouterFileCourante($visible, $poste);
            }

            if ($poste === Poste::DGA && ! DgDisponibilite::estDisponible()) {
                $this->ajouterRelationsCircuit($visible, Poste::DG);
            }

            // À l'étape d'avis, le courrier est sous contrôle de la
            // Direction générale. La DGA peut y créer ses propres missions
            // sans pour autant disposer de la vision institutionnelle
            // globale et permanente de la DG.
            if ($poste === Poste::DGA) {
                $visible->orWhere('courriers.statut', 'en_attente_avis_dg');
            }
        });
    }

    public function peutVoir(User $user, Courrier $courrier): bool
    {
        return $this->scopeVisiblePour(
            Courrier::withoutGlobalScopes()->whereKey($courrier->getKey()),
            $user,
        )->exists();
    }

    private function aVisionInstitutionnelle(User $user): bool
    {
        if ($user->role === UserRole::ADMINISTRATEUR || $user->poste === Poste::DG) {
            return true;
        }

        // Lorsque la DG est officiellement indisponible, la DGA exerce
        // son intérim sur le même périmètre documentaire. Ce n'est pas
        // un droit permanent : il disparaît dès le retour de la DG.
        if ($user->poste === Poste::DGA && ! DgDisponibilite::estDisponible()) {
            return true;
        }

        return $this->delegations->posteDelegueAujourdhui($user) === Poste::DG;
    }

    private function posteEffectif(User $user): ?Poste
    {
        return $this->delegations->posteDelegueAujourdhui($user) ?? $user->poste;
    }

    private function estAssistant(?Poste $poste): bool
    {
        return in_array($poste, [Poste::ASSISTANT_1, Poste::ASSISTANT_2, Poste::ASSISTANT_DGA], true);
    }

    /** @param Builder<covariant Courrier> $query */
    private function ajouterRelationsNominatives(Builder $query, User $user): void
    {
        $query->where(function (Builder $creation) use ($user): void {
            $creation->where('courriers.created_by', $user->id)
                // Le créateur d'un D de mission n'en conserve pas un droit
                // perpétuel : l'accès vient de la mission tant qu'elle est
                // active, puis des autres relations métier éventuelles.
                ->whereDoesntHave('missionProjetReponse');
        })
            ->orWhere(function (Builder $relecture) use ($user): void {
                $relecture->where('courriers.relecteur_id', $user->id)
                    ->where(function (Builder $active): void {
                        $active->whereDoesntHave('missionProjetReponse')
                            ->orWhereNull('courriers.relecture_validee_at');
                    });
            })
            ->orWhereHas('missionsDocumentaires', fn (Builder $mission) => $mission
                ->where('assistant_id', $user->id)
                ->whereIn('statut', ['assignee', 'en_cours']))
            ->orWhereHas('missionProjetReponse', fn (Builder $mission) => $mission
                ->where('assistant_id', $user->id)
                ->whereIn('statut', ['assignee', 'en_cours']))
            ->orWhereExists(function ($mission) use ($user): void {
                $mission->selectRaw('1')
                    ->from('missions_documentaires')
                    ->whereColumn('missions_documentaires.dossier_id', 'courriers.dossier_id')
                    ->where('missions_documentaires.assistant_id', $user->id)
                    ->whereIn('missions_documentaires.statut', ['assignee', 'en_cours']);
            })
            ->orWhereHas('transitions', fn (Builder $transition) => $transition
                ->where(fn (Builder $acteur) => $acteur
                    ->where('destinataire_user_id', $user->id)
                    ->orWhere('accuse_reception_par_id', $user->id)))
            ->orWhere(function (Builder $historique) use ($user): void {
                $historique->whereDoesntHave('missionProjetReponse')
                    ->whereHas('transitions', fn (Builder $transition) => $transition->where('changed_by_id', $user->id));
            });
    }

    /** @param Builder<covariant Courrier> $query */
    private function ajouterRelationsDirectionnelles(Builder $query, int $directionId): void
    {
        $query->orWhere(function (Builder $direction) use ($directionId): void {
            $direction->where('courriers.niveau_confidentialite', NiveauConfidentialite::ORDINAIRE)
                ->where(fn (Builder $relation) => $relation
                    ->where('courriers.direction_origine_id', $directionId)
                    ->orWhere('courriers.direction_destination_id', $directionId));
        })->orWhereHas('imputations', fn (Builder $imputation) => $imputation->where('direction_id', $directionId))
            ->orWhereHas('dispatchs', fn (Builder $dispatch) => $dispatch
                ->where('type_destination', 'direction')
                ->where('statut', 'execute')
                ->where('direction_id', $directionId))
            ->orWhereHas('traitementsDirection', fn (Builder $traitement) => $traitement->where('direction_id', $directionId))
            ->orWhereHas('documentProduitDirection', fn (Builder $document) => $document->where('direction_id', $directionId));
    }

    /** @param Builder<covariant Courrier> $query */
    private function ajouterRelationsCircuit(Builder $query, Poste $poste): void
    {
        $query->orWhereHas('transitions', fn (Builder $transition) => $transition
            ->where(fn (Builder $relation) => $relation
                ->where('destinataire_poste', $poste->value)
                ->orWhere('expediteur_poste', $poste->value)));

        if ($poste === Poste::RECEPTION) {
            $query->orWhere(fn (Builder $public) => $public
                ->where('courriers.mode_reception', 'depot_en_ligne')
                ->where('courriers.statut', 'recu'))
                // Les tableaux de répartition sont des courriers
                // synthétiques avec leur propre mini-circuit. Leur statut
                // n'appartient donc pas à CircuitTransitionRules, mais
                // TABLEAU_CHEZ_RECEPTION constitue bien une file explicite
                // de la Réception (soumission initiale ou retour DG).
                ->orWhere(fn (Builder $tableau) => $tableau
                    ->where('courriers.type', CourrierType::TABLEAU_REPARTITION)
                    ->where('courriers.statut', CourrierStatut::TABLEAU_CHEZ_RECEPTION))
                ->orWhereHas('documentProduitDirection', fn (Builder $document) => $document
                    ->whereIn('statut', ['transmis_reception', 'entre_circuit']));
        }

        if ($poste === Poste::SECRETARIAT_2) {
            $query->orWhereHas('dispatchs')
                ->orWhereHas('classement');
        }
    }

    /**
     * Un poste central voit la file sur laquelle il doit actuellement agir,
     * même avant la création du bordereau suivant. Les règles viennent de la
     * même configuration que les transitions, afin d'éviter une seconde
     * matrice divergente.
     *
     * @param  Builder<covariant Courrier>  $query
     */
    private function ajouterFileCourante(Builder $query, Poste $poste): void
    {
        $circuits = [
            ['sortant' => true, 'necessite_avis_dg' => false, 'initie_par_dg' => false],
            ['sortant' => false, 'necessite_avis_dg' => true, 'initie_par_dg' => false],
            ['sortant' => false, 'necessite_avis_dg' => false, 'initie_par_dg' => true],
            ['sortant' => false, 'necessite_avis_dg' => false, 'initie_par_dg' => false],
        ];

        foreach ($circuits as $circuit) {
            $statuts = collect(CourrierStatut::cases())
                ->filter(fn (CourrierStatut $statut) => in_array(
                    $poste,
                    $this->regles->postesAutorises(
                        $statut,
                        $circuit['necessite_avis_dg'],
                        $circuit['initie_par_dg'],
                        $circuit['sortant'],
                    ),
                    true,
                ))
                ->map(fn (CourrierStatut $statut) => $statut->value)
                ->all();

            if ($statuts === []) {
                continue;
            }

            $query->orWhere(function (Builder $file) use ($circuit, $statuts): void {
                $file->whereIn('courriers.statut', $statuts);
                if ($circuit['sortant']) {
                    $file->where('courriers.sens', 'sortant');
                } else {
                    $file->where('courriers.sens', 'entrant')
                        ->where('courriers.necessite_avis_dg', $circuit['necessite_avis_dg'])
                        ->where('courriers.initie_par_dg', $circuit['initie_par_dg']);
                }
            });
        }
    }
}
