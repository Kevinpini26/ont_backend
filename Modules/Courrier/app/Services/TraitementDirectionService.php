<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\DecisionDirection;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Enums\TraitementDirectionStatut;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Courrier\Notifications\TraitementDirectionNotification;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;

class TraitementDirectionService
{
    public function __construct(private readonly AuditLogger $audit, private readonly NotificationService $notifications, private readonly DossierWorkflowGuard $dossiers) {}

    public function creerDepuisReception(DispatchCourrier $dispatch, User $secretariat): TraitementDirection
    {
        $this->dossiers->assertActifPourNouvelleActivite($dispatch->dossier_id);
        if ($dispatch->type_destination !== DispatchTypeDestination::DIRECTION || $dispatch->statut !== DispatchStatut::EXECUTE
            || $dispatch->accuse_reception_at === null || $dispatch->direction_id !== $secretariat->direction_id
            || $secretariat->role !== UserRole::SECRETARIAT_DIRECTION) {
            throw ValidationException::withMessages(['dispatch' => 'Ce dispatch ne peut pas ouvrir un traitement directionnel.']);
        }

        $traitement = TraitementDirection::query()->firstOrCreate(
            ['dispatch_courrier_id' => $dispatch->id],
            [
                'courrier_id' => $dispatch->courrier_id, 'dossier_id' => $dispatch->dossier_id,
                'direction_id' => $dispatch->direction_id, 'statut' => TraitementDirectionStatut::RECU_SECRETARIAT,
                'recu_par_secretariat_id' => $secretariat->id, 'recu_secretariat_at' => $dispatch->accuse_reception_at,
            ],
        );
        if ($traitement->wasRecentlyCreated) {
            $this->audit->enregistrer('traitement_direction.recu_secretariat', $traitement, $secretariat, [
                'dispatch_courrier_id' => $dispatch->id,
                'direction_id' => $dispatch->direction_id,
            ]);
        }

        return $traitement;
    }

    public function transmettreDirecteur(TraitementDirection $traitement, User $secretariat, ?string $note): TraitementDirection
    {
        $traitement = DB::transaction(function () use ($traitement, $secretariat, $note) {
            $traitement = TraitementDirection::query()->lockForUpdate()->findOrFail($traitement->id);
            if ($traitement->statut !== TraitementDirectionStatut::RECU_SECRETARIAT || $secretariat->role !== UserRole::SECRETARIAT_DIRECTION || $secretariat->direction_id !== $traitement->direction_id) {
                throw ValidationException::withMessages(['traitement' => 'Ce traitement ne peut pas être transmis par ce secrétariat.']);
            }
            $directeurs = User::query()->where('direction_id', $traitement->direction_id)->where('role', UserRole::DIRECTEUR_DIRECTION)->get();
            if ($directeurs->isEmpty()) {
                $directeurs = User::query()->where('direction_id', $traitement->direction_id)->where('role', UserRole::RESPONSABLE_DIRECTION)->get();
            }
            if ($directeurs->count() !== 1) {
                throw ValidationException::withMessages(['directeur' => $directeurs->isEmpty() ? 'Aucun Directeur actif unique n’est affecté à cette direction.' : 'Plusieurs Directeurs sont affectés à cette direction. Corrigez l’organisation avant transmission.']);
            }
            $directeur = $directeurs->firstOrFail();
            $traitement->update([
                'statut' => TraitementDirectionStatut::TRANSMIS_DIRECTEUR,
                'transmis_par_secretariat_id' => $secretariat->id, 'directeur_id' => $directeur->id,
                'note_transmission' => $note, 'transmis_directeur_at' => now(),
            ]);
            $this->audit->enregistrer('traitement_direction.transmis_directeur', $traitement, $secretariat, ['directeur_id' => $directeur->id, 'direction_id' => $traitement->direction_id]);

            return $traitement;
        });
        DB::afterCommit(fn () => $this->notifications->notifier($traitement->directeur, new TraitementDirectionNotification($traitement, 'transmis_directeur')));

        return $this->charger($traitement);
    }

    public function prendreEnCharge(TraitementDirection $traitement, User $directeur): TraitementDirection
    {
        return DB::transaction(function () use ($traitement, $directeur) {
            $traitement = TraitementDirection::query()->lockForUpdate()->findOrFail($traitement->id);
            $this->verifierDirecteur($traitement, $directeur, TraitementDirectionStatut::TRANSMIS_DIRECTEUR);
            $traitement->update(['statut' => TraitementDirectionStatut::EN_TRAITEMENT, 'pris_en_charge_par_id' => $directeur->id, 'pris_en_charge_at' => now()]);
            $this->audit->enregistrer('traitement_direction.pris_en_charge', $traitement, $directeur, ['direction_id' => $traitement->direction_id, 'autorite_directeur_id' => $traitement->directeur_id]);

            return $this->charger($traitement);
        });
    }

    public function decider(TraitementDirection $traitement, User $directeur, DecisionDirection $decision, ?string $commentaire): TraitementDirection
    {
        return DB::transaction(function () use ($traitement, $directeur, $decision, $commentaire) {
            $traitement = TraitementDirection::query()->lockForUpdate()->findOrFail($traitement->id);
            $this->verifierDirecteur($traitement, $directeur, TraitementDirectionStatut::EN_TRAITEMENT);
            $traitement->update([
                'statut' => TraitementDirectionStatut::TERMINE_DIRECTEUR, 'decision_directeur' => $decision,
                'commentaire_directeur' => $commentaire, 'decision_par_id' => $directeur->id, 'decision_at' => now(),
            ]);
            $this->audit->enregistrer('traitement_direction.decision_directeur', $traitement, $directeur, ['decision' => $decision->value, 'direction_id' => $traitement->direction_id, 'autorite_directeur_id' => $traitement->directeur_id]);

            return $this->charger($traitement);
        });
    }

    private function verifierDirecteur(TraitementDirection $traitement, User $directeur, TraitementDirectionStatut $statut): void
    {
        if ($traitement->statut !== $statut || $traitement->directeur_id !== $directeur->id || $traitement->direction_id !== $directeur->direction_id
            || ! in_array($directeur->role, [UserRole::DIRECTEUR_DIRECTION, UserRole::RESPONSABLE_DIRECTION], true)) {
            throw ValidationException::withMessages(['traitement' => 'Seul le Directeur désigné de cette direction peut effectuer cette action.']);
        }
    }

    private function charger(TraitementDirection $traitement): TraitementDirection
    {
        return $traitement->load(['courrier', 'dispatch.direction', 'direction', 'recuParSecretariat', 'transmisParSecretariat', 'directeur', 'prisEnChargePar', 'decisionPar']);
    }
}
