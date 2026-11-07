<?php

namespace Modules\Courrier\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Contracts\CourrierPdfGenerator;
use Modules\Courrier\Contracts\NumeroGenerator;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Enums\DocumentRelationType;
use Modules\Courrier\Enums\MissionDocumentaireStatut;
use Modules\Courrier\Enums\MissionDocumentaireType;
use Modules\Courrier\Enums\SensCourrier;
use Modules\Courrier\Exceptions\RelectureNonValideeException;
use Modules\Courrier\Exceptions\TransitionNonAutoriseeException;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Courrier\Notifications\ProjetReponseRelectureNotification;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DgAuthorityResolver;
use Modules\Kernel\Support\EmpreinteFichier;
use Throwable;

/**
 * Rédaction, relecture et signature des réponses — extrait de
 * CourrierCircuitService, voir ce fichier pour l'orchestration publique.
 */
class CourrierReponseService
{
    public function __construct(
        private readonly NumeroGenerator $numeros,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly DocumentRelationService $relationsDocumentaires,
        private readonly CourrierPdfGenerator $pdf,
        private readonly DossierWorkflowGuard $dossiers,
        private readonly DgAuthorityResolver $autoriteDg,
        private readonly CourrierCircuitHelpers $helpers,
    ) {}

    /**
     * Circuit complet uniquement (Lot assistants) : le rédacteur assistant
     * soumet son projet à un relecteur désigné — cible projet_a_valider,
     * jamais en_relecture (réservé aux circuits dg_initie/sortant, voir
     * CourrierStatut::PROJET_A_VALIDER).
     */
    public function soumettreProjetReponse(Courrier $courrier, User $utilisateur, array $projetReponseContenu, int $relecteurId): Courrier
    {
        $mission = MissionDocumentaire::query()
            ->where('projet_courrier_id', $courrier->id)
            ->first();

        if ($mission !== null) {
            if ($mission->type !== MissionDocumentaireType::PREPARATION_REPONSE) {
                throw ValidationException::withMessages(['mission' => 'Ce projet doit être soumis par son workflow de mission.']);
            }

            return $this->soumettreProjetReponseMission($mission, $utilisateur, $projetReponseContenu);
        }

        $this->helpers->assertRelecteurNonSec2($relecteurId);

        return DB::transaction(function () use ($courrier, $utilisateur, $projetReponseContenu, $relecteurId) {
            $courrier = $this->helpers->lockCourrierFrais($courrier);
            $this->helpers->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::PROJET_A_VALIDER);

            $courrier->projet_reponse_contenu = $projetReponseContenu;
            $courrier->relecteur_id = $relecteurId;
            $courrier->relecture_validee_at = null;
            $courrier->statut = CourrierStatut::PROJET_A_VALIDER;
            $courrier->save();
            $this->helpers->tracerTransition($courrier, $utilisateur);

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
            $this->helpers->tracerTransition($courrier, $assistant);
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
        $soumission = DB::transaction(function () use ($mission, $assistant, $projetReponseContenu) {
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
            $relecteurs = User::query()
                ->where('actif', true)
                ->where('role', UserRole::AGENT_CIRCUIT_COURRIER)
                ->where('poste', $posteRelecteur)
                ->get();
            if ($relecteurs->count() !== 1) {
                throw ValidationException::withMessages(['relecteur' => 'Le compte du relecteur institutionnel doit être unique et configuré.']);
            }
            $relecteur = $relecteurs->first();

            $estResoumission = $courrier->projet_renvoye_at !== null;
            $courrier->projet_reponse_contenu = $projetReponseContenu;
            $courrier->relecteur_id = $relecteur->id;
            $courrier->relecture_validee_at = null;
            $courrier->relecture_commentaire = null;
            $courrier->statut = CourrierStatut::PROJET_A_VALIDER;
            $courrier->save();
            $this->helpers->tracerTransition($courrier, $assistant);
            $this->audit->enregistrer(
                $courrier->projet_renvoye_at ? 'courrier.projet_reponse_resoumis' : 'courrier.projet_reponse_soumis',
                $courrier,
                $assistant,
                ['mission_id' => $mission->id, 'relecteur_id' => $relecteur->id],
            );

            return [$courrier, $relecteur, $estResoumission];
        });

        [$courrier, $relecteur, $estResoumission] = $soumission;
        DB::afterCommit(fn () => $this->notifications->notifier(
            $relecteur,
            new ProjetReponseRelectureNotification($courrier, $estResoumission ? 'resoumis' : 'soumis'),
        ));

        return $courrier;
    }

    public function validerRelecture(Courrier $courrier, User $utilisateur, ?string $commentaire): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $commentaire) {
            $courrier = $this->helpers->lockCourrierFrais($courrier);

            if (! $courrier->enAttenteValidationRelecteur()) {
                throw TransitionNonAutoriseeException::sautDetape();
            }

            if ($courrier->relecteur_id !== $utilisateur->id) {
                throw TransitionNonAutoriseeException::posteNonHabilite();
            }

            $this->helpers->assertDechargeDonnee($courrier);

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
                    $this->helpers->notifierDgApresCommit($courrier, 'pret_a_signer');
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
        $courrier = DB::transaction(function () use ($courrier, $utilisateur, $observation) {
            $courrier = $this->helpers->lockCourrierFrais($courrier);

            if ($courrier->statut !== CourrierStatut::PROJET_A_VALIDER) {
                throw TransitionNonAutoriseeException::sautDetape();
            }

            if ($courrier->relecteur_id !== $utilisateur->id) {
                throw TransitionNonAutoriseeException::posteNonHabilite();
            }

            $this->helpers->assertDechargeDonnee($courrier);

            $courrier->statut = CourrierStatut::PROJET_A_REDIGER;
            $courrier->projet_renvoi_observation = $observation;
            $courrier->projet_renvoye_at = now();
            $courrier->projet_renvoye_par_id = $utilisateur->id;
            $courrier->save();
            $this->helpers->tracerTransition($courrier, $utilisateur);

            $this->audit->enregistrer('courrier.projet_renvoye_pour_correction', $courrier, $utilisateur, [
                'observation' => $observation,
            ]);

            return $courrier;
        });

        DB::afterCommit(function () use ($courrier): void {
            if ($courrier->createur !== null) {
                $this->notifications->notifier(
                    $courrier->createur,
                    new ProjetReponseRelectureNotification($courrier, 'retourne_pour_correction'),
                );
            }
        });

        return $courrier;
    }

    public function signer(Courrier $courrier, User $utilisateur): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur) {
            $sourceAutorite = $this->autoriteDg->source($utilisateur);
            if ($sourceAutorite === null) {
                throw TransitionNonAutoriseeException::posteNonHabilite();
            }
            $courrier = $this->helpers->lockCourrierFrais($courrier);
            $this->helpers->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::SIGNE);
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
            $this->helpers->tracerTransition($courrier, $utilisateur);

            if ($courrier->numero_depart !== null) {
                $this->helpers->auditerIdentite($courrier, $utilisateur, 'numero_depart', $courrier->numero_depart);
            }

            $this->audit->enregistrer('courrier.signature', $courrier, $utilisateur, [
                'description' => "Signature du courrier {$courrier->numeroSuivi()}",
                'source_autorite' => $sourceAutorite,
                'numero_depart' => $courrier->numero_depart,
            ]);

            return $courrier;
        });
    }

    public function validerPourSignature(Courrier $courrier, User $utilisateur): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur) {
            $sourceAutorite = $this->autoriteDg->source($utilisateur);
            if ($sourceAutorite === null) {
                throw TransitionNonAutoriseeException::posteNonHabilite();
            }

            $courrier = $this->helpers->lockCourrierFrais($courrier);
            $this->helpers->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::EN_ATTENTE_SIGNATURE);
            $this->dossiers->assertCourrierActifPourNouvelleActivite($courrier);

            if (! $courrier->relectureEstValidee()) {
                throw new RelectureNonValideeException;
            }
            if (blank($courrier->destinataire_externe_nom)) {
                throw ValidationException::withMessages(['destinataire_externe_nom' => 'Le destinataire doit être déterminé avant la validation pour signature.']);
            }

            $mission = $courrier->missionProjetReponse()->first();
            if ($mission !== null && ($mission->type !== MissionDocumentaireType::PREPARATION_REPONSE
                || $mission->statut !== MissionDocumentaireStatut::RETOURNEE)) {
                throw ValidationException::withMessages(['mission' => 'La mission doit être terminée après la relecture finale avant la validation pour signature.']);
            }
            if ($courrier->numero_depart !== null || $courrier->pdf_a_signer_chemin !== null || $courrier->pdf_a_signer_sha256 !== null) {
                throw ValidationException::withMessages(['courrier' => 'Ce projet possède déjà un document préparé pour signature.']);
            }

            $courrier->valide_signature_par_id = $utilisateur->id;
            $courrier->valide_signature_at = now();
            $courrier->numero_depart = $this->numeros->genererNumeroDepart();
            $courrier->statut = CourrierStatut::EN_ATTENTE_SIGNATURE;
            $courrier->setRelation('valideSignaturePar', $utilisateur);
            $courrier->pdf_a_signer_chemin = $this->pdf->genererPourSignature($courrier, $sourceAutorite);
            $courrier->pdf_a_signer_sha256 = EmpreinteFichier::pourFichierStocke($courrier->pdf_a_signer_chemin);
            $courrier->save();
            $this->helpers->tracerTransition($courrier, $utilisateur);

            $this->helpers->auditerIdentite($courrier, $utilisateur, 'numero_depart', $courrier->numero_depart);
            $this->audit->enregistrer('courrier.valide_pour_signature', $courrier, $utilisateur, [
                'source_autorite' => $sourceAutorite,
                'numero_depart' => $courrier->numero_depart,
                'pdf_a_signer_sha256' => $courrier->pdf_a_signer_sha256,
            ]);

            return $courrier;
        });
    }

    public function finaliserSignaturePhysique(Courrier $courrier, User $utilisateur, UploadedFile $scan): Courrier
    {
        $cheminFinal = null;

        try {
            return DB::transaction(function () use ($courrier, $utilisateur, $scan, &$cheminFinal): Courrier {
                if (! $this->autoriteDg->estAutoritePourTeleverserScanSigne($utilisateur)) {
                    throw TransitionNonAutoriseeException::posteNonHabilite();
                }

                $courrier = $this->helpers->lockCourrierFrais($courrier);
                if ($courrier->statut !== CourrierStatut::EN_ATTENTE_SIGNATURE) {
                    throw ValidationException::withMessages(['courrier' => 'Ce courrier n’attend pas un scan signé.']);
                }
                if (blank($courrier->numero_depart)
                    || $courrier->valide_signature_par_id === null
                    || $courrier->valide_signature_at === null
                    || blank($courrier->pdf_a_signer_chemin)
                    || blank($courrier->pdf_a_signer_sha256)) {
                    throw ValidationException::withMessages(['courrier' => 'La validation DG et le PDF à signer sont requis avant finalisation.']);
                }
                if ($courrier->signataire_id !== null || $courrier->signe_at !== null
                    || filled($courrier->pdf_chemin) || filled($courrier->pdf_sha256)) {
                    throw ValidationException::withMessages(['courrier' => 'Une version finale existe déjà pour ce courrier.']);
                }
                if (! $courrier->transitions()->where('statut', CourrierStatut::EN_ATTENTE_SIGNATURE)
                    ->where('changed_by_id', $courrier->valide_signature_par_id)->exists()) {
                    throw ValidationException::withMessages(['courrier' => 'L’autorité validatrice ne correspond pas à l’historique du courrier.']);
                }

                app(PdfOfficielIntegrity::class)->verifierFichier(
                    $courrier->pdf_a_signer_chemin,
                    $courrier->pdf_a_signer_sha256,
                    'pdf_a_signer',
                );
                $this->dossiers->assertCourrierActifPourNouvelleActivite($courrier);
                $contenuScan = file_get_contents($scan->getRealPath());
                if ($contenuScan === false || $contenuScan === '' || ! str_starts_with($contenuScan, '%PDF-')) {
                    throw ValidationException::withMessages(['scan' => 'Le fichier téléversé n’est pas un PDF valide.']);
                }

                $cheminFinal = 'courriers-signes/'.$courrier->id.'/'.Str::uuid().'.pdf';
                $cheminStocke = Storage::disk('local')->putFileAs(
                    'courriers-signes/'.$courrier->id,
                    $scan,
                    basename($cheminFinal),
                );
                if (! is_string($cheminStocke) || $cheminStocke === '') {
                    throw ValidationException::withMessages(['scan' => 'Le stockage du scan a échoué.']);
                }

                $instantAcceptation = now();
                $courrier->pdf_chemin = $cheminFinal;
                $courrier->pdf_sha256 = EmpreinteFichier::pourFichierStocke($cheminFinal);
                $courrier->signataire_id = $courrier->valide_signature_par_id;
                $courrier->signe_at = $instantAcceptation;
                $courrier->scan_signe_televerse_par_id = $utilisateur->id;
                $courrier->scan_signe_televerse_at = $instantAcceptation;
                $courrier->statut = CourrierStatut::SIGNE;
                $courrier->save();
                $this->helpers->tracerTransition($courrier, $utilisateur);

                $this->audit->enregistrer('courrier.scan_signe_finalise', $courrier, $utilisateur, [
                    'signataire_id' => $courrier->signataire_id,
                    'televerse_par_id' => $utilisateur->id,
                    'pdf_sha256' => $courrier->pdf_sha256,
                ]);

                return $courrier;
            });
        } catch (Throwable $exception) {
            if (is_string($cheminFinal)) {
                Storage::disk('local')->delete($cheminFinal);
            }

            throw $exception;
        }
    }

    private function assertMissionPreparationActive(MissionDocumentaire $mission, User $assistant): void
    {
        if (
            $mission->type !== MissionDocumentaireType::PREPARATION_REPONSE
            || $mission->autorite_poste !== Poste::DG
            || $mission->assistant_id !== $assistant->id
            || $assistant->role !== UserRole::AGENT_CIRCUIT_COURRIER
            || ! in_array($mission->statut, [MissionDocumentaireStatut::ASSIGNEE, MissionDocumentaireStatut::EN_COURS], true)
        ) {
            throw TransitionNonAutoriseeException::posteNonHabilite();
        }
    }
}
