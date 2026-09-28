<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Contracts\CircuitTransitionRules;
use Modules\Courrier\Contracts\CourrierPdfGenerator;
use Modules\Courrier\Contracts\NumeroGenerator;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierClassification;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Enums\DegreUrgence;
use Modules\Courrier\Enums\DocumentRelationType;
use Modules\Courrier\Enums\MentionImputation;
use Modules\Courrier\Enums\MissionDocumentaireStatut;
use Modules\Courrier\Enums\MissionDocumentaireType;
use Modules\Courrier\Enums\ModeReception;
use Modules\Courrier\Enums\NumerisationStatut;
use Modules\Courrier\Enums\SensCourrier;
use Modules\Courrier\Events\CourrierStageAvisFavorable;
use Modules\Courrier\Exceptions\EmpruntOriginalException;
use Modules\Courrier\Exceptions\RelectureNonValideeException;
use Modules\Courrier\Exceptions\TransitionNonAutoriseeException;
use Modules\Courrier\Mail\AccuseReceptionCandidatMail;
use Modules\Courrier\Mail\AccuseReceptionCourrierExterneMail;
use Modules\Courrier\Mail\CourrierRecuMail;
use Modules\Courrier\Mail\ReponseFinaleCourrierExterneMail;
use Modules\Courrier\Models\BordereauLot;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierAnnotation;
use Modules\Courrier\Models\CourrierTransition;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\EmpruntOriginal;
use Modules\Courrier\Models\InstructionCourrierDg;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Courrier\Models\ReorientationTri;
use Modules\Courrier\Notifications\CourrierDgNotification;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;
use Modules\Kernel\Support\DgAuthorityResolver;
use Modules\Kernel\Support\DgDisponibilite;
use Modules\Kernel\Support\EmpreinteFichier;
use Throwable;

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
        private readonly DelegationResolver $delegations,
        private readonly DocumentRelationService $relationsDocumentaires,
        private readonly DispatchCourrierService $dispatchs,
        private readonly DossierWorkflowGuard $dossiers,
        private readonly DgAuthorityResolver $autoriteDg,
    ) {}

    public function creer(User $auteur, array $donnees, bool $numerisationImpossible = false): Courrier
    {
        $estReception = $auteur->poste === $this->regles->posteDeCreation();

        // À la Réception, un scan est normalement requis ; la dérogation
        // explicite signale qu'il devra être rattrapé ultérieurement.
        $numerisationStatut = match (true) {
            $numerisationImpossible => NumerisationStatut::A_NUMERISER,
            // La pièce jointe est obligatoire à la Réception sauf
            // numerisation_impossible (voir StoreCourrierRequest) : à ce
            // point, si ni l'un ni l'autre, c'est qu'elle a bien été fournie.
            default => NumerisationStatut::NUMERISE,
        };

        if (! $estReception) {
            throw TransitionNonAutoriseeException::posteNonHabilite();
        }

        $courrier = DB::transaction(function () use ($auteur, $donnees, $numerisationStatut) {
            $courrier = Courrier::query()->create([
                ...$donnees,
                // Un enregistrement physique est identifié immédiatement
                // par son numéro institutionnel, jamais par un AR public.
                'numero_accuse_reception' => null,
                'numero_enregistrement' => $this->numeros->genererNumeroEnregistrement(),
                'statut' => CourrierStatut::RECU,
                'necessite_avis_dg' => true,
                'initie_par_dg' => false,
                'created_by' => $auteur->id,
                'numerisation_statut' => $numerisationStatut,
                // Le courrier physique est administrativement enregistré
                // dans la même transaction que l'attribution de son numéro.
                'enregistre_at' => now(),
            ]);

            $this->tracerTransition($courrier, $auteur);
            if ($courrier->numero_enregistrement !== null) {
                $this->auditerIdentite($courrier, $auteur, 'numero_enregistrement', $courrier->numero_enregistrement);
            }

            return $courrier;
        });

        // Notification envoyée après commit : jamais de mail annonçant un
        // courrier dont l'écriture aurait finalement été annulée.
        if ($courrier->direction_destination_id) {
            $responsables = User::query()
                ->where('direction_id', $courrier->direction_destination_id)
                ->whereIn('role', [UserRole::DIRECTEUR_DIRECTION, UserRole::RESPONSABLE_DIRECTION])
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
                'numero_enregistrement' => $this->numeros->genererNumeroEnregistrement(),
                'statut' => CourrierStatut::RECU,
                // Une demande de stage suit structurellement le circuit central.
                'necessite_avis_dg' => true,
                'initie_par_dg' => false,
                'created_by' => null,
                // Le mode de réception d'un dépôt via le portail public
                // n'est jamais laissé au choix du candidat : c'est un fait
                // déterminé par le canal d'entrée, pas une déclaration.
                'mode_reception' => ModeReception::DEPOT_EN_LIGNE,
            ]);

            $this->tracerTransition($courrier, null);
            $this->auditerIdentite($courrier, null, 'numero_enregistrement', $courrier->numero_enregistrement);

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
     * et pas de candidat — un expéditeur externe. Circuit central
     * obligatoire (necessite_avis_dg=true).
     */
    public function creerCourrierExterneDepuisPublic(array $donnees): Courrier
    {
        $courrier = DB::transaction(function () use ($donnees) {
            $courrier = Courrier::query()->create([
                ...$donnees,
                'type' => CourrierType::CORRESPONDANCE_GENERALE,
                'numero_accuse_reception' => $this->numeros->genererAccuseReception(),
                'numero_enregistrement' => null,
                'statut' => CourrierStatut::RECU,
                'necessite_avis_dg' => true,
                'initie_par_dg' => false,
                'created_by' => null,
                'mode_reception' => ModeReception::DEPOT_EN_LIGNE,
            ]);

            $this->audit->enregistrer('courrier.depot_public', $courrier, null, [
                'numero_accuse_reception' => $courrier->numero_accuse_reception,
                'depose_at' => now()->toISOString(),
            ]);

            return $courrier;
        });

        if ($courrier->expediteur_externe_email) {
            try {
                $this->notifications->envoyerMail($courrier->expediteur_externe_email, new AccuseReceptionCourrierExterneMail($courrier));
                $this->audit->enregistrer('courrier.accuse_reception_envoye', $courrier, null, [
                    'dossier_id' => $courrier->dossier_id,
                    'destinataire' => $courrier->expediteur_externe_email,
                    'type_notification' => 'accuse_reception',
                    'envoye_at' => now()->toISOString(),
                ]);
            } catch (Throwable $exception) {
                Log::error("Échec de mise en file de l'accusé de réception externe.", [
                    'courrier_id' => $courrier->id,
                    'dossier_id' => $courrier->dossier_id,
                    'exception' => $exception,
                ]);
                $this->audit->enregistrer('courrier.accuse_reception_echec', $courrier, null, [
                    'dossier_id' => $courrier->dossier_id,
                    'type_notification' => 'accuse_reception',
                ]);
            }
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
        $this->assertRelecteurNonSec2((int) $donnees['relecteur_id']);
        if (! $this->delegations->utilisateurHabilite($secretariat1, [Poste::SECRETARIAT_1])) {
            throw TransitionNonAutoriseeException::posteNonHabilite();
        }

        $validationRequise = (bool) ($donnees['validation_dg_requise'] ?? false);

        $courrier = DB::transaction(function () use ($secretariat1, $donnees, $validationRequise) {
            $instruction = InstructionCourrierDg::query()->lockForUpdate()->find($donnees['instruction_courrier_dg_id']);
            if ($instruction === null || ($instruction->destinataire_user_id !== null && $instruction->destinataire_user_id !== $secretariat1->id)) {
                abort(404);
            }
            if (! $instruction->active()) {
                throw ValidationException::withMessages(['instruction_courrier_dg_id' => 'Cette instruction DG n’est plus active.']);
            }

            $courrier = Courrier::query()->create([
                'objet' => $donnees['objet'],
                'type' => CourrierType::CORRESPONDANCE_GENERALE,
                'direction_destination_id' => $donnees['direction_destination_id'],
                'direction_origine_id' => null,
                // Instruction interne DG : aucun accusé technique public.
                'numero_accuse_reception' => null,
                'statut' => CourrierStatut::RECU,
                'necessite_avis_dg' => false,
                'initie_par_dg' => true,
                'validation_dg_requise' => $validationRequise,
                'piece_jointe_chemin' => $donnees['piece_jointe_chemin'] ?? null,
                'created_by' => $secretariat1->id,
            ]);

            $this->tracerTransition($courrier, $secretariat1);

            $instruction->update([
                'courrier_id' => $courrier->id,
                'consomme_at' => now(),
                'consomme_par_id' => $secretariat1->id,
            ]);
            $this->audit->enregistrer('instruction_courrier_dg.consommee', $instruction, $secretariat1, [
                'courrier_id' => $courrier->id,
                'donneur_id' => $instruction->donneur_id,
            ]);

            $courrier->projet_reponse_contenu = $donnees['projet_reponse_contenu'];
            $courrier->relecteur_id = $donnees['relecteur_id'];
            $courrier->statut = $validationRequise ? CourrierStatut::EN_ATTENTE_VALIDATION_DG : CourrierStatut::EN_RELECTURE;
            $courrier->save();
            $this->tracerTransition($courrier, $secretariat1);

            $this->audit->enregistrer('courrier.initie_par_dg', $courrier, $secretariat1, [
                'validation_dg_requise' => $validationRequise,
                'instruction_courrier_dg_id' => $instruction->id,
            ]);

            return $courrier;
        });

        $responsables = User::query()
            ->where('direction_id', $courrier->direction_destination_id)
            ->whereIn('role', [UserRole::DIRECTEUR_DIRECTION, UserRole::RESPONSABLE_DIRECTION])
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
            $sourceAutorite = $this->autoriteDg->source($dg);
            if ($sourceAutorite === null) {
                throw TransitionNonAutoriseeException::posteNonHabilite();
            }
            $courrier = $this->lockCourrierFrais($courrier);
            $this->assertTransitionAutorisee($courrier, $dg, CourrierStatut::EN_RELECTURE);

            $courrier->valide_par_dg_at = now();
            $courrier->statut = CourrierStatut::EN_RELECTURE;
            $courrier->save();
            $this->tracerTransition($courrier, $dg);

            $this->audit->enregistrer('courrier.valide_par_dg', $courrier, $dg, ['source_autorite' => $sourceAutorite]);

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
    private function tracerTransition(Courrier $courrier, ?User $utilisateur, ?int $bordereauLotId = null, ?string $instruction = null): void
    {
        $ancienStatut = CourrierTransition::query()
            ->where('courrier_id', $courrier->id)
            ->latest('id')
            ->value('nouveau_statut');
        $destinatairePoste = null;
        $destinataireUserId = null;

        if ($courrier->sens === SensCourrier::SORTANT && $courrier->statut === CourrierStatut::PROJET_A_REDIGER) {
            // Brouillon personnel de l'assistant assigné : aucune remise à
            // décharger avant la soumission nominative au relecteur.
        } elseif ($courrier->enAttenteValidationRelecteur()) {
            $destinataireUserId = $courrier->relecteur_id;
        } else {
            $postesAutorises = $this->regles->postesPourTransitionResolue(
                $courrier->statut,
                $courrier->necessite_avis_dg,
                $courrier->initie_par_dg,
                $courrier->sens === SensCourrier::SORTANT,
                ['type' => $courrier->type?->value],
            );
            $destinatairePoste = ($postesAutorises[0] ?? null)?->value;
        }

        CourrierTransition::query()->create([
            'courrier_id' => $courrier->id,
            'statut' => $courrier->statut,
            'ancien_statut' => $ancienStatut,
            'nouveau_statut' => $courrier->statut,
            'tour' => $courrier->tour,
            'changed_by_id' => $utilisateur?->id,
            'expediteur_poste' => $utilisateur?->poste?->value,
            'instruction' => $instruction,
            // Proxy volontairement simple : "cet utilisateur détient une
            // délégation active aujourd'hui", pas une vérification que
            // CETTE transition précise en dépendait — reconstituer ce fait
            // précis demanderait de faire transiter le résultat
            // d'assertTransitionAutorisee() jusqu'ici. Assez fidèle en
            // pratique : un utilisateur en délégation agit rarement aussi
            // sous son propre poste le même jour.
            'agi_en_interim' => $utilisateur !== null && ($this->autoriteDg->source($utilisateur) === 'interim_dga' || $this->delegations->posteDelegueAujourdhui($utilisateur) !== null),
            'destinataire_poste' => $destinatairePoste,
            'destinataire_user_id' => $destinataireUserId,
            'bordereau_lot_id' => $bordereauLotId,
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
    /**
     * Lot D, point 1 : une demande de stage n'atteint jamais enregistrer()
     * (elle sort du circuit courrier ordinaire dès son imputation à la
     * DFP, voir rendreAvisDg()) — sans quoi elle ne recevrait jamais de
     * cote de classement propre, restant introuvable autrement que par la
     * fiche stagiaire qui en découle. Appelée à l'approbation du tableau
     * qui la porte (voir TableauRepartitionCircuitService::rendreAvis()),
     * qu'elle ait été retenue ou non — un dossier refusé garde, lui aussi,
     * sa lettre classée. Idempotente : n'écrase jamais une cote déjà posée.
     */
    public function classerDemandeStage(Courrier $courrier): void
    {
        if ($courrier->cote_classement !== null) {
            return;
        }

        $codeDirection = $courrier->directionPrincipale()?->direction?->code ?? 'ONT';
        $courrier->cote_classement = $this->numeros->genererCote($codeDirection, 'classement_stagiaire');
        $courrier->save();
    }

    private function genererCoteClassement(Courrier $courrier): string
    {
        $codeDirection = $courrier->directionPrincipale()?->direction?->code ?? 'ONT';

        if ($courrier->numero_enregistrement === null) {
            return $this->numeros->genererCote($codeDirection, 'classement_courrier');
        }

        [$annee, $sequence] = array_pad(explode('-', (string) $courrier->numero_enregistrement, 2), 2, '0000');

        return strtr(config('courrier.format_cote_classement', '{direction}-{annee}-{sequence}'), [
            '{direction}' => $codeDirection,
            '{annee}' => $annee,
            '{sequence}' => $sequence,
        ]);
    }

    private function auditerIdentite(Courrier $courrier, ?User $acteur, string $type, string $valeur): void
    {
        $this->audit->enregistrer("courrier.{$type}_attribue", $courrier, $acteur, [
            'type_identite' => $type,
            'valeur' => $valeur,
            'attribuee_at' => now()->toISOString(),
        ]);
    }

    private function assertDechargeDonnee(Courrier $courrier): void
    {
        if ($courrier->enTransit()) {
            throw TransitionNonAutoriseeException::dechargeNonDonnee();
        }
    }

    /**
     * `$contexte` porte les valeurs nécessaires à la résolution quand le
     * statut courant a plusieurs destinations candidates (voir
     * CircuitTransitionRules::statutSuivant()) — vide pour la plupart des
     * appels, à un seul statut de destination possible.
     *
     * @param  array<string, mixed>  $contexte
     */
    private function assertTransitionAutorisee(Courrier $courrier, User $utilisateur, CourrierStatut $statutCible, array $contexte = []): void
    {
        $this->assertDechargeDonnee($courrier);

        $estSortant = $courrier->sens === SensCourrier::SORTANT;
        $statutAttendu = $this->regles->statutSuivant($courrier->statut, $courrier->necessite_avis_dg, $courrier->initie_par_dg, $estSortant, $contexte);

        if ($statutAttendu === null || $statutAttendu !== $statutCible) {
            throw TransitionNonAutoriseeException::sautDetape();
        }

        $postesAutorises = $this->regles->postesAutorises($courrier->statut, $courrier->necessite_avis_dg, $courrier->initie_par_dg, $estSortant);

        // Un utilisateur qui n'occupe pas lui-même l'un des postes
        // autorisés peut malgré tout agir s'il en détient une délégation
        // active aujourd'hui (voir DelegationPoste) — aucun poste du
        // circuit ne doit bloquer le flux par la seule absence de son
        // titulaire.
        if (! $this->delegations->utilisateurHabilite($utilisateur, $postesAutorises)) {
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
            $this->autoriteDg->source($utilisateur);
            $courrier = $this->lockCourrierFrais($courrier);

            $bordereau = $courrier->bordereauCourant();

            if ($bordereau === null) {
                throw TransitionNonAutoriseeException::sautDetape();
            }

            if ($bordereau->accuse_reception_at !== null) {
                throw TransitionNonAutoriseeException::dechargeDejaDonnee();
            }

            if ($bordereau->destinataire_user_id !== null) {
                if ($bordereau->destinataire_user_id !== $utilisateur->id) {
                    throw TransitionNonAutoriseeException::posteNonHabilite();
                }
            } elseif ($bordereau->destinataire_poste !== null) {
                $posteDestinataire = Poste::from($bordereau->destinataire_poste);
                $estInterimDg = $posteDestinataire === Poste::DG
                    && $this->autoriteDg->source($utilisateur) === 'interim_dga';

                if (! $estInterimDg && ! $this->delegations->utilisateurHabilite($utilisateur, [$posteDestinataire])) {
                    throw TransitionNonAutoriseeException::posteNonHabilite();
                }
            } else {
                // Compatibilité avec les bordereaux historiques créés avant
                // l'ajout du destinataire explicite.
                $estSortant = $courrier->sens === SensCourrier::SORTANT;
                $postesAutorises = $this->regles->postesAutorises($courrier->statut, $courrier->necessite_avis_dg, $courrier->initie_par_dg, $estSortant);
                $enInterim = $this->autoriteDg->source($utilisateur) === 'interim_dga';

                if (! $this->delegations->utilisateurHabilite($utilisateur, $postesAutorises)) {
                    throw TransitionNonAutoriseeException::posteNonHabilite();
                }

                if ($utilisateur->poste === Poste::DGA && ! $enInterim && $this->autoriteDg->source($utilisateur) !== 'delegation') {
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

    /**
     * Après la remise initiale par la Réception, le Secrétariat 01 acquitte
     * le bordereau puis place le dossier dans sa file de tri.
     */
    public function transmettreTri(Courrier $courrier, User $utilisateur, ?string $instruction = null): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $instruction) {
            $courrier = $this->lockCourrierFrais($courrier);
            $this->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::EN_ATTENTE_TRI, ['type' => $courrier->type->value]);

            $courrier->statut = CourrierStatut::EN_ATTENTE_TRI;
            $courrier->save();
            $this->tracerTransition($courrier, $utilisateur, instruction: $instruction);

            $this->audit->enregistrer('courrier.transmis_tri', $courrier, $utilisateur, ['tour' => $courrier->tour]);

            return $courrier;
        });
    }

    /**
     * Lot C, points 1-2 : "la Réception est un point de blocage" tenait au
     * fait que chaque courrier fraîchement créé porte sa propre transition
     * en attente (voir tracerTransition(), destinataire_poste déjà
     * résolu à la création) — jusqu'ici, le destinataire (Secrétariat 01,
     * le plus souvent) devait accuser réception de chacun UN PAR UN avant
     * de pouvoir seulement commencer à les traiter. Cette méthode ne crée
     * AUCUNE nouvelle transition : elle regroupe sous un même bordereau
     * des transitions déjà existantes, encore en attente, pour qu'une
     * seule décharge (voir accuserReceptionLot()) les débloque toutes.
     * "Trace individuelle par dossier" reste garantie : chaque transition
     * groupée est la même ligne qui existait déjà, seulement étiquetée.
     * Un lot doit être homogène (même poste destinataire) — un bordereau
     * physique ne peut matériellement pas être remis à deux guichets
     * différents à la fois.
     */
    public function grouperEnBordereauLot(array $courrierIds, User $emetteur): BordereauLot
    {
        $courrierIds = array_values(array_unique($courrierIds));

        if ($courrierIds === []) {
            throw TransitionNonAutoriseeException::lotVide();
        }

        return DB::transaction(function () use ($courrierIds, $emetteur) {
            $transitions = CourrierTransition::query()
                ->whereIn('courrier_id', $courrierIds)
                ->whereNull('accuse_reception_at')
                ->whereNotNull('destinataire_poste')
                ->whereNull('bordereau_lot_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('courrier_id');

            if ($transitions->count() !== count($courrierIds)) {
                throw TransitionNonAutoriseeException::sautDetape();
            }

            $postesDestinataires = $transitions->pluck('destinataire_poste')->unique();

            if ($postesDestinataires->count() > 1) {
                throw TransitionNonAutoriseeException::lotHeterogene();
            }

            foreach ($transitions as $transition) {
                $estEmetteur = $transition->changed_by_id === $emetteur->id;
                $estDestinataire = $this->delegations->utilisateurHabilite($emetteur, [Poste::from($transition->destinataire_poste)]);
                if (! $estEmetteur && ! $estDestinataire) {
                    throw TransitionNonAutoriseeException::posteNonHabilite();
                }
            }

            $bordereau = BordereauLot::query()->create([
                'numero' => $this->numeros->genererNumeroBordereauLot(),
                'emetteur_id' => $emetteur->id,
                'poste_destinataire' => $postesDestinataires->first(),
            ]);

            CourrierTransition::query()
                ->whereIn('id', $transitions->pluck('id'))
                ->update(['bordereau_lot_id' => $bordereau->id]);

            return $bordereau;
        });
    }

    /**
     * Décharge du lot en une seule action — mais chaque dossier reçoit
     * individuellement le même accusé (voir CourrierTransition, mises à
     * jour en bloc ci-dessous) : Courrier::enTransit() continue de
     * raisonner dossier par dossier, sans rien savoir d'un "lot".
     */
    public function accuserReceptionLot(BordereauLot $bordereau, User $destinataire): BordereauLot
    {
        return DB::transaction(function () use ($bordereau, $destinataire) {
            $sourceAutorite = $this->autoriteDg->source($destinataire);
            $bordereau = BordereauLot::query()->lockForUpdate()->findOrFail($bordereau->id);
            if ($bordereau->poste_destinataire === Poste::DG && $sourceAutorite === null) {
                throw TransitionNonAutoriseeException::posteNonHabilite();
            }
            if ($bordereau->accuse_reception_at !== null) {
                throw TransitionNonAutoriseeException::dechargeDejaDonnee();
            }

            if (! $this->delegations->utilisateurHabilite($destinataire, [$bordereau->poste_destinataire])) {
                throw TransitionNonAutoriseeException::posteNonHabilite();
            }

            $maintenant = now();

            $bordereau->accuse_reception_at = $maintenant;
            $bordereau->accuse_reception_par_id = $destinataire->id;
            $bordereau->save();

            $bordereau->transitions()->update([
                'accuse_reception_at' => $maintenant,
                'accuse_reception_par_id' => $destinataire->id,
                'destinataire_user_id' => $destinataire->id,
            ]);

            return $bordereau;
        });
    }

    /**
     * C'est ICI, et seulement ici, que le tri par degré d'urgence du
     * Secrétariat 01 est réellement effectué (voir CourrierStatut::EN_ATTENTE_TRI)
     * — jamais un statut à part qui bloquerait le dossier : le courrier
     * reste visible et consultable tant qu'il n'a pas encore été trié
     * (voir Courrier::urgenceTriee(), config('courrier.tri.delai_alerte_heures')
     * pour l'alerte de tri manquant). Une fois trié, seule la DG peut
     * corriger le degré (voir requalifierUrgence()) — cette méthode-ci ne
     * repose jamais sur un degré déjà présent.
     *
     * Lot assistants (voir docs/questions-ont.md) : un degré urgent ou très
     * urgent transmet directement à la DG, comme avant ; un degré normal
     * part désormais au classeur d'attente du Secrétariat 01
     * (CourrierStatut::EN_ATTENTE_CLASSEUR), qui le transmettra lui-même à
     * la DG quand il le jugera bon (voir transmettreDepuisClasseur()) —
     * voir config('courrier.circuit_transitions.complet.en_attente_tri').
     */
    public function transmettreEnAttenteAvisDg(Courrier $courrier, User $utilisateur, DegreUrgence $degreUrgence, ?string $instruction = null): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $degreUrgence, $instruction) {
            $courrier = $this->lockCourrierFrais($courrier);

            $statutCible = match ($degreUrgence) {
                DegreUrgence::URGENT, DegreUrgence::TRES_URGENT => CourrierStatut::EN_ATTENTE_AVIS_DG,
                DegreUrgence::NORMAL => CourrierStatut::EN_ATTENTE_CLASSEUR,
            };
            $this->assertTransitionAutorisee($courrier, $utilisateur, $statutCible, ['degre_urgence' => $degreUrgence->value]);

            $courrier->degre_urgence = $degreUrgence;
            $courrier->urgence_triee_at = now();
            $courrier->urgence_triee_par_id = $utilisateur->id;
            $courrier->statut = $statutCible;
            $courrier->save();
            $this->tracerTransition($courrier, $utilisateur, instruction: $instruction);

            $this->audit->enregistrer('courrier.tri_sec1', $courrier, $utilisateur, [
                'degre_urgence' => $degreUrgence->value,
                'statut_arrivee' => $statutCible->value,
            ]);
            if ($statutCible === CourrierStatut::EN_ATTENTE_AVIS_DG) {
                $this->audit->enregistrer('courrier.transmis_dg', $courrier, $utilisateur, ['depuis' => CourrierStatut::EN_ATTENTE_TRI->value]);
                $this->notifierDgApresCommit($courrier);
            }

            return $courrier;
        });
    }

    /**
     * Le Secrétariat 01 transmet à la DG un dossier qu'il tenait au classeur
     * d'attente (degré d'urgence normal, voir transmettreEnAttenteAvisDg())
     * — à son initiative, jamais automatique : rien d'autre ne fait sortir
     * un dossier du classeur.
     */
    public function transmettreDepuisClasseur(Courrier $courrier, User $utilisateur, ?string $instruction = null): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $instruction) {
            $courrier = $this->lockCourrierFrais($courrier);
            $this->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::EN_ATTENTE_AVIS_DG);

            $courrier->statut = CourrierStatut::EN_ATTENTE_AVIS_DG;
            $courrier->save();
            $this->tracerTransition($courrier, $utilisateur, instruction: $instruction);

            $this->audit->enregistrer('courrier.transmis_dg', $courrier, $utilisateur, ['depuis' => CourrierStatut::EN_ATTENTE_CLASSEUR->value]);
            $this->notifierDgApresCommit($courrier);

            return $courrier;
        });
    }

    /**
     * Correction du degré d'urgence après le tri — réservée à la DG et à
     * elle seule (jamais la DGA, y compris en intérim : c'est une décision
     * de fond, pas une action du circuit standard soumise à la garde
     * d'intérim habituelle). Peut s'exercer à tout moment après le tri,
     * quel que soit le statut courant du dossier (un directeur qui reçoit
     * un dossier marqué normal alors qu'il est brûlant doit pouvoir le
     * corriger sans renvoyer le courrier au Secrétariat) — ce n'est donc
     * volontairement pas une transition de circuit (pas d'appel à
     * assertTransitionAutorisee()).
     */
    public function requalifierUrgence(Courrier $courrier, User $dg, DegreUrgence $nouveauDegre): Courrier
    {
        return DB::transaction(function () use ($courrier, $dg, $nouveauDegre) {
            $courrier = $this->lockCourrierFrais($courrier);

            if (! $courrier->urgenceTriee()) {
                throw TransitionNonAutoriseeException::urgenceNonTriee();
            }

            $ancienDegre = $courrier->degre_urgence;
            $courrier->degre_urgence = $nouveauDegre;
            $courrier->save();

            $this->audit->enregistrer('courrier.urgence_requalifiee', $courrier, $dg, [
                'ancien_degre' => $ancienDegre->value,
                'nouveau_degre' => $nouveauDegre->value,
            ]);

            return $courrier;
        });
    }

    /**
     * Lot C (réorientation du tri) : le signataire qui reçoit un courrier
     * mal orienté vers l'avis DG le renvoie au tri — jamais une faute (le
     * dossier ne boucle pas sur lui-même comme un avis "réservé", il
     * repart carrément vers l'étape précédente), et le tour n'est
     * volontairement pas incrémenté : ce n'est pas un renvoi de fond sur un
     * dossier déjà instruit, juste une correction d'aiguillage avant même
     * que la DG ait commencé à l'examiner. Le geste est cependant tracé
     * dans reorientations_tri (voir ReorientationTri), y compris qui avait
     * trié à l'origine, pour alimenter une statistique de justesse
     * (CourrierPolicy::voirJustesseTri()) — jamais pour désigner une faute,
     * seulement pour objectiver un taux.
     */
    public function renvoyerAuTri(Courrier $courrier, User $utilisateur, ?string $motif): Courrier
    {
        return DB::transaction(function () use ($courrier, $motif, $utilisateur) {
            $courrier = $this->lockCourrierFrais($courrier);
            $this->assertDechargeDonnee($courrier);

            if ($courrier->statut !== CourrierStatut::EN_ATTENTE_AVIS_DG) {
                throw TransitionNonAutoriseeException::sautDetape();
            }

            if (! $this->autoriteDg->estAutorite($utilisateur)) {
                throw TransitionNonAutoriseeException::posteNonHabilite();
            }

            $trieParId = CourrierTransition::query()
                ->where('courrier_id', $courrier->id)
                ->where('statut', CourrierStatut::EN_ATTENTE_AVIS_DG)
                ->latest('id')
                ->value('changed_by_id');

            $courrier->statut = CourrierStatut::EN_ATTENTE_TRI;
            $courrier->save();
            $this->tracerTransition($courrier, $utilisateur);

            ReorientationTri::query()->create([
                'courrier_id' => $courrier->id,
                'trie_par_id' => $trieParId,
                'reoriente_par_id' => $utilisateur->id,
                'motif' => $motif,
                'created_at' => now(),
            ]);

            $this->audit->enregistrer('courrier.reoriente_au_tri', $courrier, $utilisateur, ['motif' => $motif]);

            return $courrier;
        });
    }

    /**
     * La Réception représente à la DG un dossier revenu en "réservé" — seule
     * transition qui incrémente le tour de boucle (voir
     * Courrier::tour, config('courrier.circuit.tours_avant_alerte')).
     */
    public function transmettreRetourVersSec1(Courrier $courrier, User $utilisateur, ?string $instruction = null): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $instruction) {
            $courrier = $this->lockCourrierFrais($courrier);

            if ($courrier->statut === CourrierStatut::RECU && $courrier->mode_reception === ModeReception::DEPOT_EN_LIGNE) {
                if ($utilisateur->poste !== Poste::RECEPTION) {
                    throw TransitionNonAutoriseeException::posteNonHabilite();
                }
                if ($courrier->numero_enregistrement === null) {
                    throw ValidationException::withMessages([
                        'courrier' => 'Le dépôt doit être enregistré par la Réception avant sa transmission à SEC1.',
                    ]);
                }
                if ($courrier->transitions()->exists()) {
                    throw TransitionNonAutoriseeException::sautDetape();
                }

                $this->tracerTransition($courrier, $utilisateur, instruction: $instruction);
                $this->audit->enregistrer('courrier.transmis_sec1', $courrier, $utilisateur, [
                    'numero_enregistrement' => $courrier->numero_enregistrement,
                ]);

                return $courrier;
            }

            $this->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::EN_ATTENTE_TRI);

            $courrier->tour += 1;
            $courrier->statut = CourrierStatut::EN_ATTENTE_TRI;
            $courrier->save();
            $this->tracerTransition($courrier, $utilisateur, instruction: $instruction);

            return $courrier;
        });
    }

    /**
     * Le Secrétariat 02 transmet un courrier imputé (avis DG favorable,
     * voir rendreAvisDg()) au secrétariat de la direction imputée à titre
     * principal (Lot 3). Étape terminale pour ce lot : la suite (tableau de
     * répartition, retour vers la DG) est le Lot 4.
     */
    public function dispatcherVersDirection(Courrier $courrier, User $utilisateur, ?string $instruction = null): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $instruction) {
            $courrier = $this->lockCourrierFrais($courrier);
            $this->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::CHEZ_DIRECTION);

            $courrier->statut = CourrierStatut::CHEZ_DIRECTION;
            $courrier->save();
            $this->tracerTransition($courrier, $utilisateur, instruction: $instruction);

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
            if ($courrier->statut !== CourrierStatut::EN_ATTENTE_AVIS_DG) {
                throw TransitionNonAutoriseeException::sautDetape();
            }
            $imputationAutomatiqueDfp = null;

            // Lot A (tableau généré, non ressaisi) : une demande de stage
            // n'a de sens que traitée par la DFP — sans ce filet, un avis
            // favorable rendu sans imputation manuelle laisserait le
            // dossier partir en rédaction interne (projet_a_rediger)
            // au lieu de rejoindre le circuit du tableau de répartition, et
            // la demande resterait orpheline de tout tableau. Configurable
            // (config('stagiaires.imputation_automatique_dfp'), défaut
            // activé) — voir docs/questions-ont.md.
            if (
                $avis === AvisDg::FAVORABLE
                && $courrier->type === CourrierType::DEMANDE_STAGE
                && config('stagiaires.imputation_automatique_dfp', true)
                && $courrier->imputations()->where('est_principale', true)->doesntExist()
            ) {
                $directionDfp = Direction::query()
                    ->where('code', config('stagiaires.direction_dfp_code'))
                    ->first();

                if ($directionDfp !== null) {
                    $courrier->imputations()->create([
                        'direction_id' => $directionDfp->id,
                        'mention' => MentionImputation::POUR_ATTRIBUTION,
                        'est_principale' => true,
                        'imputee_par_id' => $utilisateur->id,
                    ]);
                    $imputationAutomatiqueDfp = $directionDfp;
                }
            }

            // Un avis "réservé" veut dire que la décision n'est pas
            // arrêtée : le dossier boucle (retour_reception, voir
            // config('courrier.circuit_transitions.complet.en_attente_avis_dg'))
            // plutôt que d'avancer comme si une décision avait été prise.
            // Un avis favorable sur un courrier déjà imputé (Lot 3), y
            // compris par le filet ci-dessus, part en dispatch plutôt
            // qu'en rédaction interne de réponse.
            $courrierImpute = $courrier->imputations()->where('est_principale', true)->exists();
            if ($avis === AvisDg::FAVORABLE && $courrierImpute && $imputationAutomatiqueDfp === null) {
                throw ValidationException::withMessages(['courrier' => 'Une nouvelle orientation doit être décidée par le dispatch institutionnel ; les imputations historiques ne sont pas réutilisées comme décision.']);
            }
            if ($imputationAutomatiqueDfp !== null) {
                $this->assertDechargeDonnee($courrier);
                $courrier = $this->dispatchs->decider($courrier, $utilisateur, [[
                    'type' => 'direction',
                    'direction_id' => $imputationAutomatiqueDfp->id,
                    'instruction' => 'Orientation automatique de la demande de stage vers la DFP après avis favorable DG.',
                ]]);
                $this->audit->enregistrer('courrier.avis_dg_rendu', $courrier, $utilisateur, [
                    'avis' => $avis->value,
                    'orientation_dfp_automatique' => true,
                ]);
                DB::afterCommit(fn () => CourrierStageAvisFavorable::dispatch($courrier));

                return $courrier;
            }
            $statutCible = match (true) {
                $avis === AvisDg::RESERVE => CourrierStatut::RETOUR_RECEPTION,
                $avis === AvisDg::FAVORABLE && $courrierImpute => CourrierStatut::EN_DISPATCH,
                default => CourrierStatut::PROJET_A_REDIGER,
            };
            $this->assertTransitionAutorisee($courrier, $utilisateur, $statutCible, [
                'avis_dg' => $avis->value,
                'courrier_impute' => $courrierImpute,
            ]);

            $sourceAutorite = $this->autoriteDg->source($utilisateur);
            $enInterim = $sourceAutorite === 'interim_dga';

            if ($sourceAutorite === null) {
                throw TransitionNonAutoriseeException::posteNonHabilite();
            }

            $courrier->avis_dg = $avis;
            $courrier->avis_dg_commentaire = $commentaire;
            $courrier->avis_dg_rendu_at = now();
            $courrier->avis_dg_rendu_par_id = $utilisateur->id;
            $courrier->avis_dg_rendu_en_interim = $enInterim;
            // Les décisions tranchées sans imputation ouvraient auparavant
            // le projet directement sur A. Pour les nouveaux dossiers, A
            // reste désormais sous contrôle DG jusqu'à la création de la
            // mission nominative qui produira D. Les deux statuts legacy
            // restent lisibles et actionnables pour les dossiers qui y sont
            // déjà engagés, mais ne sont plus alimentés ici.
            $ouvrePreparationD = $statutCible === CourrierStatut::PROJET_A_REDIGER;
            $courrier->statut = $ouvrePreparationD ? CourrierStatut::EN_ATTENTE_AVIS_DG : $statutCible;
            $courrier->save();
            if (! $ouvrePreparationD) {
                $this->tracerTransition($courrier, $utilisateur);
            }

            $this->audit->enregistrer('courrier.avis_dg_rendu', $courrier, $utilisateur, [
                'avis' => $avis->value,
                'interim' => $enInterim,
                'preparation_reponse_d_attendue' => $ouvrePreparationD,
            ]);

            // Après commit uniquement : la fiche stagiaire créée en
            // réaction à cet événement ne doit jamais exister pour un avis
            // finalement annulé par un rollback (ex. verrou expiré, échec
            // d'assertion concurrente). Se déclenche que le courrier soit
            // imputé ou non (statutCible = en_dispatch ou
            // projet_a_rediger) : le Lot 3 ne touche pas à cet
            // événement — "l'avis favorable ne vaut pas acceptation" est le
            // Lot 5, pas celui-ci.
            if ($avis === AvisDg::FAVORABLE && $courrier->type === CourrierType::DEMANDE_STAGE) {
                DB::afterCommit(fn () => CourrierStageAvisFavorable::dispatch($courrier));
            }

            return $courrier;
        });
    }

    /**
     * Circuit complet uniquement (Lot assistants) : le rédacteur assistant
     * soumet son projet à un relecteur désigné — cible projet_a_valider,
     * jamais en_relecture (réservé aux circuits dg_initie/sortant, voir
     * CourrierStatut::PROJET_A_VALIDER).
     */
    public function soumettreProjetReponse(Courrier $courrier, User $utilisateur, array $projetReponseContenu, int $relecteurId): Courrier
    {
        $this->assertRelecteurNonSec2($relecteurId);

        return DB::transaction(function () use ($courrier, $utilisateur, $projetReponseContenu, $relecteurId) {
            $courrier = $this->lockCourrierFrais($courrier);
            $this->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::PROJET_A_VALIDER);

            $courrier->projet_reponse_contenu = $projetReponseContenu;
            $courrier->relecteur_id = $relecteurId;
            $courrier->relecture_validee_at = null;
            $courrier->statut = CourrierStatut::PROJET_A_VALIDER;
            $courrier->save();
            $this->tracerTransition($courrier, $utilisateur);

            return $courrier;
        });
    }

    /** Crée le brouillon sortant D rattaché à une mission DG nominative. */
    public function creerProjetReponseMission(MissionDocumentaire $mission, User $assistant, array $donnees): Courrier
    {
        return DB::transaction(function () use ($mission, $assistant, $donnees) {
            $mission = MissionDocumentaire::query()->lockForUpdate()->findOrFail($mission->id);
            $this->assertMissionPreparationActive($mission, $assistant);
            if ($mission->projet_courrier_id !== null) {
                throw ValidationException::withMessages(['mission' => 'Cette mission possède déjà son projet de réponse.']);
            }

            $original = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($mission->courrier_id);
            $courrier = Courrier::withoutGlobalScopes()->create([
                'objet' => $donnees['objet'],
                'projet_reponse_contenu' => $donnees['projet_reponse_contenu'] ?? ['type' => 'doc', 'content' => []],
                'type' => CourrierType::CORRESPONDANCE_GENERALE,
                'sens' => SensCourrier::SORTANT,
                'en_reponse_a_courrier_id' => $original->id,
                'dossier_id' => $original->dossier_id,
                'direction_origine_id' => null,
                'destinataire_externe_nom' => $donnees['destinataire_externe_nom'] ?? $original->expediteur_externe_nom,
                'destinataire_externe_email' => $donnees['destinataire_externe_email'] ?? $original->expediteur_externe_email,
                // Projet sortant interne au cycle : le numéro de départ
                // sera attribué à la signature, pas d'AR public.
                'numero_accuse_reception' => null,
                'statut' => CourrierStatut::PROJET_A_REDIGER,
                'necessite_avis_dg' => false,
                'initie_par_dg' => false,
                'created_by' => $assistant->id,
            ]);

            $this->relationsDocumentaires->relier($courrier, $original, DocumentRelationType::REPONSE_A, $assistant);
            $mission->update(['projet_courrier_id' => $courrier->id]);
            $this->tracerTransition($courrier, $assistant);
            $this->audit->enregistrer('courrier.projet_reponse_cree', $courrier, $assistant, [
                'mission_id' => $mission->id,
                'courrier_origine_id' => $original->id,
            ]);

            return $courrier;
        });
    }

    public function sauvegarderProjetReponseMission(MissionDocumentaire $mission, User $assistant, array $donnees): Courrier
    {
        return DB::transaction(function () use ($mission, $assistant, $donnees) {
            $mission = MissionDocumentaire::query()->lockForUpdate()->findOrFail($mission->id);
            $this->assertMissionPreparationActive($mission, $assistant);
            if ($mission->projet_courrier_id === null) {
                throw ValidationException::withMessages(['mission' => "Le projet de réponse n'a pas encore été créé."]);
            }

            $courrier = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($mission->projet_courrier_id);
            if ($courrier->statut !== CourrierStatut::PROJET_A_REDIGER || $courrier->created_by !== $assistant->id) {
                throw ValidationException::withMessages(['projet' => "Ce projet n'est pas modifiable."]);
            }
            $courrier->fill($donnees)->save();
            $this->audit->enregistrer('courrier.projet_reponse_sauvegarde', $courrier, $assistant, ['mission_id' => $mission->id]);

            return $courrier;
        });
    }

    public function soumettreProjetReponseMission(MissionDocumentaire $mission, User $assistant, array $projetReponseContenu): Courrier
    {
        return DB::transaction(function () use ($mission, $assistant, $projetReponseContenu) {
            $mission = MissionDocumentaire::query()->lockForUpdate()->findOrFail($mission->id);
            $this->assertMissionPreparationActive($mission, $assistant);
            if ($mission->projet_courrier_id === null) {
                throw ValidationException::withMessages(['mission' => "Le projet de réponse n'a pas encore été créé."]);
            }

            $courrier = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($mission->projet_courrier_id);
            if ($courrier->statut !== CourrierStatut::PROJET_A_REDIGER) {
                throw TransitionNonAutoriseeException::sautDetape();
            }

            $posteRelecteur = $assistant->poste === Poste::ASSISTANT_1 ? Poste::ASSISTANT_2 : Poste::ASSISTANT_1;
            if (! in_array($assistant->poste, [Poste::ASSISTANT_1, Poste::ASSISTANT_2], true)) {
                throw TransitionNonAutoriseeException::posteNonHabilite();
            }
            $relecteurs = User::query()->where('poste', $posteRelecteur)->get();
            if ($relecteurs->count() !== 1) {
                throw ValidationException::withMessages(['relecteur' => 'Le compte du relecteur institutionnel doit être unique et configuré.']);
            }
            $relecteur = $relecteurs->first();

            $courrier->projet_reponse_contenu = $projetReponseContenu;
            $courrier->relecteur_id = $relecteur->id;
            $courrier->relecture_validee_at = null;
            $courrier->relecture_commentaire = null;
            $courrier->statut = CourrierStatut::PROJET_A_VALIDER;
            $courrier->save();
            $this->tracerTransition($courrier, $assistant);
            $this->audit->enregistrer(
                $courrier->projet_renvoye_at ? 'courrier.projet_reponse_resoumis' : 'courrier.projet_reponse_soumis',
                $courrier,
                $assistant,
                ['mission_id' => $mission->id, 'relecteur_id' => $relecteur->id],
            );

            return $courrier;
        });
    }

    public function validerRelecture(Courrier $courrier, User $utilisateur, ?string $commentaire): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $commentaire) {
            $courrier = $this->lockCourrierFrais($courrier);

            if (! $courrier->enAttenteValidationRelecteur()) {
                throw TransitionNonAutoriseeException::sautDetape();
            }

            if ($courrier->relecteur_id !== $utilisateur->id) {
                throw TransitionNonAutoriseeException::posteNonHabilite();
            }

            $this->assertDechargeDonnee($courrier);

            $courrier->relecture_validee_at = now();
            $courrier->relecture_commentaire = $commentaire;
            $courrier->save();

            $this->audit->enregistrer('courrier.relecture_validee', $courrier, $utilisateur, [
                'commentaire_fourni' => $commentaire !== null && $commentaire !== '',
                'relecture_validee_at' => $courrier->relecture_validee_at->toIso8601String(),
            ]);

            if ($courrier->sens === SensCourrier::SORTANT) {
                $mission = MissionDocumentaire::query()
                    ->where('projet_courrier_id', $courrier->id)
                    ->where('type', MissionDocumentaireType::PREPARATION_REPONSE)
                    ->whereIn('statut', [MissionDocumentaireStatut::ASSIGNEE, MissionDocumentaireStatut::EN_COURS])
                    ->lockForUpdate()
                    ->first();
                if ($mission !== null) {
                    $mission->update([
                        'statut' => MissionDocumentaireStatut::RETOURNEE,
                        'compte_rendu' => 'Projet de réponse relu et transmis à la DG pour signature.',
                        'retournee_at' => now(),
                    ]);
                    $this->audit->enregistrer('mission_documentaire.terminee_apres_relecture', $mission, $utilisateur, [
                        'projet_courrier_id' => $courrier->id,
                    ]);
                }
                if (($mission === null || $mission->statut === MissionDocumentaireStatut::RETOURNEE)
                    && $courrier->destinataire_externe_nom !== null) {
                    $this->notifierDgApresCommit($courrier, 'pret_a_signer');
                }
            }

            return $courrier;
        });
    }

    /**
     * Renvoi pour correction, du relecteur désigné vers l'assistant
     * rédacteur — circuit complet uniquement (seul PROJET_A_VALIDER connaît
     * cette transition, voir config('courrier.circuit_transitions.complet')
     * qui ne la liste d'ailleurs pas : gardée manuellement, hors de la table,
     * comme validerRelecture() ci-dessus — le relecteur désigné n'est pas un
     * poste). Observation obligatoire (voir RenvoyerPourCorrectionRequest) :
     * un renvoi sans justification laisserait l'assistant deviner ce qui ne
     * va pas.
     */
    public function renvoyerPourCorrection(Courrier $courrier, User $utilisateur, string $observation): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $observation) {
            $courrier = $this->lockCourrierFrais($courrier);

            if ($courrier->statut !== CourrierStatut::PROJET_A_VALIDER) {
                throw TransitionNonAutoriseeException::sautDetape();
            }

            if ($courrier->relecteur_id !== $utilisateur->id) {
                throw TransitionNonAutoriseeException::posteNonHabilite();
            }

            $this->assertDechargeDonnee($courrier);

            $courrier->statut = CourrierStatut::PROJET_A_REDIGER;
            $courrier->projet_renvoi_observation = $observation;
            $courrier->projet_renvoye_at = now();
            $courrier->projet_renvoye_par_id = $utilisateur->id;
            $courrier->save();
            $this->tracerTransition($courrier, $utilisateur);

            $this->audit->enregistrer('courrier.projet_renvoye_pour_correction', $courrier, $utilisateur, [
                'observation' => $observation,
            ]);

            return $courrier;
        });
    }

    public function signer(Courrier $courrier, User $utilisateur): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur) {
            $sourceAutorite = $this->autoriteDg->source($utilisateur);
            if ($sourceAutorite === null) {
                throw TransitionNonAutoriseeException::posteNonHabilite();
            }
            $courrier = $this->lockCourrierFrais($courrier);
            $this->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::SIGNE);
            $this->dossiers->assertCourrierActifPourNouvelleActivite($courrier);

            if (! $courrier->relectureEstValidee()) {
                throw new RelectureNonValideeException;
            }
            $mission = $courrier->missionProjetReponse()->first();
            if ($mission !== null && ($mission->type !== MissionDocumentaireType::PREPARATION_REPONSE
                || $mission->statut !== MissionDocumentaireStatut::RETOURNEE)) {
                throw ValidationException::withMessages(['mission' => 'La mission de préparation de réponse doit être terminée avant signature.']);
            }

            $courrier->signataire_id = $utilisateur->id;
            $courrier->signe_at = now();
            $courrier->statut = CourrierStatut::SIGNE;

            // Numéro de départ attribué exactement à la signature (pas à
            // l'envoi effectif, voir docs/lot 2) : un courrier sortant
            // signé porte déjà sa référence officielle, même si sa remise
            // matérielle suit de quelques heures ou jours.
            if ($courrier->sens === SensCourrier::SORTANT) {
                if (blank($courrier->destinataire_externe_nom)
                    && ($courrier->missionProjetReponse()->exists()
                        || AuditLog::query()->where('auditable_type', $courrier->getMorphClass())->where('auditable_id', $courrier->id)->where('action', 'courrier.sortant_prepare')->exists())) {
                    throw ValidationException::withMessages(['destinataire_externe_nom' => 'Le destinataire doit être déterminé avant la signature.']);
                }
                $courrier->numero_depart = $this->numeros->genererNumeroDepart();
            }

            // PDF définitif généré exactement ici, jamais avant (le contenu du
            // projet de réponse reste modifiable jusqu'à cet instant précis) et
            // jamais régénéré ensuite : posé dans la même sauvegarde que la
            // transition elle-même, pour qu'il n'existe jamais d'état
            // "signé sans PDF".
            $courrier->pdf_chemin = $this->pdf->generer($courrier);
            $courrier->pdf_sha256 = EmpreinteFichier::pourFichierStocke($courrier->pdf_chemin);
            $courrier->save();
            $this->tracerTransition($courrier, $utilisateur);

            if ($courrier->numero_depart !== null) {
                $this->auditerIdentite($courrier, $utilisateur, 'numero_depart', $courrier->numero_depart);
            }

            $this->audit->enregistrer('courrier.signature', $courrier, $utilisateur, [
                'description' => "Signature du courrier {$courrier->numero_accuse_reception}",
                'source_autorite' => $sourceAutorite,
                'numero_depart' => $courrier->numero_depart,
            ]);

            return $courrier;
        });
    }

    private function assertMissionPreparationActive(MissionDocumentaire $mission, User $assistant): void
    {
        if (
            $mission->type !== MissionDocumentaireType::PREPARATION_REPONSE
            || $mission->autorite_poste !== Poste::DG
            || $mission->assistant_id !== $assistant->id
            || ! in_array($mission->statut, [MissionDocumentaireStatut::ASSIGNEE, MissionDocumentaireStatut::EN_COURS], true)
        ) {
            throw TransitionNonAutoriseeException::posteNonHabilite();
        }
    }

    public function enregistrer(
        Courrier $courrier,
        User $utilisateur,
        CourrierClassification $classification,
        ?string $noteTechnique,
        ?string $accuseReceptionPartenaire,
        ?string $emplacementPhysique = null,
    ): Courrier {
        return DB::transaction(function () use ($courrier, $utilisateur, $classification, $noteTechnique, $accuseReceptionPartenaire, $emplacementPhysique) {
            $courrier = $this->lockCourrierFrais($courrier);

            if ($courrier->statut === CourrierStatut::RECU && $courrier->mode_reception === ModeReception::DEPOT_EN_LIGNE) {
                if ($utilisateur->poste !== Poste::RECEPTION) {
                    throw TransitionNonAutoriseeException::posteNonHabilite();
                }
                if ($courrier->numero_enregistrement !== null) {
                    throw ValidationException::withMessages([
                        'courrier' => 'Ce dépôt public est déjà enregistré.',
                    ]);
                }

                $courrier->numero_enregistrement = $this->numeros->genererNumeroEnregistrement();
                $courrier->classification = $classification;
                $courrier->note_technique = $noteTechnique;
                $courrier->accuse_reception_partenaire = $accuseReceptionPartenaire;
                $courrier->cote_classement = $this->genererCoteClassement($courrier);
                if ($emplacementPhysique !== null) {
                    $courrier->emplacement_physique = $emplacementPhysique;
                }
                $courrier->enregistre_at = now();
                $courrier->save();
                $this->auditerIdentite($courrier, $utilisateur, 'numero_enregistrement', $courrier->numero_enregistrement);

                return $courrier;
            }

            $this->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::ENREGISTRE);

            $courrier->classification = $classification;
            $courrier->note_technique = $noteTechnique;
            $courrier->accuse_reception_partenaire = $accuseReceptionPartenaire;
            $courrier->cote_classement = $this->genererCoteClassement($courrier);
            if ($emplacementPhysique !== null) {
                $courrier->emplacement_physique = $emplacementPhysique;
            }
            $courrier->enregistre_at = now();
            $courrier->statut = CourrierStatut::ENREGISTRE;
            $courrier->save();
            $this->tracerTransition($courrier, $utilisateur);

            return $courrier;
        });
    }

    /**
     * Crée le courrier de réponse comme enregistrement à part entière
     * (sens=sortant), lié à l'original par en_reponse_a_courrier_id —
     * plutôt qu'un simple champ de l'original (voir docs, lot 2). Démarre
     * directement à en_relecture, le relecteur étant désigné à la
     * création (même principe que soumettreProjetReponse() pour la
     * réponse historique) : rédiger sans relecteur désigné n'a pas de sens,
     * il n'y aurait personne à qui la transition suivante serait destinée.
     */
    public function initierReponseSortante(User $auteur, Courrier $original, array $donnees): Courrier
    {
        if ($auteur->poste === Poste::SECRETARIAT_1) {
            throw ValidationException::withMessages([
                'courrier' => 'Une réponse décidée par la DG doit être préparée dans le cadre d’une mission nominative.',
            ]);
        }

        $estResponsableConcerne = $auteur->role->estDirecteurDirection()
            && in_array($auteur->direction_id, [$original->direction_origine_id, $original->direction_destination_id], true);
        if (! $estResponsableConcerne) {
            throw TransitionNonAutoriseeException::posteNonHabilite();
        }

        return $this->creerCourrierSortant($auteur, $donnees, $original);
    }

    /**
     * Courrier sortant proactif, sans courrier d'arrivée déclencheur — une
     * direction qui écrit de sa propre initiative à un partenaire externe
     * (pas seulement en réponse à quelque chose déjà reçu). Même circuit
     * de relecture et de signature que initierReponseSortante().
     */
    public function initierCourrierSortant(User $auteur, array $donnees): Courrier
    {
        if (! $auteur->role->estDirecteurDirection()) {
            throw TransitionNonAutoriseeException::posteNonHabilite();
        }

        return $this->creerCourrierSortant($auteur, $donnees, null);
    }

    private function creerCourrierSortant(User $auteur, array $donnees, ?Courrier $original): Courrier
    {
        $this->assertRelecteurNonSec2((int) $donnees['relecteur_id']);

        return DB::transaction(function () use ($auteur, $donnees, $original) {
            $courrier = Courrier::query()->create([
                ...$donnees,
                'objet' => $donnees['objet'] ?? ($original ? 'Réponse à : '.$original->objet : $donnees['objet'] ?? null),
                'type' => CourrierType::CORRESPONDANCE_GENERALE,
                'sens' => SensCourrier::SORTANT,
                'en_reponse_a_courrier_id' => $original?->id,
                'dossier_id' => $original?->dossier_id,
                // Visible par la même direction que le rédacteur (ou celle
                // de l'original s'il y en a un) — jamais
                // direction_destination_id : le destinataire est externe,
                // suivi via destinataire_externe_nom, pas une direction.
                'direction_origine_id' => $original?->direction_origine_id ?? $auteur->direction_id,
                // Courrier sortant institutionnel : aucun AR public.
                'numero_accuse_reception' => null,
                'statut' => CourrierStatut::EN_RELECTURE,
                'necessite_avis_dg' => false,
                'initie_par_dg' => false,
                'created_by' => $auteur->id,
            ]);

            $this->tracerTransition($courrier, $auteur);

            if ($original !== null) {
                $this->relationsDocumentaires->relier($courrier, $original, DocumentRelationType::REPONSE_A, $auteur);
            }

            $this->audit->enregistrer('courrier.sortant_prepare', $courrier, $auteur, [
                'destinataire_predefini' => filled($courrier->destinataire_externe_nom),
            ]);

            return $courrier;
        });
    }

    /**
     * Marque l'envoi effectif d'un courrier sortant signé : numéro de
     * départ, destinataire externe, mode d'expédition, date d'envoi.
     * Distinct d'enregistrer() (qui numérote un courrier ENTRANT) — un
     * courrier sortant n'entre jamais dans le registre arrivée.
     */
    public function envoyer(Courrier $courrier, User $utilisateur, array $donnees): Courrier
    {
        $courrier = DB::transaction(function () use ($courrier, $utilisateur, $donnees) {
            $courrier = $this->lockCourrierFrais($courrier);
            $this->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::ENVOYE);
            if ($courrier->signe_at === null || $courrier->signataire_id === null
                || ! $courrier->signataire()->exists() || blank($courrier->numero_depart)
                || ! $courrier->relectureEstValidee()) {
                throw ValidationException::withMessages(['courrier' => 'La signature, la relecture validée et le numéro de départ sont requis avant envoi.']);
            }
            $signataire = $courrier->signataire;
            if ($signataire->poste !== Poste::DG
                && ! $courrier->transitions()->where('statut', CourrierStatut::SIGNE)
                    ->where('changed_by_id', $signataire->id)->where('agi_en_interim', true)->exists()) {
                throw ValidationException::withMessages(['courrier' => 'La signature institutionnelle autorisée est requise avant envoi.']);
            }
            app(PdfOfficielIntegrity::class)->verifier($courrier);

            $origine = $this->origineExternePubliqueDeLaReponse($courrier);
            if ($origine !== null) {
                $emailSaisi = mb_strtolower(trim((string) ($donnees['destinataire_externe_email'] ?? '')));
                if ($emailSaisi !== mb_strtolower((string) $origine->expediteur_externe_email)) {
                    throw ValidationException::withMessages([
                        'destinataire_externe_email' => "L'adresse doit correspondre à celle du demandeur externe à l'origine de cette réponse.",
                    ]);
                }
                $donnees['destinataire_externe_nom'] = $origine->expediteur_externe_nom;
                $donnees['destinataire_externe_email'] = $origine->expediteur_externe_email;
            } elseif ($courrier->missionProjetReponse()->exists() || filled($courrier->destinataire_externe_nom)) {
                $nomSaisi = trim((string) ($donnees['destinataire_externe_nom'] ?? ''));
                $emailSaisi = mb_strtolower(trim((string) ($donnees['destinataire_externe_email'] ?? '')));
                $emailProjet = mb_strtolower(trim((string) ($courrier->destinataire_externe_email ?? '')));
                if ($nomSaisi !== (string) $courrier->destinataire_externe_nom || $emailSaisi !== $emailProjet) {
                    throw ValidationException::withMessages([
                        'destinataire_externe_nom' => 'Le destinataire final doit correspondre au projet validé et signé.',
                    ]);
                }
                $donnees['destinataire_externe_nom'] = $courrier->destinataire_externe_nom;
                $donnees['destinataire_externe_email'] = $courrier->destinataire_externe_email;
            }

            $courrier->destinataire_externe_nom = $donnees['destinataire_externe_nom'] ?? null;
            $courrier->destinataire_externe_email = $donnees['destinataire_externe_email'] ?? null;
            $courrier->mode_expedition = $donnees['mode_expedition'] ?? null;
            $courrier->date_envoi = now();
            $courrier->statut = CourrierStatut::ENVOYE;
            $courrier->save();
            $this->tracerTransition($courrier, $utilisateur);

            $this->audit->enregistrer('courrier.envoye', $courrier, $utilisateur, [
                'date_envoi' => $courrier->date_envoi,
                'destinataire' => $courrier->destinataire_externe_email ?? $courrier->destinataire_externe_nom,
                'numero_depart' => $courrier->numero_depart,
            ]);

            return $courrier;
        });

        DB::afterCommit(fn () => $this->notifierReponseExterneEnvoyee($courrier, $utilisateur));

        return $courrier;
    }

    private function notifierReponseExterneEnvoyee(Courrier $reponse, User $acteur): void
    {
        $origine = $this->origineExternePubliqueDeLaReponse($reponse);
        if ($origine === null || blank($reponse->pdf_chemin) || blank($reponse->pdf_sha256)) {
            return;
        }

        $ttl = max(1, (int) config('courrier.reponse_externe.lien_ttl_minutes', 10080));
        $url = URL::temporarySignedRoute(
            'api.public.reponses.telecharger',
            now()->addMinutes($ttl),
            ['courrier' => $reponse->id],
        );

        try {
            $this->notifications->envoyerMail(
                $origine->expediteur_externe_email,
                new ReponseFinaleCourrierExterneMail($origine, $reponse, $url),
            );
            $this->audit->enregistrer('courrier.reponse_finale_notifiee', $reponse, $acteur, [
                'dossier_id' => $reponse->dossier_id,
                'courrier_origine_id' => $origine->id,
                'destinataire' => $origine->expediteur_externe_email,
                'type_notification' => 'reponse_finale',
                'numero_depart' => $reponse->numero_depart,
                'notifiee_at' => now()->toISOString(),
            ]);
        } catch (Throwable $exception) {
            Log::error('Échec de mise en file de la réponse finale externe.', [
                'courrier_id' => $reponse->id,
                'dossier_id' => $reponse->dossier_id,
                'exception' => $exception,
            ]);
            $this->audit->enregistrer('courrier.reponse_finale_notification_echec', $reponse, $acteur, [
                'dossier_id' => $reponse->dossier_id,
                'courrier_origine_id' => $origine->id,
                'type_notification' => 'reponse_finale',
            ]);
        }
    }

    private function origineExternePubliqueDeLaReponse(Courrier $reponse): ?Courrier
    {
        if ($reponse->sens !== SensCourrier::SORTANT || $reponse->en_reponse_a_courrier_id === null) {
            return null;
        }

        $origine = Courrier::withoutGlobalScopes()
            ->whereKey($reponse->en_reponse_a_courrier_id)
            ->where('dossier_id', $reponse->dossier_id)
            ->where('sens', SensCourrier::ENTRANT)
            ->whereNotNull('expediteur_externe_email')
            ->first();

        return $origine !== null && filter_var($origine->expediteur_externe_email, FILTER_VALIDATE_EMAIL) ? $origine : null;
    }

    private function assertRelecteurNonSec2(int $relecteurId): void
    {
        if (User::query()->whereKey($relecteurId)->where('poste', Poste::SECRETARIAT_2)->exists()) {
            throw ValidationException::withMessages(['relecteur_id' => 'SEC2 ne peut pas être désigné comme relecteur dans un nouveau circuit.']);
        }
    }

    /**
     * Preuve de remise — un courrier sortant confié à un porteur n'est
     * réellement délivré qu'une fois la décharge signée rapportée.
     * Distinct de l'envoi lui-même : un courrier peut être "envoyé" (parti
     * de l'Office) sans que la remise soit encore confirmée.
     */
    public function enregistrerRemise(Courrier $courrier, User $utilisateur, array $donnees): Courrier
    {
        if ($courrier->statut !== CourrierStatut::ENVOYE) {
            throw TransitionNonAutoriseeException::sautDetape();
        }

        $courrier->remis_le = now();
        $courrier->remis_a = $donnees['remis_a'];
        $courrier->mode_remise = $donnees['mode_remise'];
        $courrier->decharge_remise_chemin = $donnees['decharge_remise_chemin'] ?? null;
        $courrier->save();

        $this->audit->enregistrer('courrier.remise_confirmee', $courrier, $utilisateur, [
            'description' => "Remise confirmée pour le courrier {$courrier->numero_depart}",
        ]);

        return $courrier;
    }

    public function ajouterAnnotation(Courrier $courrier, User $auteur, string $contenu): CourrierAnnotation
    {
        return DB::transaction(function () use ($courrier, $auteur, $contenu) {
            /** @var CourrierAnnotation $annotation */
            $annotation = $courrier->annotations()->create([
                'auteur_id' => $auteur->id,
                'contenu' => $contenu,
            ]);

            $this->audit->enregistrer('courrier.annotation_creee', $courrier, $auteur, [
                'annotation_id' => $annotation->id,
                // Le contenu reste dans la table métier protégée ; le journal
                // ne conserve qu'une donnée non sensible utile au contrôle.
                'contenu_longueur' => mb_strlen($contenu),
            ]);

            return $annotation;
        });
    }

    /**
     * Sortie de l'original physique — voir docs/numerisation-courrier.md
     * (Lot 5). Refuse une nouvelle sortie tant que la précédente n'a pas
     * été restituée : un original ne peut matériellement être qu'à un seul
     * endroit à la fois.
     */
    public function sortirOriginal(Courrier $courrier, User $utilisateur, string $motif): EmpruntOriginal
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $motif) {
            $courrier = $this->lockCourrierFrais($courrier);

            if ($courrier->empruntEnCours() !== null) {
                throw EmpruntOriginalException::dejaEmprunte();
            }

            /** @var EmpruntOriginal $emprunt */
            $emprunt = $courrier->empruntsOriginaux()->create([
                'emprunte_par_id' => $utilisateur->id,
                'emprunte_le' => now(),
                'motif' => $motif,
            ]);

            $this->audit->enregistrer('courrier.original_sorti', $courrier, $utilisateur, [
                'emprunt_id' => $emprunt->id,
                'emprunte_le' => $emprunt->emprunte_le?->toIso8601String(),
            ]);

            return $emprunt;
        });
    }

    public function restituerOriginal(Courrier $courrier, User $utilisateur): EmpruntOriginal
    {
        return DB::transaction(function () use ($courrier, $utilisateur) {
            $courrier = $this->lockCourrierFrais($courrier);
            $emprunt = $courrier->empruntEnCours();

            if ($emprunt === null) {
                throw EmpruntOriginalException::aucunEmpruntEnCours();
            }

            $emprunt->update(['restitue_le' => now(), 'restitue_par_id' => $utilisateur->id]);

            $this->audit->enregistrer('courrier.original_restitue', $courrier, $utilisateur, [
                'emprunt_id' => $emprunt->id,
                'emprunte_par_id' => $emprunt->emprunte_par_id,
                'restitue_le' => $emprunt->restitue_le?->toIso8601String(),
            ]);

            return $emprunt;
        });
    }

    private function notifierDgApresCommit(Courrier $courrier, ?string $evenement = null): void
    {
        $evenement ??= DocumentProduitDirection::query()->where('courrier_id', $courrier->id)->exists()
            ? 'retour_direction_a_decider' : 'pret_a_traiter';
        DB::afterCommit(function () use ($courrier, $evenement) {
            $interim = $this->autoriteDg->interimOuvert();
            if ($interim !== null) {
                $destinataire = User::query()->find($interim->dga_interimaire_id);
                if ($destinataire !== null) {
                    $this->notifications->notifier($destinataire, new CourrierDgNotification($courrier, $evenement));
                }

                return;
            }
            User::query()->where('poste', Poste::DG)->each(fn (User $dg) => $this->notifications->notifier($dg, new CourrierDgNotification($courrier, $evenement)));
        });
    }
}
