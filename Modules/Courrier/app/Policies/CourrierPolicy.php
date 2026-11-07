<?php

namespace Modules\Courrier\Policies;

use Modules\Courrier\Contracts\CircuitTransitionRules;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\ModeSortie;
use Modules\Courrier\Enums\NiveauConfidentialite;
use Modules\Courrier\Enums\SensCourrier;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Services\CourrierVisibilityService;
use Modules\Courrier\Services\CycleDecisionnelService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;
use Modules\Kernel\Support\DgAuthorityResolver;

class CourrierPolicy
{
    public function __construct(
        private readonly CircuitTransitionRules $regles,
        private readonly DelegationResolver $delegations,
        private readonly CourrierVisibilityService $visibility,
        private readonly DgAuthorityResolver $autoriteDg,
    ) {}

    private function estAgentCircuitActif(User $user): bool
    {
        return $user->role === UserRole::AGENT_CIRCUIT_COURRIER
            && ! $user->poste?->estHistorique();
    }

    public function viewAny(User $user): bool
    {
        return ! ($user->role === UserRole::AGENT_CIRCUIT_COURRIER && $user->poste?->estHistorique());
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
        if ($user->role === UserRole::AGENT_CIRCUIT_COURRIER && $user->poste?->estHistorique()) {
            return false;
        }

        return $this->visibility->peutVoir($user, $courrier);
    }

    /**
     * L'administrateur garde la vision institutionnelle de la liste (voir
     * CourrierVisibilityService::aVisionInstitutionnelle()) mais pas du
     * contenu d'un courrier confidentiel ou secret : rôle technique
     * (comptes, structure, paramètres, supervision), pas un rôle métier
     * habilité à lire le fond des dossiers sensibles. Tout autre acteur
     * qui a légitimement accès au courrier (circuit, DG/DGA en autorité
     * institutionnelle) garde l'accès complet au contenu. Voir
     * CourrierResource, qui masque contenu/pièces jointes/numérisations
     * quand cette méthode retourne false.
     *
     * Ne revérifie pas la visibilité de base (contrairement à view()) : le
     * $courrier reçu ici a toujours déjà traversé CourrierDirectionScope,
     * le même filtre, appliqué automatiquement par le modèle lui-même
     * (liaison de route, relations chargées) — la revérifier ici ajouterait
     * une requête de visibilité complète par ligne dans les réponses de
     * liste, pour un résultat déjà garanti.
     */
    public function consulterContenu(User $user, Courrier $courrier): bool
    {
        if ($user->role === UserRole::ADMINISTRATEUR) {
            return $courrier->niveau_confidentialite === NiveauConfidentialite::ORDINAIRE;
        }

        return true;
    }

    /** La Réception est l'unique point d'entrée du courrier entrant. */
    public function create(User $user): bool
    {
        return $user->poste === $this->regles->posteDeCreation();
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

    public function enregistrer(User $user, Courrier $courrier): bool
    {
        if ($courrier->statut === CourrierStatut::RECU && $courrier->mode_reception?->value === 'depot_en_ligne') {
            return $user->poste === Poste::RECEPTION;
        }

        return $this->transmettre($user, $courrier);
    }

    public function transmettreSec1(User $user, Courrier $courrier): bool
    {
        if ($courrier->statut === CourrierStatut::RECU && $courrier->mode_reception?->value === 'depot_en_ligne') {
            return $user->poste === Poste::RECEPTION;
        }

        return $this->transmettre($user, $courrier);
    }

    public function creerMission(User $user, Courrier $courrier): bool
    {
        return in_array($courrier->statut, [CourrierStatut::EN_ATTENTE_AVIS_DG, CourrierStatut::DISPATCH_EXECUTE], true)
            && ($this->autoriteDg->estAutorite($user) || $user->poste === Poste::DGA);
    }

    public function deciderDispatch(User $user, Courrier $courrier): bool
    {
        $statutAutorise = in_array($courrier->statut, [CourrierStatut::EN_ATTENTE_AVIS_DG, CourrierStatut::DISPATCH_EXECUTE, CourrierStatut::ENVOYE], true)
            || ($courrier->statut === CourrierStatut::REMIS
                && in_array($courrier->mode_sortie, [ModeSortie::RETRAIT_PHYSIQUE, ModeSortie::COURRIEL_ET_RETRAIT], true)
                && $courrier->estSortieCompletee());

        return $statutAutorise
            && $this->autoriteDg->estAutorite($user);
    }

    private const STATUTS_ANNULABLES = [
        CourrierStatut::RECU, CourrierStatut::EN_ATTENTE_TRI, CourrierStatut::EN_ATTENTE_CLASSEUR, CourrierStatut::EN_ATTENTE_AVIS_DG,
    ];

    private function peutGererEtat(User $user): bool
    {
        return $this->delegations->utilisateurHabilite($user, [Poste::RECEPTION, Poste::SECRETARIAT_1, Poste::DG, Poste::DGA])
            || $this->autoriteDg->estAutorite($user);
    }

    /** Annulation d'un enregistrement par erreur : tant que le courrier n'a pas été décidé ni dispatché. */
    public function annuler(User $user, Courrier $courrier): bool
    {
        return ! $courrier->estAnnule()
            && $courrier->sens === SensCourrier::ENTRANT
            && $courrier->documentProduitDirection === null
            && in_array($courrier->statut, self::STATUTS_ANNULABLES, true)
            && $this->peutGererEtat($user);
    }

    public function suspendre(User $user, Courrier $courrier): bool
    {
        return ! $courrier->estAnnule() && ! $courrier->estSuspendu()
            && $courrier->documentProduitDirection === null
            && ! in_array($courrier->statut, [CourrierStatut::ENVOYE, CourrierStatut::REMIS, CourrierStatut::DISPONIBLE_RETRAIT, CourrierStatut::DISPATCH_EXECUTE], true)
            && $this->peutGererEtat($user);
    }

    public function reprendreSuspension(User $user, Courrier $courrier): bool
    {
        return ! $courrier->estAnnule() && $courrier->estSuspendu() && $this->peutGererEtat($user);
    }

    /** Mise en copie pour information : même autorité que la décision, tant que le courrier est en circuit. */
    public function ajouterCopies(User $user, Courrier $courrier): bool
    {
        return in_array($courrier->statut, [
            CourrierStatut::EN_ATTENTE_AVIS_DG,
            CourrierStatut::EN_DISPATCH,
            CourrierStatut::DISPATCH_EXECUTE,
            CourrierStatut::ENVOYE,
        ], true) && $this->autoriteDg->estAutorite($user);
    }

    public function deciderClassement(User $user, Courrier $courrier): bool
    {
        if ($courrier->dossier_id === null
            || ! $courrier->estCycleClassementApresSortieCompletee()
            || ! $this->autoriteDg->estAutorite($user)) {
            return false;
        }

        $bordereau = $courrier->bordereauCourant();
        if ($bordereau !== null && $bordereau->destinataire_poste === Poste::DG->value && $bordereau->accuse_reception_at === null) {
            return false;
        }

        return app(CycleDecisionnelService::class)->peutOuvrir($courrier);
    }

    public function validerRelecture(User $user, Courrier $courrier): bool
    {
        return $courrier->enAttenteValidationRelecteur() && $courrier->relecteur_id === $user->id;
    }

    /**
     * Même filtre que validerRelecture() : réservé au relecteur désigné,
     * seul PROJET_A_VALIDER connaît cette transition (voir
     * CourrierCircuitService::renvoyerPourCorrection()).
     */
    public function renvoyerPourCorrection(User $user, Courrier $courrier): bool
    {
        return $courrier->statut === CourrierStatut::PROJET_A_VALIDER && $courrier->relecteur_id === $user->id;
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
     * Filtre grossier, comme requalifierUrgence() — la garde d'intérim
     * dynamique (DGA seulement si la DG est indisponible) reste dans
     * CourrierCircuitService::renvoyerAuTri().
     */
    public function renvoyerAuTri(User $user, Courrier $courrier): bool
    {
        return $user->poste === Poste::DG
            || $user->poste === Poste::DGA
            || $this->delegations->utilisateurHabilite($user, [Poste::DG]);
    }

    /**
     * Lot C, point 3, revu au lot 2 (proposition G) : taux global pour le Secrétariat 01 qui
     * trie, détail par agent pour la DG seule. L'administrateur n'y a plus accès.
     */
    public function voirJustesseTri(User $user): bool
    {
        return $this->voirJustesseTriParAgent($user)
            || ($this->estAgentCircuitActif($user) && $this->delegations->utilisateurHabilite($user, [Poste::SECRETARIAT_1]));
    }

    /**
     * Filtre grossier, comme transmettre()/signer() : le relecteur désigné
     * pour en_relecture, ou un poste habilité pour ce statut sinon. La
     * vérification précise (garde d'intérim DGA comprise) reste dans
     * CourrierCircuitService::accuserReception().
     */
    public function accuserReception(User $user, Courrier $courrier): bool
    {
        if ($courrier->statut === CourrierStatut::EN_DISPATCH
            && $courrier->bordereauCourant()?->destinataire_poste === Poste::SECRETARIAT_2->value) {
            return $this->delegations->utilisateurHabilite($user, [Poste::SECRETARIAT_2]);
        }
        if ($courrier->enAttenteValidationRelecteur()) {
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
        return $this->delegations->utilisateurHabilite($user, [Poste::SECRETARIAT_1]);
    }

    /**
     * Seule la DG peut valider un courrier qu'elle a elle-même initié, avant
     * qu'il ne parte en relecture — le blocage de statut précis reste dans
     * CourrierCircuitService, comme pour signer().
     */
    public function validerAvantDiffusion(User $user, Courrier $courrier): bool
    {
        return $this->autoriteDg->estAutorite($user);
    }

    /**
     * Action sensible : signer un document. Le blocage effectif tant que
     * la relecture n'est pas validée est appliqué par CourrierCircuitService.
     */
    public function signer(User $user, Courrier $courrier): bool
    {
        return $this->autoriteDg->estAutorite($user)
            && $courrier->statut === CourrierStatut::EN_RELECTURE;
    }

    public function validerPourSignature(User $user, Courrier $courrier): bool
    {
        return $this->autoriteDg->estAutorite($user)
            && $courrier->statut === CourrierStatut::PROJET_A_VALIDER;
    }

    public function telechargerPdfASigner(User $user, Courrier $courrier): bool
    {
        return $courrier->statut === CourrierStatut::EN_ATTENTE_SIGNATURE
            && ($courrier->valide_signature_par_id === $user->id || $this->autoriteDg->estAutorite($user))
            && $this->view($user, $courrier);
    }

    public function televerserScanSigne(User $user, Courrier $courrier): bool
    {
        return $courrier->statut === CourrierStatut::EN_ATTENTE_SIGNATURE
            && $this->autoriteDg->estAutoritePourTeleverserScanSigne($user)
            && $this->view($user, $courrier);
    }

    public function confierAuDga(User $user, Courrier $courrier): bool
    {
        return $courrier->statut === CourrierStatut::EN_ATTENTE_AVIS_DG
            && $user->poste === Poste::DG
            && $this->autoriteDg->source($user) === 'titulaire'
            && $courrier->confiageDgaEnCours() === null;
    }

    public function reprendreDuDga(User $user, Courrier $courrier): bool
    {
        return $courrier->statut === CourrierStatut::EN_ATTENTE_AVIS_DG
            && $user->poste === Poste::DG
            && $this->autoriteDg->source($user) === 'titulaire'
            && $courrier->confiageDgaEnCours() !== null;
    }

    public function retournerAuDg(User $user, Courrier $courrier): bool
    {
        return $courrier->statut === CourrierStatut::EN_ATTENTE_AVIS_DG
            && $user->poste === Poste::DGA
            && $courrier->confiageDgaEnCours() !== null;
    }

    public function annoter(User $user, Courrier $courrier): bool
    {
        if (! $this->view($user, $courrier)) {
            return false;
        }

        if (($this->delegations->posteDelegueAujourdhui($user) ?? $user->poste) === Poste::ASSISTANT_DGA) {
            return false;
        }

        if (($this->delegations->posteDelegueAujourdhui($user) ?? $user->poste) === Poste::SECRETARIAT_2) {
            return $this->delegations->utilisateurHabilite($user, [Poste::DG, Poste::DGA]);
        }

        if ($user->poste !== Poste::SECRETARIAT_1) {
            return true;
        }

        if ($this->delegations->utilisateurHabilite($user, [Poste::DG, Poste::DGA])) {
            return true;
        }

        if ($courrier->relecteur_id === $user->id) {
            return true;
        }

        return $courrier->missionsDocumentaires()
            ->where('assistant_id', $user->id)
            ->whereIn('statut', ['assignee', 'en_cours'])
            ->exists();
    }

    /**
     * Qui impute un courrier vers une ou plusieurs directions — restreint
     * aux postes du circuit central et à l'administrateur, comme
     * voirStatistiques(). Périmètre volontairement large en attendant
     * confirmation (voir docs/questions-ont.md) — à restreindre si la
     * DFP ou la DG précise que ce doit être un poste unique.
     */
    public function imputer(User $user, Courrier $courrier): bool
    {
        return $this->delegations->utilisateurHabilite($user, [Poste::DG, Poste::DGA]);
    }

    /**
     * Filtre grossier, comme transmettre() : le responsable d'une direction
     * concernée par l'original, ou secretariat_1 (qui rédige déjà les
     * projets de réponse aujourd'hui). Vérification précise (laquelle des
     * deux directions de l'original) dans le service.
     */
    public function initierReponse(User $user, Courrier $courrier): bool
    {
        return $user->role->estDirecteurDirection();
    }

    public function envoyer(User $user, Courrier $courrier): bool
    {
        $postesAutorises = $this->regles->postesAutorises($courrier->statut, $courrier->necessite_avis_dg, $courrier->initie_par_dg, true);

        return $this->delegations->utilisateurHabilite($user, $postesAutorises);
    }

    public function choisirModeSortie(User $user, Courrier $courrier): bool
    {
        return $this->peutExecuterSortieSec2($user, $courrier)
            && $courrier->statut === CourrierStatut::SIGNE;
    }

    public function envoyerParCourriel(User $user, Courrier $courrier): bool
    {
        return $this->peutExecuterSortieSec2($user, $courrier);
    }

    public function rendreDisponiblePourRetrait(User $user, Courrier $courrier): bool
    {
        return $this->peutExecuterSortieSec2($user, $courrier);
    }

    public function confirmerRemisePhysique(User $user, Courrier $courrier): bool
    {
        return $this->peutExecuterSortieSec2($user, $courrier);
    }

    private function peutExecuterSortieSec2(User $user, Courrier $courrier): bool
    {
        return $this->view($user, $courrier)
            && $this->delegations->utilisateurHabilite($user, [Poste::SECRETARIAT_2]);
    }

    public function voirFileSortiesSec2(User $user): bool
    {
        return $this->delegations->utilisateurHabilite($user, [Poste::SECRETARIAT_2]);
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
        return $this->gereLeCircuit($user);
    }

    /**
     * Matrice d'accès validée (lot 2, propositions A à C) : statistiques, registre et originaux
     * réservés aux postes qui gèrent le circuit (Réception, SEC1, SEC2) et à la DG. Le DGA n'y a
     * accès qu'en intérim (autorité DG) ou par délégation officielle d'un de ces postes. Les
     * assistants et l'administrateur (rôle technique) n'y ont plus accès.
     */
    private function gereLeCircuit(User $user): bool
    {
        return $this->estAgentCircuitActif($user)
            && ($user->poste === Poste::DG
                || $this->autoriteDg->estAutorite($user)
                || $this->delegations->utilisateurHabilite($user, [Poste::RECEPTION, Poste::SECRETARIAT_1, Poste::SECRETARIAT_2]));
    }

    /**
     * Documents de masse (registre, export, archive) : l'objet d'un courrier confidentiel ou secret
     * n'y figure que pour la DG (ou son intérim) et SEC2 (proposition E et I du lot 2).
     */
    public function voirObjetConfidentielEnMasse(User $user, Courrier $courrier): bool
    {
        if ($courrier->niveau_confidentialite === NiveauConfidentialite::ORDINAIRE) {
            return true;
        }

        return $this->estAgentCircuitActif($user)
            && ($user->poste === Poste::DG
                || $this->autoriteDg->estAutorite($user)
                || $this->delegations->utilisateurHabilite($user, [Poste::SECRETARIAT_2]));
    }

    /** Détail de la justesse du tri par agent : réservé à la DG (proposition G du lot 2). */
    public function voirJustesseTriParAgent(User $user): bool
    {
        return $this->estAgentCircuitActif($user)
            && ($user->poste === Poste::DG || $this->autoriteDg->estAutorite($user));
    }

    /**
     * Le registre PDF est un document opposable (celui que le Secrétariat
     * Général signe, que la tutelle réclame) : même périmètre que
     * voirStatistiques en attendant confirmation (voir docs/questions-ont.md
     * — qui doit réellement avoir accès au registre).
     */
    public function voirRegistre(User $user): bool
    {
        return $this->gereLeCircuit($user);
    }

    /**
     * Tableau de bord DG : distinct de voirStatistiques (qui couvre tout le
     * circuit) — réservé au poste DG spécifiquement et à l'administrateur.
     */
    public function voirTableauDeBordDg(User $user): bool
    {
        return $this->autoriteDg->estAutorite($user);
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
            || ($user->role->estDirecteurDirection() && $user->direction_id !== null);
    }
}
