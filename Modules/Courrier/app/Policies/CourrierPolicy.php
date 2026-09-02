<?php

namespace Modules\Courrier\Policies;

use Modules\Courrier\Contracts\CircuitTransitionRules;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\NiveauConfidentialite;
use Modules\Courrier\Enums\SensCourrier;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;

class CourrierPolicy
{
    public function __construct(
        private readonly CircuitTransitionRules $regles,
        private readonly DelegationResolver $delegations,
    ) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Un courrier ordinaire reste visible dès lors que le
     * CourrierDirectionScope l'a laissé passer (c'est déjà le vrai filtre,
     * voir la docblock de la classe). Confidentiel/secret ajoute une garde
     * réelle : restreint aux postes du circuit central, à l'administrateur,
     * et à la direction imputée (principale ou en copie) — pas à
     * n'importe quelle direction qui aurait un lien historique via
     * direction_origine_id/direction_destination_id sans être imputée.
     * Périmètre exact à confirmer, voir docs/questions-ont.md.
     */
    public function view(User $user, Courrier $courrier): bool
    {
        if ($courrier->niveau_confidentialite === NiveauConfidentialite::ORDINAIRE) {
            return true;
        }

        if ($user->role === UserRole::ADMINISTRATEUR || $user->role === UserRole::AGENT_CIRCUIT_COURRIER) {
            return true;
        }

        return $user->direction_id !== null && $courrier->imputations->contains('direction_id', $user->direction_id);
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
        $estSortant = $courrier->sens === SensCourrier::SORTANT;
        $postesAutorises = $this->regles->postesAutorises($courrier->statut, $courrier->necessite_avis_dg, $courrier->initie_par_dg, $estSortant);

        return $this->delegations->utilisateurHabilite($user, $postesAutorises);
    }

    public function validerRelecture(User $user, Courrier $courrier): bool
    {
        return $courrier->statut === CourrierStatut::EN_RELECTURE && $courrier->relecteur_id === $user->id;
    }

    /**
     * Réservé à la DG, et à elle seule — jamais la DGA, même en intérim
     * (voir CourrierCircuitService::requalifierUrgence()) : corriger le
     * degré d'urgence est une décision de fond, pas une action du circuit
     * standard soumise à la garde d'intérim habituelle.
     */
    public function requalifierUrgence(User $user, Courrier $courrier): bool
    {
        return $user->poste === Poste::DG;
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

        $estSortant = $courrier->sens === SensCourrier::SORTANT;
        $postesAutorises = $this->regles->postesAutorises($courrier->statut, $courrier->necessite_avis_dg, $courrier->initie_par_dg, $estSortant);

        return $this->delegations->utilisateurHabilite($user, $postesAutorises);
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
        $estSortant = $courrier->sens === SensCourrier::SORTANT;
        $postesAutorises = $this->regles->postesAutorises($courrier->statut, $courrier->necessite_avis_dg, $courrier->initie_par_dg, $estSortant);

        return $this->delegations->utilisateurHabilite($user, $postesAutorises);
    }

    public function annoter(User $user, Courrier $courrier): bool
    {
        return true;
    }

    /**
     * Qui impute un courrier vers une ou plusieurs directions — restreint
     * aux postes du circuit central et à l'administrateur, comme
     * voirStatistiques(). Périmètre volontairement large en attendant
     * confirmation (voir docs/questions-ont.md) : dans la pratique décrite,
     * c'est la DG qui impute, mais rien n'empêche le Protocole de le faire
     * en amont — à restreindre si la DFP/le Secrétariat Général précise
     * que ce doit être un poste unique.
     */
    public function imputer(User $user, Courrier $courrier): bool
    {
        return $user->role === UserRole::ADMINISTRATEUR || $user->role === UserRole::AGENT_CIRCUIT_COURRIER;
    }

    /**
     * Filtre grossier, comme transmettre() : le responsable d'une direction
     * concernée par l'original, ou secretariat_1 (qui rédige déjà les
     * projets de réponse aujourd'hui). Vérification précise (laquelle des
     * deux directions de l'original) dans le service.
     */
    public function initierReponse(User $user, Courrier $courrier): bool
    {
        return $user->role === UserRole::RESPONSABLE_DIRECTION || $user->poste === Poste::SECRETARIAT_1;
    }

    public function envoyer(User $user, Courrier $courrier): bool
    {
        $postesAutorises = $this->regles->postesAutorises($courrier->statut, $courrier->necessite_avis_dg, $courrier->initie_par_dg, true);

        return $this->delegations->utilisateurHabilite($user, $postesAutorises);
    }

    public function enregistrerRemise(User $user, Courrier $courrier): bool
    {
        return $user->poste === Poste::SECRETARIAT_2 || $user->role === UserRole::ADMINISTRATEUR;
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
     * Le registre PDF est un document opposable (celui que le Secrétariat
     * Général signe, que la tutelle réclame) : même périmètre que
     * voirStatistiques en attendant confirmation (voir docs/questions-ont.md
     * — qui doit réellement avoir accès au registre).
     */
    public function voirRegistre(User $user): bool
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
