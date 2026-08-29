<?php

namespace Modules\Courrier\Policies;

use Modules\Courrier\Contracts\CircuitTransitionRules;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;

class CourrierPolicy
{
    public function __construct(private readonly CircuitTransitionRules $regles) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Courrier $courrier): bool
    {
        return true;
    }

    /**
     * Deux points d'entrée dans le circuit : la réception (courrier externe
     * physique) et une direction elle-même, qui peut initier un courrier à
     * destination d'une autre direction ou de la DG — celui-ci suit ensuite
     * le même circuit normal (recu -> au_protocole -> ...).
     */
    public function create(User $user): bool
    {
        return $user->poste === $this->regles->posteDeCreation()
            || $user->role === UserRole::RESPONSABLE_DIRECTION;
    }

    /**
     * Action sensible : faire avancer un courrier d'un statut vers le
     * suivant. La vérification précise (saut d'étape, poste habilité) est
     * de toute façon effectuée par CourrierCircuitService ; cette policy
     * ne fait qu'un filtre grossier pour les routes/UI.
     */
    public function transmettre(User $user, Courrier $courrier): bool
    {
        return in_array($user->poste, $this->regles->postesAutorises($courrier->statut, $courrier->necessite_avis_dg, $courrier->initie_par_dg), true);
    }

    public function validerRelecture(User $user, Courrier $courrier): bool
    {
        return $courrier->statut === CourrierStatut::EN_RELECTURE && $courrier->relecteur_id === $user->id;
    }

    /**
     * Filtre grossier, comme transmettre()/signer() : le relecteur désigné
     * pour en_relecture, ou un poste habilité pour ce statut sinon. La
     * vérification précise (garde d'intérim DGA comprise) reste dans
     * CourrierCircuitService::accuserReception().
     */
    public function accuserReception(User $user, Courrier $courrier): bool
    {
        if ($courrier->statut === CourrierStatut::EN_RELECTURE) {
            return $courrier->relecteur_id === $user->id;
        }

        return in_array($user->poste, $this->regles->postesAutorises($courrier->statut, $courrier->necessite_avis_dg, $courrier->initie_par_dg), true);
    }

    /**
     * Le Secrétariat 01 initie un courrier au nom de la DG, sans courrier
     * entrant déclencheur — voir CourrierCircuitService::initierParDg().
     */
    public function initierParDg(User $user): bool
    {
        return $user->poste === Poste::SECRETARIAT_1;
    }

    /**
     * Seule la DG peut valider un courrier qu'elle a elle-même initié, avant
     * qu'il ne parte en relecture — le blocage de statut précis reste dans
     * CourrierCircuitService, comme pour signer().
     */
    public function validerAvantDiffusion(User $user, Courrier $courrier): bool
    {
        return $user->poste === Poste::DG;
    }

    /**
     * Action sensible : signer un document. Le blocage effectif tant que
     * la relecture n'est pas validée est appliqué par CourrierCircuitService.
     */
    public function signer(User $user, Courrier $courrier): bool
    {
        return in_array($user->poste, $this->regles->postesAutorises($courrier->statut, $courrier->necessite_avis_dg, $courrier->initie_par_dg), true);
    }

    public function annoter(User $user, Courrier $courrier): bool
    {
        return true;
    }

    /**
     * Tableau de bord du circuit : par nature transverse aux directions,
     * réservé aux postes du circuit courrier et à l'administrateur (la DG
     * y a accès en tant que poste du circuit hiérarchique).
     */
    public function voirStatistiques(User $user): bool
    {
        return $user->role === UserRole::ADMINISTRATEUR || $user->role === UserRole::AGENT_CIRCUIT_COURRIER;
    }

    /**
     * Tableau de bord DG : distinct de voirStatistiques (qui couvre tout le
     * circuit) — réservé au poste DG spécifiquement et à l'administrateur.
     */
    public function voirTableauDeBordDg(User $user): bool
    {
        return $user->role === UserRole::ADMINISTRATEUR
            || ($user->role === UserRole::AGENT_CIRCUIT_COURRIER && $user->poste === Poste::DG);
    }

    /**
     * Tableau de bord d'une direction d'accueil : réservé au responsable
     * d'une direction précise (qui doit alors avoir un direction_id — sans
     * ça rien à afficher), et à la DFP pour une vue organisation entière —
     * un compte DFP n'a structurellement pas de direction_id (voir
     * UserFactory::agentDfp()), donc pas de garde direction_id !== null
     * pour ce rôle : CourrierStatistiqueController::pourDirection()
     * traite déjà ce cas comme "aucun filtre" plutôt que comme "aucun
     * droit". Pas de cas d'usage pour l'administrateur ici (aucune
     * direction propre à afficher), à la différence des autres tableaux
     * de bord transverses.
     */
    public function voirTableauDeBordDirection(User $user): bool
    {
        return $user->role === UserRole::AGENT_DFP
            || ($user->role === UserRole::RESPONSABLE_DIRECTION && $user->direction_id !== null);
    }
}
