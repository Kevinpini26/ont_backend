<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Contracts\CircuitTransitionRules;
use Modules\Courrier\Contracts\NumeroGenerator;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Enums\ModeReception;
use Modules\Courrier\Enums\NumerisationStatut;
use Modules\Courrier\Enums\SensCourrier;
use Modules\Courrier\Exceptions\TransitionNonAutoriseeException;
use Modules\Courrier\Mail\AccuseReceptionCandidatMail;
use Modules\Courrier\Mail\AccuseReceptionCourrierExterneMail;
use Modules\Courrier\Mail\CourrierRecuMail;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierTransition;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\InstructionCourrierDg;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;
use Modules\Kernel\Support\DgAuthorityResolver;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Création et réception des courriers (circuit interne comme dépôts
 * publics) — extrait de CourrierCircuitService, voir ce fichier pour
 * l'orchestration publique.
 */
class CourrierReceptionService
{
    public function __construct(
        private readonly CircuitTransitionRules $regles,
        private readonly NumeroGenerator $numeros,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly DelegationResolver $delegations,
        private readonly DgAuthorityResolver $autoriteDg,
        private readonly DocumentProduitScanOfficielService $scansOfficiels,
        private readonly CourrierCircuitHelpers $helpers,
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

            $this->helpers->tracerTransition($courrier, $auteur);
            if ($courrier->numero_enregistrement !== null) {
                $this->helpers->auditerIdentite($courrier, $auteur, 'numero_enregistrement', $courrier->numero_enregistrement);
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
                $this->notifications->envoyerMail($responsable->email, new CourrierRecuMail($courrier), 'courrier.recu_direction', $courrier);
            }
        }

        $this->accuserReceptionGuichet($courrier);

        return $courrier;
    }

    /**
     * Un courrier enregistré au guichet n'a pas de numero_accuse_reception
     * public (voir creer() ci-dessus) : l'email de confirmation à
     * l'expéditeur ou au candidat n'offre donc aucun lien de suivi, juste
     * la confirmation elle-même. Sans adresse email captée, rien n'est
     * envoyé automatiquement ; l'agent imprime la fiche de secours via
     * GET /courriers/{courrier}/accuse-reception-guichet
     * (CourrierController::accuseReceptionGuichet()).
     */
    private function accuserReceptionGuichet(Courrier $courrier): void
    {
        $estDemandeStage = $courrier->type === CourrierType::DEMANDE_STAGE;
        $email = $estDemandeStage ? $courrier->candidat_email : $courrier->expediteur_externe_email;
        if (blank($email)) {
            return;
        }

        $mail = $estDemandeStage ? new AccuseReceptionCandidatMail($courrier) : new AccuseReceptionCourrierExterneMail($courrier);
        $type = $estDemandeStage ? 'stagiaire.accuse_reception_guichet' : 'courrier.accuse_reception_guichet';

        try {
            $this->notifications->envoyerMail($email, $mail, $type, $courrier, $this->agentsReception());
            $this->audit->enregistrer('courrier.accuse_reception_envoye', $courrier, null, [
                'destinataire' => $email,
                'type_notification' => 'accuse_reception_guichet',
                'envoye_at' => now()->toISOString(),
            ]);
        } catch (Throwable $exception) {
            Log::error("Échec de mise en file de l'accusé de réception guichet.", [
                'courrier_id' => $courrier->id,
                'exception' => $exception,
            ]);
            $this->audit->enregistrer('courrier.accuse_reception_echec', $courrier, null, [
                'type_notification' => 'accuse_reception_guichet',
            ]);
        }
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

            $this->helpers->tracerTransition($courrier, null);
            $this->helpers->auditerIdentite($courrier, null, 'numero_enregistrement', $courrier->numero_enregistrement);

            return $courrier;
        });

        if ($courrier->candidat_email) {
            $this->notifications->envoyerMail(
                $courrier->candidat_email,
                new AccuseReceptionCandidatMail($courrier),
                'stagiaire.accuse_reception_demande',
                $courrier,
                $this->agentsReception(),
            );
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
                $this->notifications->envoyerMail(
                    $courrier->expediteur_externe_email,
                    new AccuseReceptionCourrierExterneMail($courrier),
                    'courrier.accuse_reception_externe',
                    $courrier,
                    $this->agentsReception(),
                );
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
        $this->helpers->assertRelecteurNonSec2((int) $donnees['relecteur_id']);
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

            $this->helpers->tracerTransition($courrier, $secretariat1);

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
            $this->helpers->tracerTransition($courrier, $secretariat1);

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
            $this->notifications->envoyerMail($responsable->email, new CourrierRecuMail($courrier), 'courrier.recu_direction', $courrier);
        }

        return $courrier;
    }

    /**
     * Agents actuellement au poste Réception — destinataires de l'alerte
     * interne quand un accusé de réception externe échoue définitivement
     * (voir Modules\Kernel\Jobs\EnvoyerNotificationEmailJob::failed()).
     *
     * @return array<int, User>
     */
    private function agentsReception(): array
    {
        return User::query()
            ->where('role', UserRole::AGENT_CIRCUIT_COURRIER)
            ->where('poste', Poste::RECEPTION)
            ->get()
            ->all();
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
            $courrier = $this->helpers->lockCourrierFrais($courrier);
            $this->helpers->assertTransitionAutorisee($courrier, $dg, CourrierStatut::EN_RELECTURE);

            $courrier->valide_par_dg_at = now();
            $courrier->statut = CourrierStatut::EN_RELECTURE;
            $courrier->save();
            $this->helpers->tracerTransition($courrier, $dg);

            $this->audit->enregistrer('courrier.valide_par_dg', $courrier, $dg, ['source_autorite' => $sourceAutorite]);

            return $courrier;
        });
    }

    /**
     * Règle de cotation provisoire, voir docs/questions-ont.md : la règle
     * réelle du classement physique reste à confirmer avec le Secrétariat
     * Général. "ONT" en repli quand aucune direction n'est imputée
     * (courrier interne à la DG, par exemple) — jamais une cote vide.
     *
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

    /**
     * Décharge explicite du destinataire d'un bordereau, préalable
     * obligatoire à toute action sur le dossier (voir
     * CourrierCircuitHelpers::assertDechargeDonnee()). L'habilitation ici
     * est volontairement recalculée à la volée (pas fondée uniquement sur
     * le destinataire_poste déjà enregistré sur le bordereau) pour rester
     * alignée avec l'action de circuit qu'elle débloque, garde d'intérim
     * de la DGA comprise.
     */
    public function accuserReception(Courrier $courrier, User $utilisateur): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur) {
            $this->autoriteDg->source($utilisateur);
            $courrier = $this->helpers->lockCourrierFrais($courrier);

            $bordereau = $courrier->bordereauCourant();

            if ($bordereau === null) {
                throw TransitionNonAutoriseeException::sautDetape();
            }

            if ($courrier->dossier_id === null) {
                $dispatchs = $courrier->dispatchs()->lockForUpdate()->get();
                if ($dispatchs->count() !== 1) {
                    throw TransitionNonAutoriseeException::sautDetape();
                }
                $this->scansOfficiels->verifierPourSec2($dispatchs->sole());
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

    public function prendreEnChargeAutonomeSec1(DocumentProduitDirection $document, User $utilisateur): Courrier
    {
        return DB::transaction(function () use ($document, $utilisateur) {
            $document = DocumentProduitDirection::query()->lockForUpdate()->findOrFail($document->id);
            $utilisateur = User::query()->lockForUpdate()->findOrFail($utilisateur->id);
            $courrier = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($document->courrier_id);

            $this->assertAutonomeAccepteSec1($document, $courrier, $utilisateur, CourrierStatut::RECU);
            $this->scansOfficiels->verifierPourSec1($document);

            $transition = CourrierTransition::query()
                ->where('courrier_id', $courrier->id)
                ->orderByDesc('id')
                ->first();
            if ($transition === null
                || $transition->nouveau_statut !== CourrierStatut::RECU
                || $transition->destinataire_poste !== Poste::SECRETARIAT_1->value) {
                throw TransitionNonAutoriseeException::sautDetape();
            }
            if ($transition->accuse_reception_at !== null) {
                throw new ConflictHttpException('Le document a déjà été pris en charge par SEC1.');
            }

            $courrier = $this->accuserReception($courrier, $utilisateur);
            $this->audit->enregistrer('document_direction.autonome_pris_en_charge_sec1', $document, $utilisateur, [
                'courrier_id' => $courrier->id,
                'numero_enregistrement' => $courrier->numero_enregistrement,
                'prise_en_charge_at' => now()->toIso8601String(),
            ]);

            return $courrier;
        });
    }

    public function transmettreAutonomeDgDepuisSec1(DocumentProduitDirection $document, User $utilisateur): Courrier
    {
        return DB::transaction(function () use ($document, $utilisateur) {
            $document = DocumentProduitDirection::query()->lockForUpdate()->findOrFail($document->id);
            $utilisateur = User::query()->lockForUpdate()->findOrFail($utilisateur->id);
            $courrier = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($document->courrier_id);

            $this->assertAutonomeAccepteSec1($document, $courrier, $utilisateur, CourrierStatut::RECU);
            $this->scansOfficiels->verifierPourSec1($document);

            $transition = CourrierTransition::query()
                ->where('courrier_id', $courrier->id)
                ->orderByDesc('id')
                ->first();
            if ($transition === null
                || $transition->nouveau_statut !== CourrierStatut::RECU
                || $transition->destinataire_poste !== Poste::SECRETARIAT_1->value) {
                throw TransitionNonAutoriseeException::sautDetape();
            }
            if ($transition->accuse_reception_at === null) {
                throw ValidationException::withMessages([
                    'document' => 'SEC1 doit d’abord accuser réception du document avant de le transmettre à la DG.',
                ]);
            }
            if ($courrier->enTransit()) {
                throw TransitionNonAutoriseeException::dechargeNonDonnee();
            }

            $courrier->necessite_avis_dg = true;
            $courrier->statut = CourrierStatut::EN_ATTENTE_AVIS_DG;
            $courrier->save();
            $this->helpers->tracerTransition($courrier, $utilisateur);

            $dateTransmission = now();
            $this->audit->enregistrer('courrier.transmis_dg', $courrier, $utilisateur, [
                'depuis' => CourrierStatut::RECU->value,
                'document_autonome' => true,
            ]);
            $this->audit->enregistrer('document_direction.autonome_transmis_dg_sec1', $document, $utilisateur, [
                'courrier_id' => $courrier->id,
                'numero_enregistrement' => $courrier->numero_enregistrement,
                'reference_documentaire' => $courrier->reference_documentaire,
                'transmis_dg_at' => $dateTransmission->toIso8601String(),
            ]);
            $this->helpers->notifierDgApresCommit($courrier);

            return $courrier;
        });
    }

    private function assertAutonomeAccepteSec1(
        DocumentProduitDirection $document,
        Courrier $courrier,
        User $utilisateur,
        CourrierStatut $statutAttendu,
    ): void {
        if ($utilisateur->role !== UserRole::AGENT_CIRCUIT_COURRIER
            || ! $this->delegations->utilisateurHabilite($utilisateur, [Poste::SECRETARIAT_1])) {
            throw TransitionNonAutoriseeException::posteNonHabilite();
        }

        if ($document->origine !== DocumentProduitOrigine::AUTONOME
            || $document->statut !== DocumentProduitStatut::ENTRE_CIRCUIT
            || $document->traitement_direction_id !== null
            || $document->document_source_id !== null
            || $document->courrier_id !== $courrier->id
            || $document->transmis_reception_par_id === null
            || $document->transmis_reception_at === null
            || $document->recu_par_id === null
            || $document->recu_at === null
            || $document->scan_officiel_document_id === null
            || $courrier->direction_origine_id !== $document->direction_id
            || $courrier->statut !== $statutAttendu
            || $courrier->numero_enregistrement === null
            || trim($courrier->numero_enregistrement) === ''
            || ! is_string($courrier->reference_documentaire)
            || preg_match('/\A\d{3,}\/[A-Z]{1,12}\/[A-Z0-9]{1,20}\/ONT\/\d{4}\z/', $courrier->reference_documentaire) !== 1
            || $courrier->dispatchs()->exists()) {
            throw new ConflictHttpException('Le document autonome n’est plus dans un état cohérent pour une action de SEC1.');
        }
    }
}
