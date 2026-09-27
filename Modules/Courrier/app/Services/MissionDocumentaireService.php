<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\MissionDocumentaireStatut;
use Modules\Courrier\Enums\MissionDocumentaireType;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Courrier\Notifications\MissionDocumentaireNotification;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;

class MissionDocumentaireService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly DelegationResolver $delegations,
        private readonly DossierWorkflowGuard $dossiers,
    ) {}

    public function creer(Courrier $courrier, User $acteur, User $assistant, string $instruction): MissionDocumentaire
    {
        $autorite = $this->autoriteDe($acteur);
        $posteAssistantAttendu = match ($autorite) {
            Poste::DG => [Poste::ASSISTANT_1, Poste::ASSISTANT_2],
            Poste::DGA => [Poste::ASSISTANT_DGA],
            default => [],
        };

        if (! in_array($assistant->poste, $posteAssistantAttendu, true)) {
            throw ValidationException::withMessages(['assistant_id' => "Cet assistant ne dépend pas de l'autorité demandeuse."]);
        }

        $mission = DB::transaction(function () use ($courrier, $acteur, $assistant, $instruction, $autorite) {
            $courrier = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($courrier->id);
            $this->dossiers->assertCourrierActifPourNouvelleActivite($courrier);

            if ($courrier->statut !== CourrierStatut::EN_ATTENTE_AVIS_DG || $courrier->dossier_id === null) {
                throw ValidationException::withMessages(['courrier' => "Le courrier n'est pas sous contrôle DG/DGA pour une mission."]);
            }
            if ($courrier->missionsDocumentaires()->where('autorite_poste', $autorite)->whereIn('statut', [
                MissionDocumentaireStatut::ASSIGNEE,
                MissionDocumentaireStatut::EN_COURS,
            ])->exists()) {
                throw ValidationException::withMessages(['courrier' => 'Une mission est déjà active pour cette autorité.']);
            }

            $mission = MissionDocumentaire::query()->create([
                'courrier_id' => $courrier->id,
                'dossier_id' => $courrier->dossier_id,
                'demandeur_id' => $acteur->id,
                'demandeur_poste' => $acteur->poste,
                'autorite_poste' => $autorite,
                'assistant_id' => $assistant->id,
                'instruction' => $instruction,
                'type' => MissionDocumentaireType::GENERALE,
                'statut' => MissionDocumentaireStatut::ASSIGNEE,
                'envoyee_at' => now(),
            ]);

            $this->audit->enregistrer('mission_documentaire.creee', $mission, $acteur, [
                'courrier_id' => $courrier->id,
                'dossier_id' => $courrier->dossier_id,
                'assistant_id' => $assistant->id,
                'autorite_poste' => $autorite->value,
                'instruction' => $instruction,
            ]);

            return $mission;
        });

        DB::afterCommit(fn () => $this->notifications->notifier(
            $assistant,
            new MissionDocumentaireNotification($mission->load('courrier'), 'assignee'),
        ));

        return $mission;
    }

    public function creerPreparationReponse(Courrier $courrier, User $acteur, User $assistant, string $instruction): MissionDocumentaire
    {
        if ($this->autoriteDe($acteur) !== Poste::DG) {
            throw ValidationException::withMessages(['mission' => 'Seule la DG peut demander la préparation de cette réponse.']);
        }
        if (! in_array($assistant->poste, [Poste::ASSISTANT_1, Poste::ASSISTANT_2], true)) {
            throw ValidationException::withMessages(['assistant_id' => 'La DG doit choisir Assistant DG1 ou Assistant DG2.']);
        }

        $mission = DB::transaction(function () use ($courrier, $acteur, $assistant, $instruction) {
            $courrier = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($courrier->id);
            $this->dossiers->assertCourrierActifPourNouvelleActivite($courrier);

            if (! in_array($courrier->statut, [CourrierStatut::EN_ATTENTE_AVIS_DG, CourrierStatut::DISPATCH_EXECUTE], true) || $courrier->dossier_id === null) {
                throw ValidationException::withMessages(['courrier' => "Le courrier n'est pas sous contrôle de la DG."]);
            }
            if ($courrier->missionsDocumentaires()->where('autorite_poste', Poste::DG)->whereIn('statut', [
                MissionDocumentaireStatut::ASSIGNEE,
                MissionDocumentaireStatut::EN_COURS,
            ])->exists()) {
                throw ValidationException::withMessages(['courrier' => 'Une mission DG est déjà active pour ce courrier.']);
            }

            $mission = MissionDocumentaire::query()->create([
                'courrier_id' => $courrier->id,
                'dossier_id' => $courrier->dossier_id,
                'demandeur_id' => $acteur->id,
                'demandeur_poste' => $acteur->poste,
                'autorite_poste' => Poste::DG,
                'assistant_id' => $assistant->id,
                'instruction' => $instruction,
                'type' => MissionDocumentaireType::PREPARATION_REPONSE,
                'statut' => MissionDocumentaireStatut::ASSIGNEE,
                'envoyee_at' => now(),
            ]);

            $this->audit->enregistrer('courrier.preparation_reponse_demandee', $courrier, $acteur, [
                'mission_id' => $mission->id,
                'assistant_id' => $assistant->id,
                'instruction' => $instruction,
            ]);
            $this->audit->enregistrer('mission_documentaire.creee', $mission, $acteur, [
                'courrier_id' => $courrier->id,
                'assistant_id' => $assistant->id,
                'type' => MissionDocumentaireType::PREPARATION_REPONSE->value,
            ]);

            return $mission;
        });

        DB::afterCommit(fn () => $this->notifications->notifier(
            $assistant,
            new MissionDocumentaireNotification($mission->load('courrier'), 'assignee'),
        ));

        return $mission;
    }

    public function prendreEnCharge(MissionDocumentaire $mission, User $assistant): MissionDocumentaire
    {
        return DB::transaction(function () use ($mission, $assistant) {
            $mission = MissionDocumentaire::query()->lockForUpdate()->findOrFail($mission->id);
            if ($mission->assistant_id !== $assistant->id || $mission->statut !== MissionDocumentaireStatut::ASSIGNEE) {
                throw ValidationException::withMessages(['mission' => 'Cette mission ne peut pas être prise en charge.']);
            }

            $mission->update(['statut' => MissionDocumentaireStatut::EN_COURS, 'prise_en_charge_at' => now()]);
            $this->audit->enregistrer('mission_documentaire.prise_en_charge', $mission, $assistant, [
                'courrier_id' => $mission->courrier_id,
                'prise_en_charge_at' => $mission->prise_en_charge_at?->toIso8601String(),
            ]);

            return $mission;
        });
    }

    public function retourner(MissionDocumentaire $mission, User $assistant, string $compteRendu, ?array $projet): MissionDocumentaire
    {
        $mission = DB::transaction(function () use ($mission, $assistant, $compteRendu, $projet) {
            $mission = MissionDocumentaire::query()->lockForUpdate()->findOrFail($mission->id);
            if ($mission->type === MissionDocumentaireType::PREPARATION_REPONSE) {
                throw ValidationException::withMessages(['mission' => 'Une mission de préparation de réponse se termine après validation de la relecture de son projet D.']);
            }
            if ($mission->assistant_id !== $assistant->id || $mission->statut !== MissionDocumentaireStatut::EN_COURS) {
                throw ValidationException::withMessages(['mission' => 'Cette mission ne peut pas être retournée.']);
            }

            $mission->update([
                'statut' => MissionDocumentaireStatut::RETOURNEE,
                'compte_rendu' => $compteRendu,
                'projet_reponse_contenu' => $projet,
                'retournee_at' => now(),
            ]);

            if ($projet !== null) {
                // La mission cesse d'accorder sa visibilité dès qu'elle
                // passe à RETOURNEE. L'écriture métier doit néanmoins être
                // finalisée dans la même transaction, sans dépendre du
                // scope HTTP de l'assistant devenu immédiatement caduc.
                Courrier::withoutGlobalScopes()->whereKey($mission->courrier_id)
                    ->update(['projet_reponse_contenu' => $projet]);
            }

            $this->audit->enregistrer('mission_documentaire.retournee', $mission, $assistant, [
                'courrier_id' => $mission->courrier_id,
                'demandeur_id' => $mission->demandeur_id,
                'compte_rendu' => $compteRendu,
                'retournee_at' => $mission->retournee_at?->toIso8601String(),
            ]);

            return $mission;
        });

        DB::afterCommit(fn () => $this->notifications->notifier(
            $mission->demandeur,
            new MissionDocumentaireNotification($mission->load('courrier'), 'retournee'),
        ));

        return $mission;
    }

    public function annuler(MissionDocumentaire $mission, User $acteur, string $motif): MissionDocumentaire
    {
        return DB::transaction(function () use ($mission, $acteur, $motif) {
            $mission = MissionDocumentaire::query()->lockForUpdate()->findOrFail($mission->id);
            if ($mission->projet_courrier_id !== null) {
                throw ValidationException::withMessages(['mission' => 'Une mission ayant déjà produit un projet D ne peut plus être annulée.']);
            }
            if (! $mission->statut->estActive()) {
                throw ValidationException::withMessages(['mission' => 'Une mission terminée ne peut pas être annulée.']);
            }

            $mission->update([
                'statut' => MissionDocumentaireStatut::ANNULEE,
                'annulee_par_id' => $acteur->id,
                'motif_annulation' => $motif,
                'annulee_at' => now(),
            ]);
            $this->audit->enregistrer('mission_documentaire.annulee', $mission, $acteur, [
                'courrier_id' => $mission->courrier_id,
                'motif' => $motif,
                'annulee_at' => $mission->annulee_at?->toIso8601String(),
            ]);

            return $mission;
        });
    }

    private function autoriteDe(User $acteur): Poste
    {
        if ($acteur->poste === Poste::DG || $acteur->poste === Poste::DGA) {
            return $acteur->poste;
        }

        $posteDelegue = $this->delegations->posteDelegueAujourdhui($acteur);
        if ($posteDelegue === Poste::DG || $posteDelegue === Poste::DGA) {
            return $posteDelegue;
        }

        throw ValidationException::withMessages(['mission' => "Vous n'êtes pas habilité à créer une mission."]);
    }
}
