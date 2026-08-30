<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\DB;
use Modules\Courrier\Contracts\CircuitTransitionRules;
use Modules\Courrier\Contracts\CourrierPdfGenerator;
use Modules\Courrier\Contracts\NumeroGenerator;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierClassification;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Enums\ModeReception;
use Modules\Courrier\Events\CourrierStageAvisFavorable;
use Modules\Courrier\Exceptions\RelectureNonValideeException;
use Modules\Courrier\Exceptions\TransitionNonAutoriseeException;
use Modules\Courrier\Mail\AccuseReceptionCandidatMail;
use Modules\Courrier\Mail\AccuseReceptionCourrierExterneMail;
use Modules\Courrier\Mail\CourrierRecuMail;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierAnnotation;
use Modules\Courrier\Models\CourrierTransition;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DgDisponibilite;

/**
 * Orchestre les transitions du circuit courrier : c'est l'unique point
 * d'entrée qui vérifie l'ordre strict des statuts et l'habilitation du
 * poste de l'utilisateur, en s'appuyant sur CircuitTransitionRules
 * (point d'extension) plutôt que sur une logique codée en dur.
 */
class CourrierCircuitService
{
    public function __construct(
        private readonly CircuitTransitionRules $regles,
        private readonly NumeroGenerator $numeros,
        private readonly CourrierPdfGenerator $pdf,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    public function creer(User $auteur, array $donnees): Courrier
    {
        $estReception = $auteur->poste === $this->regles->posteDeCreation();
        $estDirection = $auteur->role === UserRole::RESPONSABLE_DIRECTION;

        if (! $estReception && ! $estDirection) {
            throw TransitionNonAutoriseeException::posteNonHabilite();
        }

        // Une direction ne peut initier un courrier qu'en son propre nom :
        // la direction d'origine est forcée à la sienne, jamais choisie.
        if ($estDirection) {
            $donnees['direction_origine_id'] = $auteur->direction_id;
        }

        // Circuit déterminé automatiquement, jamais choisi par l'agent :
        // seul un courrier initié par une direction, à destination d'une
        // autre direction précise (pas la DG), et qui n'est pas une demande
        // de stage, suit le circuit court. Tout le reste (mail externe
        // trié par la Réception, courrier à destination de la DG, demande
        // de stage qui exige structurellement son avis) suit le circuit
        // complet.
        $estCircuitCourt = $estDirection
            && ! empty($donnees['direction_destination_id'])
            && $donnees['type'] !== CourrierType::DEMANDE_STAGE->value;

        $courrier = DB::transaction(function () use ($auteur, $donnees, $estCircuitCourt) {
            $courrier = Courrier::query()->create([
                ...$donnees,
                'numero_accuse_reception' => $this->numeros->genererAccuseReception(),
                'statut' => CourrierStatut::RECU,
                'necessite_avis_dg' => ! $estCircuitCourt,
                'initie_par_dg' => false,
                'created_by' => $auteur->id,
            ]);

            $this->tracerTransition($courrier, $auteur);

            return $courrier;
        });

        // Notification envoyée après commit : jamais de mail annonçant un
        // courrier dont l'écriture aurait finalement été annulée.
        if ($courrier->direction_destination_id) {
            $responsables = User::query()
                ->where('direction_id', $courrier->direction_destination_id)
                ->where('role', UserRole::RESPONSABLE_DIRECTION)
                ->get();

            foreach ($responsables as $responsable) {
                $this->notifications->envoyerMail($responsable->email, new CourrierRecuMail($courrier));
            }
        }

        return $courrier;
    }

    /**
     * Point d'entrée public (module Public, sans authentification) : un
     * candidat externe dépose sa demande de stage en ligne. Contrairement à
     * creer(), aucun agent ONT n'est à l'origine de la création
     * (created_by reste nul) et aucune direction n'est encore assignée —
     * le courrier suit ensuite le circuit normal à partir de la Réception.
     */
    public function creerDepuisPublic(array $donnees): Courrier
    {
        $courrier = DB::transaction(function () use ($donnees) {
            $courrier = Courrier::query()->create([
                ...$donnees,
                'type' => CourrierType::DEMANDE_STAGE,
                'numero_accuse_reception' => $this->numeros->genererAccuseReception(),
                'statut' => CourrierStatut::RECU,
                // Une demande de stage exige structurellement l'avis de la DG :
                // toujours le circuit complet, jamais le circuit court.
                'necessite_avis_dg' => true,
                'initie_par_dg' => false,
                'created_by' => null,
                // Le mode de réception d'un dépôt via le portail public
                // n'est jamais laissé au choix du candidat : c'est un fait
                // déterminé par le canal d'entrée, pas une déclaration.
                'mode_reception' => ModeReception::DEPOT_EN_LIGNE,
            ]);

            $this->tracerTransition($courrier, null);

            return $courrier;
        });

        if ($courrier->candidat_email) {
            $this->notifications->envoyerMail($courrier->candidat_email, new AccuseReceptionCandidatMail($courrier));
        }

        return $courrier;
    }

    /**
     * Dépôt public d'un courrier externe (partenaire, sans compte) : même
     * traitement que creerDepuisPublic(), mais type=correspondance_generale
     * et pas de candidat — un expéditeur externe. Circuit complet
     * obligatoire (necessite_avis_dg=true) : un dépôt externe non trié par
     * la Réception ne peut pas emprunter le circuit court, réservé aux
     * échanges direction-à-direction.
     */
    public function creerCourrierExterneDepuisPublic(array $donnees): Courrier
    {
        $courrier = DB::transaction(function () use ($donnees) {
            $courrier = Courrier::query()->create([
                ...$donnees,
                'type' => CourrierType::CORRESPONDANCE_GENERALE,
                'numero_accuse_reception' => $this->numeros->genererAccuseReception(),
                'statut' => CourrierStatut::RECU,
                'necessite_avis_dg' => true,
                'initie_par_dg' => false,
                'created_by' => null,
                'mode_reception' => ModeReception::DEPOT_EN_LIGNE,
            ]);

            $this->tracerTransition($courrier, null);

            return $courrier;
        });

        if ($courrier->expediteur_externe_email) {
            $this->notifications->envoyerMail($courrier->expediteur_externe_email, new AccuseReceptionCourrierExterneMail($courrier));
        }

        return $courrier;
    }

    /**
     * Courrier sortant initié par la DG elle-même (instruction, note de
     * service), sans courrier entrant déclencheur — voir la section
     * 'dg_initie' de config('courrier.circuit_transitions'). Contrairement
     * à soumettreProjetReponse() (étape séparée sur un courrier déjà
     * existant), tout est capturé en une seule action : le formulaire du
     * Secrétariat 01 réunit à la fois la création (objet, direction
     * destinataire) et la rédaction (contenu, relecteur).
     */
    public function initierParDg(User $secretariat1, array $donnees): Courrier
    {
        if ($secretariat1->poste !== Poste::SECRETARIAT_1) {
            throw TransitionNonAutoriseeException::posteNonHabilite();
        }

        $validationRequise = (bool) ($donnees['validation_dg_requise'] ?? false);

        $courrier = DB::transaction(function () use ($secretariat1, $donnees, $validationRequise) {
            $courrier = Courrier::query()->create([
                'objet' => $donnees['objet'],
                'type' => CourrierType::CORRESPONDANCE_GENERALE,
                'direction_destination_id' => $donnees['direction_destination_id'],
                'direction_origine_id' => null,
                'numero_accuse_reception' => $this->numeros->genererAccuseReception(),
                'statut' => CourrierStatut::RECU,
                'necessite_avis_dg' => false,
                'initie_par_dg' => true,
                'validation_dg_requise' => $validationRequise,
                'piece_jointe_chemin' => $donnees['piece_jointe_chemin'] ?? null,
                'created_by' => $secretariat1->id,
            ]);

            $this->tracerTransition($courrier, $secretariat1);

            $courrier->projet_reponse_contenu = $donnees['projet_reponse_contenu'];
            $courrier->relecteur_id = $donnees['relecteur_id'];
            $courrier->statut = $validationRequise ? CourrierStatut::EN_ATTENTE_VALIDATION_DG : CourrierStatut::EN_RELECTURE;
            $courrier->save();
            $this->tracerTransition($courrier, $secretariat1);

            $this->audit->enregistrer('courrier.initie_par_dg', $courrier, $secretariat1, [
                'validation_dg_requise' => $validationRequise,
            ]);

            return $courrier;
        });

        $responsables = User::query()
            ->where('direction_id', $courrier->direction_destination_id)
            ->where('role', UserRole::RESPONSABLE_DIRECTION)
            ->get();

        foreach ($responsables as $responsable) {
            $this->notifications->envoyerMail($responsable->email, new CourrierRecuMail($courrier));
        }

        return $courrier;
    }

    /**
     * Réservée aux courriers initiés par la DG dont le rédacteur a jugé
     * nécessaire l'aval formel de la DG avant diffusion (case à cocher du
     * formulaire, voir initierParDg()) : débloque la relecture, qui ne
     * peut commencer avant.
     */
    public function validerAvantDiffusion(Courrier $courrier, User $dg): Courrier
    {
        return DB::transaction(function () use ($courrier, $dg) {
            $courrier = $this->lockCourrierFrais($courrier);
            $this->assertTransitionAutorisee($courrier, $dg, CourrierStatut::EN_RELECTURE);

            $courrier->valide_par_dg_at = now();
            $courrier->statut = CourrierStatut::EN_RELECTURE;
            $courrier->save();
            $this->tracerTransition($courrier, $dg);

            $this->audit->enregistrer('courrier.valide_par_dg', $courrier, $dg);

            return $courrier;
        });
    }

    /**
     * Trace chaque changement de statut : alimente le calcul du temps moyen
     * de traitement par étape du circuit (dashboard courrier). $utilisateur
     * est nul uniquement pour un dépôt public (aucun agent à l'origine).
     */
    /**
     * Chaque transition EST un bordereau de transmission (voir
     * CourrierTransition) : le destinataire est calculé ici, dérivé de qui
     * est habilité à agir depuis ce nouveau statut — sauf pour en_relecture,
     * dont le destinataire est la personne précise désignée comme
     * relecteur, jamais un poste générique. Un statut sans suite possible
     * (terminal, ou sans entrée dans l'arbre de circuit — le "recu"
     * intermédiaire de initierParDg()) n'a pas de destinataire : rien à
     * décharger.
     */
    private function tracerTransition(Courrier $courrier, ?User $utilisateur): void
    {
        $destinatairePoste = null;
        $destinataireUserId = null;

        if ($courrier->statut === CourrierStatut::EN_RELECTURE) {
            $destinataireUserId = $courrier->relecteur_id;
        } else {
            $postesAutorises = $this->regles->postesAutorises($courrier->statut, $courrier->necessite_avis_dg, $courrier->initie_par_dg);
            $destinatairePoste = ($postesAutorises[0] ?? null)?->value;
        }

        CourrierTransition::query()->create([
            'courrier_id' => $courrier->id,
            'statut' => $courrier->statut,
            'changed_by_id' => $utilisateur?->id,
            'destinataire_poste' => $destinatairePoste,
            'destinataire_user_id' => $destinataireUserId,
            'created_at' => now(),
        ]);
    }

    /**
     * Bloque toute action tant que le bordereau qui a amené le courrier à
     * son statut actuel n'a pas été acquitté par son destinataire — voir
     * accuserReception(). Seule assertTransitionAutorisee() et
     * validerRelecture() (qui ne passe pas par elle, car elle ne change pas
     * le statut) l'appellent : ce sont les deux seuls points d'entrée où un
     * utilisateur agit sur un dossier qui vient de lui être transmis.
     */
    /**
     * Recharge le courrier avec un verrou de ligne, à appeler en tout
     * premier à l'intérieur d'une DB::transaction() : si deux requêtes
     * tentent la même transition au même instant, la seconde attend que la
     * première committe puis relit l'état réel avant de revalider — jamais
     * un statut déjà périmé porté par l'instance reçue en paramètre de la
     * méthode publique. Même pattern que
     * StagiaireCircuitService::lockStagiaireFrais().
     */
    private function lockCourrierFrais(Courrier $courrier): Courrier
    {
        return Courrier::query()->lockForUpdate()->findOrFail($courrier->id);
    }

    /**
     * Règle de cotation provisoire, voir docs/questions-ont.md : la règle
     * réelle du classement physique reste à confirmer avec le Secrétariat
     * Général. "ONT" en repli quand aucune direction n'est imputée
     * (courrier interne à la DG, par exemple) — jamais une cote vide.
     */
    private function genererCoteClassement(Courrier $courrier): string
    {
        $codeDirection = $courrier->directionPrincipale()?->direction?->code ?? 'ONT';
        [$annee, $sequence] = array_pad(explode('-', (string) $courrier->numero_enregistrement, 2), 2, '0000');

        return strtr(config('courrier.format_cote_classement', '{direction}-{annee}-{sequence}'), [
            '{direction}' => $codeDirection,
            '{annee}' => $annee,
            '{sequence}' => $sequence,
        ]);
    }

    private function assertDechargeDonnee(Courrier $courrier): void
    {
        if ($courrier->enTransit()) {
            throw TransitionNonAutoriseeException::dechargeNonDonnee();
        }
    }

    private function assertTransitionAutorisee(Courrier $courrier, User $utilisateur, CourrierStatut $statutCible): void
    {
        $this->assertDechargeDonnee($courrier);

        $statutAttendu = $this->regles->statutSuivant($courrier->statut, $courrier->necessite_avis_dg, $courrier->initie_par_dg);

        if ($statutAttendu === null || $statutAttendu !== $statutCible) {
            throw TransitionNonAutoriseeException::sautDetape();
        }

        $postesAutorises = $this->regles->postesAutorises($courrier->statut, $courrier->necessite_avis_dg, $courrier->initie_par_dg);

        if ($utilisateur->poste === null || ! in_array($utilisateur->poste, $postesAutorises, true)) {
            throw TransitionNonAutoriseeException::posteNonHabilite();
        }
    }

    /**
     * Décharge explicite du destinataire d'un bordereau, préalable
     * obligatoire à toute action sur le dossier (voir assertDechargeDonnee()).
     * L'habilitation ici est volontairement recalculée à la volée (pas
     * fondée uniquement sur le destinataire_poste déjà enregistré sur le
     * bordereau) pour rester alignée avec l'action de circuit qu'elle
     * débloque, garde d'intérim de la DGA comprise.
     */
    public function accuserReception(Courrier $courrier, User $utilisateur): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur) {
            $courrier = $this->lockCourrierFrais($courrier);

            $bordereau = $courrier->bordereauCourant();

            if ($bordereau === null) {
                throw TransitionNonAutoriseeException::sautDetape();
            }

            if ($bordereau->accuse_reception_at !== null) {
                throw TransitionNonAutoriseeException::dechargeDejaDonnee();
            }

            if ($courrier->statut === CourrierStatut::EN_RELECTURE) {
                if ($courrier->relecteur_id !== $utilisateur->id) {
                    throw TransitionNonAutoriseeException::posteNonHabilite();
                }
            } else {
                $postesAutorises = $this->regles->postesAutorises($courrier->statut, $courrier->necessite_avis_dg, $courrier->initie_par_dg);
                $enInterim = $utilisateur->poste === Poste::DGA;

                if ($utilisateur->poste === null || ! in_array($utilisateur->poste, $postesAutorises, true)) {
                    throw TransitionNonAutoriseeException::posteNonHabilite();
                }

                if ($enInterim && DgDisponibilite::estDisponible()) {
                    throw TransitionNonAutoriseeException::posteNonHabilite();
                }
            }

            $bordereau->accuse_reception_par_id = $utilisateur->id;
            $bordereau->accuse_reception_at = now();
            $bordereau->save();

            $this->audit->enregistrer('courrier.accuse_reception', $courrier, $utilisateur, [
                'statut' => $courrier->statut->value,
            ]);

            return $courrier;
        });
    }

    public function transmettreAuProtocole(Courrier $courrier, User $utilisateur): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur) {
            $courrier = $this->lockCourrierFrais($courrier);
            $this->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::AU_PROTOCOLE);

            $courrier->statut = CourrierStatut::AU_PROTOCOLE;
            $courrier->save();
            $this->tracerTransition($courrier, $utilisateur);

            return $courrier;
        });
    }

    public function transmettreEnAttenteAvisDg(Courrier $courrier, User $utilisateur): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur) {
            $courrier = $this->lockCourrierFrais($courrier);
            $this->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::EN_ATTENTE_AVIS_DG);

            $courrier->statut = CourrierStatut::EN_ATTENTE_AVIS_DG;
            $courrier->save();
            $this->tracerTransition($courrier, $utilisateur);

            return $courrier;
        });
    }

    /**
     * La DGA figure parmi les postes structurellement habilités pour cette
     * étape (voir config('courrier.circuit_transitions')), mais ne peut
     * réellement rendre l'avis que lorsque la DG est marquée indisponible
     * (intérim, voir DgDisponibilite) — la DG, elle, n'est jamais bloquée
     * par ce drapeau. Ce n'est pas une règle de circuit statique (elle
     * dépend d'un état mutable), donc pas encodée dans la table de
     * transitions elle-même.
     */
    public function rendreAvisDg(Courrier $courrier, User $utilisateur, AvisDg $avis, ?string $commentaire): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $avis, $commentaire) {
            $courrier = $this->lockCourrierFrais($courrier);
            $this->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::PROJET_REPONSE_EN_COURS);

            $enInterim = $utilisateur->poste === Poste::DGA;

            if ($enInterim && DgDisponibilite::estDisponible()) {
                throw TransitionNonAutoriseeException::posteNonHabilite();
            }

            $courrier->avis_dg = $avis;
            $courrier->avis_dg_commentaire = $commentaire;
            $courrier->avis_dg_rendu_at = now();
            $courrier->avis_dg_rendu_par_id = $utilisateur->id;
            $courrier->avis_dg_rendu_en_interim = $enInterim;
            $courrier->statut = CourrierStatut::PROJET_REPONSE_EN_COURS;
            $courrier->save();
            $this->tracerTransition($courrier, $utilisateur);

            $this->audit->enregistrer('courrier.avis_dg_rendu', $courrier, $utilisateur, [
                'avis' => $avis->value,
                'interim' => $enInterim,
            ]);

            // Après commit uniquement : la fiche stagiaire créée en
            // réaction à cet événement ne doit jamais exister pour un avis
            // finalement annulé par un rollback (ex. verrou expiré, échec
            // d'assertion concurrente).
            if ($avis === AvisDg::FAVORABLE && $courrier->type === CourrierType::DEMANDE_STAGE) {
                DB::afterCommit(fn () => CourrierStageAvisFavorable::dispatch($courrier));
            }

            return $courrier;
        });
    }

    public function soumettreProjetReponse(Courrier $courrier, User $utilisateur, array $projetReponseContenu, int $relecteurId): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $projetReponseContenu, $relecteurId) {
            $courrier = $this->lockCourrierFrais($courrier);
            $this->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::EN_RELECTURE);

            $courrier->projet_reponse_contenu = $projetReponseContenu;
            $courrier->relecteur_id = $relecteurId;
            $courrier->relecture_validee_at = null;
            $courrier->statut = CourrierStatut::EN_RELECTURE;
            $courrier->save();
            $this->tracerTransition($courrier, $utilisateur);

            return $courrier;
        });
    }

    public function validerRelecture(Courrier $courrier, User $utilisateur, ?string $commentaire): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $commentaire) {
            $courrier = $this->lockCourrierFrais($courrier);

            if ($courrier->statut !== CourrierStatut::EN_RELECTURE) {
                throw TransitionNonAutoriseeException::sautDetape();
            }

            if ($courrier->relecteur_id !== $utilisateur->id) {
                throw TransitionNonAutoriseeException::posteNonHabilite();
            }

            $this->assertDechargeDonnee($courrier);

            $courrier->relecture_validee_at = now();
            $courrier->relecture_commentaire = $commentaire;
            $courrier->save();

            return $courrier;
        });
    }

    public function signer(Courrier $courrier, User $utilisateur): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur) {
            $courrier = $this->lockCourrierFrais($courrier);
            $this->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::SIGNE);

            if (! $courrier->relectureEstValidee()) {
                throw new RelectureNonValideeException;
            }

            $courrier->signataire_id = $utilisateur->id;
            $courrier->signe_at = now();
            $courrier->statut = CourrierStatut::SIGNE;

            // PDF définitif généré exactement ici, jamais avant (le contenu du
            // projet de réponse reste modifiable jusqu'à cet instant précis) et
            // jamais régénéré ensuite : posé dans la même sauvegarde que la
            // transition elle-même, pour qu'il n'existe jamais d'état
            // "signé sans PDF".
            $courrier->pdf_chemin = $this->pdf->generer($courrier);
            $courrier->save();
            $this->tracerTransition($courrier, $utilisateur);

            $this->audit->enregistrer('courrier.signature', $courrier, $utilisateur, [
                'description' => "Signature du courrier {$courrier->numero_accuse_reception}",
            ]);

            return $courrier;
        });
    }

    public function enregistrer(
        Courrier $courrier,
        User $utilisateur,
        CourrierClassification $classification,
        ?string $noteTechnique,
        ?string $accuseReceptionPartenaire,
    ): Courrier {
        return DB::transaction(function () use ($courrier, $utilisateur, $classification, $noteTechnique, $accuseReceptionPartenaire) {
            $courrier = $this->lockCourrierFrais($courrier);
            $this->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::ENREGISTRE);

            $courrier->classification = $classification;
            $courrier->note_technique = $noteTechnique;
            $courrier->accuse_reception_partenaire = $accuseReceptionPartenaire;
            $courrier->numero_enregistrement = $this->numeros->genererNumeroEnregistrement();
            $courrier->cote_classement = $this->genererCoteClassement($courrier);
            $courrier->enregistre_at = now();
            $courrier->statut = CourrierStatut::ENREGISTRE;
            $courrier->save();
            $this->tracerTransition($courrier, $utilisateur);

            return $courrier;
        });
    }

    public function ajouterAnnotation(Courrier $courrier, User $auteur, string $contenu): CourrierAnnotation
    {
        /** @var CourrierAnnotation */
        return $courrier->annotations()->create([
            'auteur_id' => $auteur->id,
            'contenu' => $contenu,
        ]);
    }
}
