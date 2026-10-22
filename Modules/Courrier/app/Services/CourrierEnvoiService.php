<?php

namespace Modules\Courrier\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Enums\DocumentRelationType;
use Modules\Courrier\Enums\ModeExpedition;
use Modules\Courrier\Enums\ModeRemise;
use Modules\Courrier\Enums\ModeSortie;
use Modules\Courrier\Enums\SensCourrier;
use Modules\Courrier\Exceptions\TransitionNonAutoriseeException;
use Modules\Courrier\Mail\ReponseFinaleCourrierExterneMail;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;
use Throwable;

/**
 * Sortie d'un courrier sortant (courriel, retrait physique, remise) et
 * courrier sortant proactif/réponse — extrait de CourrierCircuitService,
 * voir ce fichier pour l'orchestration publique.
 */
class CourrierEnvoiService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly DelegationResolver $delegations,
        private readonly DocumentRelationService $relationsDocumentaires,
        private readonly CourrierCircuitHelpers $helpers,
    ) {}

    /**
     * Crée le courrier de réponse comme enregistrement à part entière
     * (sens=sortant), lié à l'original par en_reponse_a_courrier_id —
     * plutôt qu'un simple champ de l'original (voir docs, lot 2). Démarre
     * directement à en_relecture, le relecteur étant désigné à la
     * création (même principe que CourrierReponseService::soumettreProjetReponse()
     * pour la réponse historique) : rédiger sans relecteur désigné n'a pas
     * de sens, il n'y aurait personne à qui la transition suivante serait
     * destinée.
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
        $this->helpers->assertRelecteurNonSec2((int) $donnees['relecteur_id']);

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

            $this->helpers->tracerTransition($courrier, $auteur);

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
     * Distinct de enregistrer() (qui numérote un courrier ENTRANT, voir
     * CourrierCircuitService) — un courrier sortant n'entre jamais dans le
     * registre arrivée.
     */
    public function choisirModeSortie(Courrier $courrier, User $utilisateur, ModeSortie $mode): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $mode): Courrier {
            $courrier = $this->helpers->lockCourrierFrais($courrier);
            $this->assertActeurSec2($utilisateur);
            $this->helpers->assertDechargeDonnee($courrier);

            if ($courrier->statut !== CourrierStatut::SIGNE || $courrier->sens !== SensCourrier::SORTANT) {
                throw TransitionNonAutoriseeException::sautDetape();
            }
            if ($courrier->mode_sortie !== null) {
                throw ValidationException::withMessages(['mode_sortie' => 'Le mode de sortie est déjà fixé.']);
            }
            $this->assertPdfFinalPretPourSortie($courrier);
            $this->verifierCourrielOfficielSiRequis($courrier, $mode);

            $courrier->mode_sortie = $mode;
            $courrier->save();
            $this->audit->enregistrer('courrier.mode_sortie_choisi', $courrier, $utilisateur, [
                'mode_sortie' => $mode->value,
                'numero_depart' => $courrier->numero_depart,
            ]);

            return $courrier;
        });
    }

    public function envoyerParCourriel(Courrier $courrier, User $utilisateur): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur): Courrier {
            $courrier = $this->helpers->lockCourrierFrais($courrier);
            $this->assertActeurSec2($utilisateur);
            $this->helpers->assertDechargeDonnee($courrier);
            $this->assertPdfFinalPretPourSortie($courrier);

            if (! in_array($courrier->mode_sortie, [ModeSortie::COURRIEL, ModeSortie::COURRIEL_ET_RETRAIT], true)) {
                throw ValidationException::withMessages(['mode_sortie' => 'Le canal courriel n’est pas prévu pour ce courrier.']);
            }
            if ($courrier->courriel_envoye_at !== null) {
                throw ValidationException::withMessages(['courriel' => 'Le courriel a déjà été exécuté.']);
            }
            $this->verifierCourrielOfficielSiRequis($courrier, $courrier->mode_sortie);

            $destinataire = trim((string) $courrier->destinataire_externe_email);
            $origine = $this->originePourCourriel($courrier);
            $ttl = max(1, (int) config('courrier.reponse_externe.lien_ttl_minutes', 10080));
            $url = URL::temporarySignedRoute(
                'api.public.reponses.telecharger',
                now()->addMinutes($ttl),
                ['courrier' => $courrier->id],
            );
            $mail = new ReponseFinaleCourrierExterneMail($origine, $courrier, $url);
            $instantEnvoi = now();
            $ancienStatut = $courrier->statut;
            $courrier->courriel_envoye_at = $instantEnvoi;
            $courrier->courriel_envoye_par_id = $utilisateur->id;
            $courrier->courriel_destinataire = $destinataire;
            $courrier->date_envoi = $instantEnvoi->toDateString();
            if ($courrier->statut !== CourrierStatut::REMIS) {
                $courrier->statut = CourrierStatut::ENVOYE;
            }
            $courrier->save();
            if ($ancienStatut !== $courrier->statut) {
                $this->helpers->tracerTransition($courrier, $utilisateur);
            }
            $this->notifications->envoyerMail($destinataire, $mail);
            $this->audit->enregistrer('courrier.courriel_envoye', $courrier, $utilisateur, [
                'courriel_destinataire' => $destinataire,
                'courriel_envoye_at' => $instantEnvoi->toISOString(),
                'numero_depart' => $courrier->numero_depart,
                'pdf_sha256' => $courrier->pdf_sha256,
            ]);

            return $courrier;
        });
    }

    public function rendreDisponiblePourRetrait(Courrier $courrier, User $utilisateur, ?string $observation): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $observation): Courrier {
            $courrier = $this->helpers->lockCourrierFrais($courrier);
            $this->assertActeurSec2($utilisateur);
            $this->helpers->assertDechargeDonnee($courrier);
            $this->assertPdfFinalPretPourSortie($courrier);

            if (! in_array($courrier->mode_sortie, [ModeSortie::RETRAIT_PHYSIQUE, ModeSortie::COURRIEL_ET_RETRAIT], true)) {
                throw ValidationException::withMessages(['mode_sortie' => 'Le canal retrait physique n’est pas prévu pour ce courrier.']);
            }
            if ($courrier->retrait_disponible_at !== null || $courrier->remis_le !== null) {
                throw ValidationException::withMessages(['retrait' => 'Le retrait est déjà disponible ou a déjà été effectué.']);
            }

            $instantDisponibilite = now();
            $ancienStatut = $courrier->statut;
            $courrier->retrait_disponible_at = $instantDisponibilite;
            $courrier->retrait_disponible_par_id = $utilisateur->id;
            $courrier->retrait_disponible_observation = $observation;
            if ($courrier->courriel_envoye_at === null) {
                $courrier->statut = CourrierStatut::DISPONIBLE_RETRAIT;
            }
            $courrier->save();
            if ($ancienStatut !== $courrier->statut) {
                $this->helpers->tracerTransition($courrier, $utilisateur);
            }
            $this->audit->enregistrer('courrier.retrait_disponible', $courrier, $utilisateur, [
                'retrait_disponible_at' => $instantDisponibilite->toISOString(),
                'observation' => $observation,
            ]);

            return $courrier;
        });
    }

    public function confirmerRemisePhysique(
        Courrier $courrier,
        User $utilisateur,
        string $remisA,
        ?string $observation,
        ?UploadedFile $decharge,
    ): Courrier {
        $cheminDecharge = null;

        try {
            return DB::transaction(function () use ($courrier, $utilisateur, $remisA, $observation, $decharge, &$cheminDecharge): Courrier {
                $courrier = $this->helpers->lockCourrierFrais($courrier);
                $this->assertActeurSec2($utilisateur);
                $this->helpers->assertDechargeDonnee($courrier);
                $this->assertPdfFinalPretPourSortie($courrier);

                if (! in_array($courrier->mode_sortie, [ModeSortie::RETRAIT_PHYSIQUE, ModeSortie::COURRIEL_ET_RETRAIT], true)
                    || $courrier->retrait_disponible_at === null) {
                    throw ValidationException::withMessages(['retrait' => 'Le courrier doit être disponible au retrait avant la remise.']);
                }
                if ($courrier->remis_le !== null || $courrier->retrait_effectue_par_id !== null) {
                    throw ValidationException::withMessages(['retrait' => 'La remise physique a déjà été confirmée.']);
                }

                if ($decharge !== null) {
                    $cheminDecharge = 'courriers-decharges/'.Str::uuid().'.'.$decharge->guessExtension();
                    $cheminStocke = Storage::disk('local')->putFileAs('courriers-decharges', $decharge, basename($cheminDecharge));
                    if (! is_string($cheminStocke) || $cheminStocke === '') {
                        throw ValidationException::withMessages(['decharge_remise' => 'Le stockage de la décharge a échoué.']);
                    }
                }

                $instantRemise = now();
                $courrier->remis_le = $instantRemise;
                $courrier->remis_a = $remisA;
                $courrier->mode_remise = ModeRemise::RETRAIT_PHYSIQUE;
                $courrier->decharge_remise_chemin = $cheminDecharge;
                $courrier->retrait_effectue_par_id = $utilisateur->id;
                $courrier->retrait_observation = $observation;
                $courrier->statut = CourrierStatut::REMIS;
                $courrier->save();
                $this->helpers->tracerTransition($courrier, $utilisateur);
                $this->audit->enregistrer('courrier.remise_physique_confirmee', $courrier, $utilisateur, [
                    'remis_a' => $remisA,
                    'remis_le' => $instantRemise->toISOString(),
                    'decharge_remise_chemin' => $cheminDecharge,
                    'observation' => $observation,
                ]);

                return $courrier;
            });
        } catch (Throwable $exception) {
            if (is_string($cheminDecharge)) {
                Storage::disk('local')->delete($cheminDecharge);
            }

            throw $exception;
        }
    }

    private function assertActeurSec2(User $utilisateur): void
    {
        if (! $this->delegations->utilisateurHabilite($utilisateur, [Poste::SECRETARIAT_2])) {
            throw TransitionNonAutoriseeException::posteNonHabilite();
        }
    }

    private function assertPdfFinalPretPourSortie(Courrier $courrier): void
    {
        if ($courrier->sens !== SensCourrier::SORTANT
            || ! in_array($courrier->statut, [
                CourrierStatut::SIGNE,
                CourrierStatut::DISPONIBLE_RETRAIT,
                CourrierStatut::ENVOYE,
                CourrierStatut::REMIS,
            ], true)
            || $courrier->signe_at === null
            || $courrier->signataire_id === null
            || blank($courrier->numero_depart)) {
            throw ValidationException::withMessages(['courrier' => 'Un courrier sortant signé et numéroté est requis.']);
        }

        app(PdfOfficielIntegrity::class)->verifier($courrier);
    }

    private function verifierCourrielOfficielSiRequis(Courrier $courrier, ModeSortie $mode): void
    {
        if (in_array($mode, [ModeSortie::COURRIEL, ModeSortie::COURRIEL_ET_RETRAIT], true)
            && (blank($courrier->destinataire_externe_email)
                || filter_var($courrier->destinataire_externe_email, FILTER_VALIDATE_EMAIL) === false)) {
            throw ValidationException::withMessages(['destinataire_externe_email' => 'Une adresse email officielle valide est requise pour le canal courriel.']);
        }
    }

    private function originePourCourriel(Courrier $reponse): ?Courrier
    {
        if ($reponse->en_reponse_a_courrier_id === null) {
            return null;
        }

        return Courrier::withoutGlobalScopes()
            ->whereKey($reponse->en_reponse_a_courrier_id)
            ->where('dossier_id', $reponse->dossier_id)
            ->where('sens', SensCourrier::ENTRANT)
            ->first();
    }

    public function envoyer(Courrier $courrier, User $utilisateur, array $donnees): Courrier
    {
        $courrier = DB::transaction(function () use ($courrier, $utilisateur, $donnees) {
            $courrier = $this->helpers->lockCourrierFrais($courrier);
            $this->helpers->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::ENVOYE);
            if ($courrier->mode_sortie !== null || $courrier->scan_signe_televerse_at !== null) {
                throw ValidationException::withMessages(['mode_sortie' => 'Ce courrier doit utiliser les actions explicites de sortie SEC2.']);
            }
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
            $this->helpers->tracerTransition($courrier, $utilisateur);

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
        if ($reponse->mode_expedition !== ModeExpedition::COURRIEL) {
            return;
        }

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

    /**
     * Preuve de remise — un courrier sortant confié à un porteur n'est
     * réellement délivré qu'une fois la décharge signée rapportée.
     * Distinct de l'envoi lui-même : un courrier peut être "envoyé" (parti
     * de l'Office) sans que la remise soit encore confirmée.
     */
    public function enregistrerRemise(Courrier $courrier, User $utilisateur, array $donnees): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $donnees): Courrier {
            $courrier = $this->helpers->lockCourrierFrais($courrier);

            if ($courrier->mode_sortie !== null) {
                throw ValidationException::withMessages(['mode_sortie' => 'Utilisez la confirmation de remise physique du mode de sortie choisi.']);
            }
            if ($courrier->statut !== CourrierStatut::ENVOYE) {
                throw TransitionNonAutoriseeException::sautDetape();
            }
            if ($courrier->remis_le !== null || $courrier->remis_a !== null || $courrier->mode_remise !== null) {
                throw ValidationException::withMessages(['remise' => 'Une preuve de remise a déjà été enregistrée pour ce courrier legacy.']);
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
        });
    }
}
