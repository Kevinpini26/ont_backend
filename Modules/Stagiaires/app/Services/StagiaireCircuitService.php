<?php

namespace Modules\Stagiaires\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\NotificationCanal;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\EmpreinteFichier;
use Modules\Stagiaires\Contracts\AffectationRules;
use Modules\Stagiaires\Contracts\AttestationGenerator;
use Modules\Stagiaires\Contracts\CalculateurNoteFinale;
use Modules\Stagiaires\Contracts\CertificatGenerator;
use Modules\Stagiaires\Contracts\ConventionGenerator;
use Modules\Stagiaires\Contracts\EngagementConfidentialiteGenerator;
use Modules\Stagiaires\Contracts\NoteAffectationGenerator;
use Modules\Stagiaires\Contracts\NotificationAvecSms;
use Modules\Stagiaires\Contracts\SequenceGenerator;
use Modules\Stagiaires\Enums\DocumentType;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Enums\StagiaireTypeStage;
use Modules\Stagiaires\Enums\TypeLienPublic;
use Modules\Stagiaires\Exceptions\QuotaDirectionAtteintException;
use Modules\Stagiaires\Exceptions\StagiaireTransitionException;
use Modules\Stagiaires\Mail\StagiaireAffecteMail;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Models\StagiaireDocument;
use Modules\Stagiaires\Models\StagiaireLienPublic;
use Modules\Stagiaires\Models\StagiairePresence;
use Modules\Stagiaires\Models\StagiaireProlongation;
use Modules\Stagiaires\Models\StagiaireSuivi;
use Modules\Stagiaires\Notifications\ConventionASignerNotification;
use Modules\Stagiaires\Notifications\EngagementConfidentialiteASignerNotification;
use Modules\Stagiaires\Notifications\RapportStageDemandeNotification;
use Modules\Stagiaires\Notifications\RetourExperienceDemandeNotification;
use Modules\Stagiaires\Notifications\StagiaireAffecteNotification;

class StagiaireCircuitService
{
    public function __construct(
        private readonly AffectationRules $affectationRules,
        private readonly CalculateurNoteFinale $calculateur,
        private readonly AttestationGenerator $attestations,
        private readonly ConventionGenerator $conventions,
        private readonly NoteAffectationGenerator $notesAffectation,
        private readonly CertificatGenerator $certificats,
        private readonly EngagementConfidentialiteGenerator $engagementsConfidentialite,
        private readonly SequenceGenerator $sequences,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly NotificationCanal $smsCanal,
    ) {}

    private function assertStatut(Stagiaire $stagiaire, StagiaireStatut $attendu): void
    {
        if ($stagiaire->statut !== $attendu) {
            throw new StagiaireTransitionException(
                "Action impossible : le dossier est au statut «{$stagiaire->statut->label()}», pas «{$attendu->label()}»."
            );
        }
    }

    public function examinerDossier(Stagiaire $stagiaire): Stagiaire
    {
        $this->assertStatut($stagiaire, StagiaireStatut::DOSSIER_RECU);

        $stagiaire->statut = StagiaireStatut::EN_ATTENTE_AFFECTATION;
        $stagiaire->save();

        return $stagiaire;
    }

    /**
     * Lot 5 (verrouiller la diffusion) : plus jamais une action directe de
     * la DFP — appelée uniquement par
     * TableauRepartitionCircuitService::rendreAvis() lors de l'approbation
     * d'un tableau, une fois par ligne, à l'intérieur de la même
     * transaction que l'approbation elle-même. C'est cette seule action,
     * un seul horodatage, qui rend l'affectation réelle et déclenche tout
     * le reste (note d'affectation, notification à la direction) —
     * jusque-là le dossier reste "en instruction" (voir
     * StagiaireStatut::EN_INSTRUCTION), sans effet visible à l'extérieur.
     * Le contrôle de quota se fait ici, au moment où ça compte vraiment,
     * pas à l'ajout d'une ligne au tableau — sans possibilité de
     * dérogation "hors quota" (l'ancienne option forcer/justification de
     * l'action directe disparaît avec elle ; voir docs/questions-ont.md
     * si un besoin réel de dérogation via tableau se confirme).
     */
    public function affecter(
        Stagiaire $stagiaire,
        User $dfp,
        int $directionId,
        string $encadrantPressenti,
    ): Stagiaire {
        $this->assertStatut($stagiaire, StagiaireStatut::EN_INSTRUCTION);

        if (! $this->affectationRules->estEligible($directionId)) {
            throw new StagiaireTransitionException("Direction non éligible à l'affectation (inactive ou inexistante).");
        }

        return DB::transaction(function () use ($stagiaire, $dfp, $directionId, $encadrantPressenti) {
            // forcer: false en dur — plus de dérogation "hors quota" pour
            // cette action automatique (voir docblock de la méthode).
            $this->verrouillerEtVerifierQuota($directionId, forcer: false);

            $stagiaire->direction_id = $directionId;
            $stagiaire->affecte_par_id = $dfp->id;
            $stagiaire->affecte_at = now();
            $stagiaire->statut = StagiaireStatut::AFFECTE;
            $stagiaire->maitre_stage = $encadrantPressenti;
            // Attribué à l'affectation, jamais avant : un dossier encore en
            // attente n'a pas d'existence administrative dans une direction,
            // donc pas encore d'identifiant stable.
            if ($stagiaire->matricule === null) {
                $stagiaire->matricule = sprintf(
                    config('stagiaires.format_matricule'),
                    now()->year,
                    $this->sequences->suivant('matricule', now()->year),
                );
            }
            $stagiaire->save();

            $cheminNoteAffectation = $this->notesAffectation->generer($stagiaire);
            $stagiaire->documents()->create([
                'type' => DocumentType::NOTE_AFFECTATION,
                'nom_original' => "note-affectation-{$stagiaire->nom}.pdf",
                'chemin' => $cheminNoteAffectation,
                'sha256' => EmpreinteFichier::pourFichierStocke($cheminNoteAffectation),
                'uploaded_by_id' => $dfp->id,
            ]);

            $this->audit->enregistrer('stagiaire.affectation', $stagiaire, $dfp, [
                'direction_id' => $directionId,
            ]);

            $this->notifierResponsablesAffectation($stagiaire, $directionId);

            return $stagiaire;
        });
    }

    /**
     * Verrou sur la direction ciblée : sérialise deux affectations (ou
     * réaffectations) concurrentes vers la même direction — sans lui,
     * estSaturee() peut être lue par les deux requêtes avant qu'aucune
     * n'ait sauvegardé, et le quota se retrouve dépassé sans jamais
     * déclencher le mode hors-quota (donc sans justification ni trace
     * d'audit). À appeler à l'intérieur d'une DB::transaction().
     */
    private function verrouillerEtVerifierQuota(int $directionId, bool $forcer): bool
    {
        Direction::query()->whereKey($directionId)->lockForUpdate()->firstOrFail();

        $horsQuota = $this->affectationRules->estSaturee($directionId);

        if ($horsQuota && ! $forcer) {
            throw new QuotaDirectionAtteintException;
        }

        return $horsQuota;
    }

    private function notifierResponsablesAffectation(Stagiaire $stagiaire, int $directionId): void
    {
        $responsables = User::query()
            ->where('direction_id', $directionId)
            ->where('role', 'responsable_direction')
            ->get();

        foreach ($responsables as $responsable) {
            $this->notifications->notifier($responsable, new StagiaireAffecteNotification($stagiaire));
            $this->notifications->envoyerMail($responsable->email, new StagiaireAffecteMail($stagiaire));
        }
    }

    /**
     * Réaffectation en cours de stage (changement de direction d'accueil
     * après l'affectation initiale) : contrairement à affecter(), la
     * justification est toujours obligatoire (traçabilité systématique,
     * pas seulement en cas de dérogation de quota) et le statut du
     * stagiaire n'est pas modifié — une réaffectation en stage_en_cours le
     * reste.
     */
    public function reaffecter(
        Stagiaire $stagiaire,
        User $dfp,
        int $nouvelleDirectionId,
        string $justification,
        bool $forcer = false,
    ): Stagiaire {
        if (! in_array($stagiaire->statut, [
            StagiaireStatut::AFFECTE,
            StagiaireStatut::STAGE_EN_COURS,
            StagiaireStatut::EVALUATION_EN_COURS,
        ], true)) {
            throw new StagiaireTransitionException(
                "Action impossible : le dossier est au statut «{$stagiaire->statut->label()}», qui ne permet pas de réaffectation."
            );
        }

        if ($nouvelleDirectionId === $stagiaire->direction_id) {
            throw new StagiaireTransitionException('La nouvelle direction doit être différente de la direction actuelle.');
        }

        if (! $this->affectationRules->estEligible($nouvelleDirectionId)) {
            throw new StagiaireTransitionException("Direction non éligible à l'affectation (inactive ou inexistante).");
        }

        return DB::transaction(function () use ($stagiaire, $dfp, $nouvelleDirectionId, $justification, $forcer) {
            $horsQuota = $this->verrouillerEtVerifierQuota($nouvelleDirectionId, $forcer);

            $ancienneDirectionId = $stagiaire->direction_id;

            $stagiaire->direction_id = $nouvelleDirectionId;
            $stagiaire->affecte_par_id = $dfp->id;
            $stagiaire->affecte_hors_quota = $horsQuota;
            $stagiaire->save();

            $this->audit->enregistrer('stagiaire.reaffectation', $stagiaire, $dfp, [
                'ancienne_direction_id' => $ancienneDirectionId,
                'nouvelle_direction_id' => $nouvelleDirectionId,
                'justification' => $justification,
            ]);

            if ($horsQuota) {
                $this->audit->enregistrer('stagiaire.reaffectation_hors_quota', $stagiaire, $dfp, [
                    'direction_id' => $nouvelleDirectionId,
                    'justification' => $justification,
                ]);
            }

            $this->notifierResponsablesAffectation($stagiaire, $nouvelleDirectionId);

            return $stagiaire;
        });
    }

    /**
     * $dateFin est ignorée pour un stage professionnel : sa durée initiale
     * est fixée à 3 mois à compter de la date de début, calculée ici — au-delà,
     * seule une prolongation tracée (prolongerStage()) peut la repousser.
     * Un stage académique garde sa date de fin librement choisie par la DFP.
     */
    public function validerArrivee(Stagiaire $stagiaire, Carbon $dateDebut, ?Carbon $dateFin): Stagiaire
    {
        $this->assertStatut($stagiaire, StagiaireStatut::AFFECTE);

        $stagiaire->date_debut_stage = $dateDebut;
        $stagiaire->date_fin_stage = $stagiaire->type_stage === StagiaireTypeStage::PROFESSIONNEL
            ? $dateDebut->copy()->addMonths(3)
            : $dateFin;
        $stagiaire->statut = StagiaireStatut::STAGE_EN_COURS;
        $stagiaire->save();

        // Convention de stage : direction et dates sont connues à ce stade,
        // le document peut donc être complet dès sa génération.
        $stagiaire->convention_chemin = $this->conventions->generer($stagiaire);
        $stagiaire->convention_sha256 = EmpreinteFichier::pourFichierStocke($stagiaire->convention_chemin);
        $stagiaire->convention_genere_at = now();
        $stagiaire->save();

        $lien = StagiaireLienPublic::genererPour($stagiaire, TypeLienPublic::CONVENTION);
        $this->envoyerLienParEmailSiPossible($stagiaire, $lien, new ConventionASignerNotification($lien));

        // Document distinct de la convention (voir docs/questions-ont.md) :
        // sa propre signature, son propre lien à usage unique.
        $stagiaire->engagement_confidentialite_chemin = $this->engagementsConfidentialite->generer($stagiaire);
        $stagiaire->engagement_confidentialite_sha256 = EmpreinteFichier::pourFichierStocke($stagiaire->engagement_confidentialite_chemin);
        $stagiaire->engagement_confidentialite_genere_at = now();
        $stagiaire->save();

        $lienEngagement = StagiaireLienPublic::genererPour($stagiaire, TypeLienPublic::ENGAGEMENT_CONFIDENTIALITE);
        $this->envoyerLienParEmailSiPossible($stagiaire, $lienEngagement, new EngagementConfidentialiteASignerNotification($lienEngagement));

        return $stagiaire;
    }

    public function signerEngagementConfidentialite(Stagiaire $stagiaire): Stagiaire
    {
        if (! $stagiaire->engagement_confidentialite_chemin) {
            throw new StagiaireTransitionException("Aucun engagement de confidentialité n'a encore été généré pour ce dossier.");
        }

        if ($stagiaire->engagement_confidentialite_signe_at) {
            throw new StagiaireTransitionException("L'engagement de confidentialité a déjà été signé.");
        }

        $stagiaire->engagement_confidentialite_signe_at = now();
        $stagiaire->save();

        $this->audit->enregistrer('stagiaire.engagement_confidentialite_signature', $stagiaire, null, [
            'description' => "Signature de l'engagement de confidentialité par le stagiaire {$stagiaire->nom} via lien public",
        ]);

        return $stagiaire;
    }

    /**
     * Stage académique uniquement : modification directe des deux dates,
     * sans historique formel — contrairement à prolongerStage() (stage
     * professionnel), qui trace chaque prolongation.
     */
    public function modifierDatesStage(Stagiaire $stagiaire, User $dfp, Carbon $dateDebut, Carbon $dateFin): Stagiaire
    {
        if ($stagiaire->type_stage !== StagiaireTypeStage::ACADEMIQUE) {
            throw new StagiaireTransitionException(
                'Cette action est réservée au stage académique — un stage professionnel se prolonge, il ne se modifie pas directement.'
            );
        }

        if (! in_array($stagiaire->statut, [StagiaireStatut::STAGE_EN_COURS, StagiaireStatut::EVALUATION_EN_COURS], true)) {
            throw new StagiaireTransitionException(
                "Action impossible : le dossier est au statut «{$stagiaire->statut->label()}»."
            );
        }

        $echeanceChangee = ! $dateFin->equalTo($stagiaire->date_fin_stage);

        $stagiaire->date_debut_stage = $dateDebut;
        $stagiaire->date_fin_stage = $dateFin;
        if ($echeanceChangee) {
            // Sinon la commande d'alerte à 10 jours ne renotifiera jamais la
            // nouvelle échéance : le drapeau resterait déjà positionné pour
            // l'ancienne date.
            $stagiaire->alerte_echeance_envoyee_at = null;
        }
        $stagiaire->save();

        $stagiaire->convention_chemin = $this->conventions->generer($stagiaire);
        $stagiaire->convention_sha256 = EmpreinteFichier::pourFichierStocke($stagiaire->convention_chemin);
        $stagiaire->convention_genere_at = now();
        $stagiaire->save();

        $this->audit->enregistrer('stagiaire.dates_modifiees', $stagiaire, $dfp, [
            'date_debut_stage' => $dateDebut->toDateString(),
            'date_fin_stage' => $dateFin->toDateString(),
        ]);

        return $stagiaire;
    }

    /**
     * Stage professionnel uniquement : chaque prolongation crée une entrée
     * dans stagiaire_prolongations, sans jamais écraser l'historique
     * précédent — voir StagiaireProlongation.
     */
    public function prolongerStage(Stagiaire $stagiaire, User $dfp, Carbon $nouvelleDateFin, string $motif): Stagiaire
    {
        if ($stagiaire->type_stage !== StagiaireTypeStage::PROFESSIONNEL) {
            throw new StagiaireTransitionException(
                'Cette action est réservée au stage professionnel — un stage académique se modifie directement, il ne se prolonge pas.'
            );
        }

        $this->assertStatut($stagiaire, StagiaireStatut::STAGE_EN_COURS);

        if (! $nouvelleDateFin->gt($stagiaire->date_fin_stage)) {
            throw new StagiaireTransitionException('La nouvelle date de fin doit être postérieure à la date de fin actuelle.');
        }

        $ancienneDateFin = $stagiaire->date_fin_stage;

        StagiaireProlongation::query()->create([
            'stagiaire_id' => $stagiaire->id,
            'ancienne_date_fin' => $ancienneDateFin,
            'nouvelle_date_fin' => $nouvelleDateFin,
            'motif' => $motif,
            'prolonge_par_id' => $dfp->id,
        ]);

        $stagiaire->date_fin_stage = $nouvelleDateFin;
        // Sinon la commande d'alerte à 10 jours ne renotifiera jamais la
        // nouvelle échéance : le drapeau resterait déjà positionné pour
        // l'ancienne date.
        $stagiaire->alerte_echeance_envoyee_at = null;
        $stagiaire->save();

        $stagiaire->convention_chemin = $this->conventions->generer($stagiaire);
        $stagiaire->convention_sha256 = EmpreinteFichier::pourFichierStocke($stagiaire->convention_chemin);
        $stagiaire->convention_genere_at = now();
        $stagiaire->save();

        $this->audit->enregistrer('stagiaire.prolongation', $stagiaire, $dfp, [
            'ancienne_date_fin' => $ancienneDateFin->toDateString(),
            'nouvelle_date_fin' => $nouvelleDateFin->toDateString(),
            'motif' => $motif,
        ]);

        return $stagiaire;
    }

    /**
     * Le stagiaire n'a pas de compte utilisateur : l'envoi se fait par
     * e-mail (routage à la demande) si `contact` ressemble à une adresse
     * valide, par SMS s'il ressemble plutôt à un numéro de téléphone (voir
     * Modules\Kernel\Support\SmsNotificationCanal) — un complément, pas un
     * remplacement : `contact` ne distingue pas les deux formats à la
     * saisie, si bien qu'un contact téléphone n'était auparavant notifié
     * par aucun canal. Dans tous les cas, le lien reste consultable via
     * StagiaireResource pour une transmission manuelle par la direction.
     */
    private function envoyerLienParEmailSiPossible(Stagiaire $stagiaire, StagiaireLienPublic $lien, $notification): void
    {
        if (filter_var($stagiaire->contact, FILTER_VALIDATE_EMAIL)) {
            $this->notifications->notifierParEmail($stagiaire->contact, $notification);

            return;
        }

        if ($notification instanceof NotificationAvecSms && $this->smsCanal->gere($stagiaire->contact ?? '')) {
            $this->notifications->notifierParSms($stagiaire->contact, $notification->messageSms());
        }
    }

    public function terminerStage(Stagiaire $stagiaire): Stagiaire
    {
        $this->assertStatut($stagiaire, StagiaireStatut::STAGE_EN_COURS);

        $stagiaire->statut = StagiaireStatut::EVALUATION_EN_COURS;
        $stagiaire->save();

        return $stagiaire;
    }

    /**
     * @param  string[]  $objectifs  2 à 5 objectifs courts, cardinalité déjà
     *                               validée côté FormRequest.
     */
    public function definirObjectifs(Stagiaire $stagiaire, array $objectifs): Stagiaire
    {
        $stagiaire->objectifs = array_values($objectifs);
        $stagiaire->save();

        return $stagiaire;
    }

    /**
     * @param  array  $donnees  clés parmi lieu_naissance, filiere_formation, niveau_formation,
     *                          maitre_stage, conseiller_stage — voir DefinirInformationsComplementairesRequest.
     */
    public function definirInformationsComplementaires(Stagiaire $stagiaire, array $donnees): Stagiaire
    {
        $stagiaire->fill($donnees);
        $stagiaire->save();

        return $stagiaire;
    }

    public function signerConventionDirection(Stagiaire $stagiaire, User $responsable): Stagiaire
    {
        if (! $stagiaire->convention_chemin) {
            throw new StagiaireTransitionException("Aucune convention n'a encore été générée pour ce dossier.");
        }

        if ($stagiaire->convention_signee_direction_at) {
            throw new StagiaireTransitionException('La convention a déjà été signée par la direction.');
        }

        $stagiaire->convention_signee_direction_at = now();
        $stagiaire->convention_signee_direction_par_id = $responsable->id;
        $stagiaire->save();

        $this->audit->enregistrer('stagiaire.convention_signature_direction', $stagiaire, $responsable);

        return $stagiaire;
    }

    /**
     * Signature via le lien public à usage unique : le stagiaire n'a pas de
     * compte, l'action n'est donc jamais attribuable à un utilisateur
     * authentifié (audit avec acteur nul, IP/user-agent conservés).
     */
    public function signerConventionStagiaire(Stagiaire $stagiaire): Stagiaire
    {
        if (! $stagiaire->convention_chemin) {
            throw new StagiaireTransitionException("Aucune convention n'a encore été générée pour ce dossier.");
        }

        if ($stagiaire->convention_signee_stagiaire_at) {
            throw new StagiaireTransitionException('La convention a déjà été signée par le stagiaire.');
        }

        $stagiaire->convention_signee_stagiaire_at = now();
        $stagiaire->save();

        $this->audit->enregistrer('stagiaire.convention_signature_stagiaire', $stagiaire, null, [
            'description' => "Signature de la convention par le stagiaire {$stagiaire->nom} via lien public",
        ]);

        return $stagiaire;
    }

    /**
     * @param  array  $grille  voir Modules\Stagiaires\Support\GrilleEvaluation — déjà validée par
     *                         EvaluerDirectionRequest, le total est toujours recalculé ici, jamais
     *                         fait confiance à une valeur envoyée par le client.
     */
    public function evaluerParDirection(Stagiaire $stagiaire, User $evaluateur, array $grille): Stagiaire
    {
        $this->assertStatut($stagiaire, StagiaireStatut::EVALUATION_EN_COURS);

        $classeGrille = $stagiaire->type_stage->classeGrille();
        $total = $classeGrille::total($grille);

        return DB::transaction(function () use ($stagiaire, $evaluateur, $grille, $total) {
            $stagiaire = $this->lockStagiaireFrais($stagiaire);

            $stagiaire->evaluation_direction_grille = $grille;
            $stagiaire->evaluation_direction_total = $total;
            $stagiaire->evaluation_direction_at = now();
            $stagiaire->save();

            $this->audit->enregistrer('stagiaire.evaluation_direction', $stagiaire, $evaluateur, [
                'total' => $total,
            ]);

            return $this->cloturerSiEvaluationsCompletes($stagiaire);
        });
    }

    public function evaluerParDfp(Stagiaire $stagiaire, User $evaluateur, array $grille): Stagiaire
    {
        $this->assertStatut($stagiaire, StagiaireStatut::EVALUATION_EN_COURS);

        $classeGrille = $stagiaire->type_stage->classeGrille();
        $total = $classeGrille::total($grille);

        return DB::transaction(function () use ($stagiaire, $evaluateur, $grille, $total) {
            $stagiaire = $this->lockStagiaireFrais($stagiaire);

            $stagiaire->evaluation_dfp_grille = $grille;
            $stagiaire->evaluation_dfp_total = $total;
            $stagiaire->evaluation_dfp_at = now();
            $stagiaire->save();

            $this->audit->enregistrer('stagiaire.evaluation_dfp', $stagiaire, $evaluateur, [
                'total' => $total,
            ]);

            return $this->cloturerSiEvaluationsCompletes($stagiaire);
        });
    }

    /**
     * Relit le stagiaire avec un verrou de ligne — si l'autre évaluateur
     * (direction ou DFP) soumet sa propre grille au même moment, cette
     * requête attend que l'autre committe avant de relire :
     * cloturerSiEvaluationsCompletes() ne travaille alors jamais sur un
     * instantané chargé avant que l'autre évaluation n'ait été enregistrée
     * (sinon la clôture peut n'être déclenchée par aucune des deux
     * requêtes, et le dossier reste bloqué). À appeler à l'intérieur d'une
     * DB::transaction().
     */
    private function lockStagiaireFrais(Stagiaire $stagiaire): Stagiaire
    {
        return Stagiaire::query()->lockForUpdate()->findOrFail($stagiaire->id);
    }

    /**
     * Réservé à la DFP : donne à la direction l'accès à son formulaire
     * d'évaluation pour ce dossier précis (voir StagiairePolicy::evaluerEnTantQueDirection()).
     */
    public function ouvrirPeriodeEvaluation(Stagiaire $stagiaire, User $dfp): Stagiaire
    {
        $stagiaire->periode_evaluation_ouverte_at = now();
        $stagiaire->periode_evaluation_ouverte_par_id = $dfp->id;
        $stagiaire->save();

        $this->audit->enregistrer('stagiaire.periode_evaluation_ouverte', $stagiaire, $dfp);

        // Le stagiaire doit déposer son rapport de fin de stage avant que
        // l'évaluation ne soit rendue : envoyé au même moment que
        // l'ouverture du formulaire d'évaluation de la direction, pas
        // seulement à la clôture (voir RETOUR_EXPERIENCE, qui lui est
        // volontairement post-clôture — un sondage de satisfaction, pas
        // un livrable attendu).
        $lien = StagiaireLienPublic::genererPour($stagiaire, TypeLienPublic::RAPPORT_STAGE);
        $this->envoyerLienParEmailSiPossible($stagiaire, $lien, new RapportStageDemandeNotification($lien));

        return $stagiaire;
    }

    private function cloturerSiEvaluationsCompletes(Stagiaire $stagiaire): Stagiaire
    {
        if ($stagiaire->evaluation_direction_total === null || $stagiaire->evaluation_dfp_total === null) {
            return $stagiaire;
        }

        $stagiaire->note_finale = $this->calculateur->calculer(
            $stagiaire->evaluation_direction_total,
            $stagiaire->evaluation_dfp_total,
        );
        $stagiaire->statut = StagiaireStatut::CLOTURE;
        $stagiaire->cloture_at = now();
        $stagiaire->numero_attestation = sprintf(
            'ATT-%d-%06d',
            now()->year,
            $this->sequences->suivant('attestation', now()->year),
        );
        $stagiaire->token_verification = Str::random(32);
        $stagiaire->save();

        $chemin = $this->attestations->generer($stagiaire);

        StagiaireDocument::query()->create([
            'stagiaire_id' => $stagiaire->id,
            'type' => DocumentType::ATTESTATION_STAGE,
            'nom_original' => "attestation-{$stagiaire->nom}.pdf",
            'chemin' => $chemin,
            'sha256' => EmpreinteFichier::pourFichierStocke($chemin),
        ]);

        // Certificat distinct de l'attestation : ne mentionne jamais la
        // note finale (voir CertificatGenerator), donc consultable par la
        // direction d'accueil sans exposer une information confidentielle.
        $cheminCertificat = $this->certificats->generer($stagiaire);
        StagiaireDocument::query()->create([
            'stagiaire_id' => $stagiaire->id,
            'type' => DocumentType::CERTIFICAT_FIN_STAGE,
            'nom_original' => "certificat-fin-stage-{$stagiaire->nom}.pdf",
            'chemin' => $cheminCertificat,
            'sha256' => EmpreinteFichier::pourFichierStocke($cheminCertificat),
        ]);

        $lien = StagiaireLienPublic::genererPour($stagiaire, TypeLienPublic::RETOUR_EXPERIENCE);
        $this->envoyerLienParEmailSiPossible($stagiaire, $lien, new RetourExperienceDemandeNotification($lien));

        return $stagiaire;
    }

    /**
     * Une ligne par jour ouvré : upsert sur (stagiaire_id, date) pour
     * permettre une saisie en deux temps (arrivée le matin, départ ajouté
     * le soir sur la même ligne) sans écraser l'heure déjà enregistrée si
     * elle n'est pas fournie dans cet appel.
     */
    public function enregistrerPresence(
        Stagiaire $stagiaire,
        User $saisiPar,
        Carbon $date,
        ?string $heureArrivee,
        ?string $heureDepart,
    ): StagiairePresence {
        return DB::transaction(function () use ($stagiaire, $saisiPar, $date, $heureArrivee, $heureDepart) {
            try {
                // lockForUpdate() : si la ligne existe déjà, sérialise avec
                // un autre appel concurrent sur le même jour (double-clic,
                // deux onglets) au lieu de laisser les deux écrasements se
                // chevaucher.
                $presence = $stagiaire->presences()->where('date', $date->toDateString())->lockForUpdate()->first()
                    ?? $stagiaire->presences()->create(['date' => $date->toDateString(), 'saisi_par_id' => $saisiPar->id]);
            } catch (UniqueConstraintViolationException) {
                // Deux appels concurrents ont trouvé la ligne absente et
                // tenté de la créer tous les deux : le second échoue sur la
                // contrainte unique (stagiaire_id, date) plutôt que de
                // planter — on rebascule alors sur la ligne que l'autre
                // appel vient de committer.
                $presence = $stagiaire->presences()->where('date', $date->toDateString())->lockForUpdate()->firstOrFail();
            }

            if ($heureArrivee !== null) {
                $presence->heure_arrivee = $heureArrivee;
            }
            if ($heureDepart !== null) {
                $presence->heure_depart = $heureDepart;
            }
            $presence->saisi_par_id = $saisiPar->id;
            $presence->save();

            /** @var StagiairePresence */
            return $presence;
        });
    }

    /**
     * "Décocher" un jour dans le calendrier de présence — suppression
     * idempotente (aucune erreur si la date n'a jamais été saisie).
     */
    public function supprimerPresence(Stagiaire $stagiaire, Carbon $date): void
    {
        $stagiaire->presences()->where('date', $date->toDateString())->delete();
    }

    /**
     * Réconciliation d'un lot de présences saisies hors connexion (mode
     * dégradé — une direction dans une représentation provinciale sans
     * accès réseau continu, qui note l'assiduité sur papier ou dans une
     * application locale, puis transmet le tout une fois la connexion
     * rétablie). Contrairement à enregistrerPresence() (un jour à la fois,
     * toujours appliqué), une entrée dont la date porte déjà une valeur
     * différente de celle transmise est un CONFLIT — jamais écrasée
     * silencieusement, sauf `$forcer` explicite — car la ligne existante a
     * pu être saisie entre-temps par quelqu'un d'autre, en ligne.
     *
     * @param  array<int, array{date: string, heure_arrivee: ?string, heure_depart: ?string}>  $entrees
     * @return array<int, array{date: string, statut: string, champs_conflit: array<int, string>}>
     */
    public function reconcilierPresences(Stagiaire $stagiaire, User $saisiPar, array $entrees, bool $forcer = false): array
    {
        return DB::transaction(function () use ($stagiaire, $saisiPar, $entrees, $forcer) {
            $rapport = [];

            foreach ($entrees as $entree) {
                $existante = $stagiaire->presences()->where('date', $entree['date'])->lockForUpdate()->first();

                $champsConflit = [];
                if ($existante !== null && ! $forcer) {
                    foreach (['heure_arrivee', 'heure_depart'] as $champ) {
                        $entrant = $entree[$champ] ?? null;
                        if ($entrant !== null && $existante->{$champ} !== null && $existante->{$champ} !== $entrant) {
                            $champsConflit[] = $champ;
                        }
                    }
                }

                if ($champsConflit !== []) {
                    $rapport[] = ['date' => $entree['date'], 'statut' => 'conflit', 'champs_conflit' => $champsConflit];

                    continue;
                }

                $this->enregistrerPresence(
                    $stagiaire,
                    $saisiPar,
                    Carbon::parse($entree['date']),
                    $entree['heure_arrivee'] ?? null,
                    $entree['heure_depart'] ?? null,
                );

                $rapport[] = ['date' => $entree['date'], 'statut' => 'applique', 'champs_conflit' => []];
            }

            $this->audit->enregistrer('stagiaire.presences_reconciliees', $stagiaire, $saisiPar, [
                'nombre_entrees' => count($entrees),
                'nombre_conflits' => count(array_filter($rapport, fn ($r) => $r['statut'] === 'conflit')),
                'forcer' => $forcer,
            ]);

            return $rapport;
        });
    }

    /**
     * $uploadePar reste nul pour un dépôt public (rapport de fin de stage
     * déposé par le stagiaire lui-même, sans compte Sanctum) — même
     * principe que created_by nul sur CourrierCircuitService::creerDepuisPublic().
     */
    public function ajouterDocument(Stagiaire $stagiaire, ?User $uploadePar, DocumentType $type, string $nomOriginal, string $chemin): StagiaireDocument
    {
        /** @var StagiaireDocument */
        return $stagiaire->documents()->create([
            'type' => $type,
            'nom_original' => $nomOriginal,
            'chemin' => $chemin,
            'sha256' => EmpreinteFichier::pourFichierStocke($chemin),
            'uploaded_by_id' => $uploadePar?->id,
        ]);
    }

    /**
     * @param  array{date_suivi: string, observations: string, difficultes_signalees?: ?string}  $donnees
     */
    public function ajouterSuivi(Stagiaire $stagiaire, User $redacteur, array $donnees): StagiaireSuivi
    {
        /** @var StagiaireSuivi */
        return $stagiaire->suivis()->create([
            ...$donnees,
            'redige_par_id' => $redacteur->id,
        ]);
    }
}
