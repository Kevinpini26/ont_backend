<?php

namespace Modules\Courrier\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Models\ClassementDocument;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierPieceJointe;
use Modules\Courrier\Models\CourrierTransition;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Notifications\DispatchCourrierNotification;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;
use Modules\Kernel\Support\DgDisponibilite;

class DispatchCourrierService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly DelegationResolver $delegations,
        private readonly TraitementDirectionService $traitementsDirection,
        private readonly DossierWorkflowGuard $dossiers,
        private readonly CycleDecisionnelService $cycles,
    ) {}

    /** @param array<int, array<string, mixed>> $destinations */
    public function decider(Courrier $courrier, User $acteur, array $destinations): Courrier
    {
        $autorite = $this->autoriteDe($acteur);
        $nouveauxDispatchs = [];

        $courrier = DB::transaction(function () use ($courrier, $acteur, $destinations, $autorite, &$nouveauxDispatchs) {
            $courrier = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($courrier->id);
            $ancienStatut = $courrier->statut;

            if (! in_array($courrier->statut, [CourrierStatut::EN_ATTENTE_AVIS_DG, CourrierStatut::DISPATCH_EXECUTE, CourrierStatut::ENVOYE], true) || $courrier->dossier_id === null) {
                throw ValidationException::withMessages(['courrier' => "Le courrier n'est pas disponible pour une décision de dispatch."]);
            }

            if ($courrier->statut === CourrierStatut::ENVOYE
                && collect($destinations)->contains(fn (array $destination) => ($destination['type'] ?? null) !== DispatchTypeDestination::CLASSEMENT->value)) {
                throw ValidationException::withMessages([
                    'destinations' => 'Un courrier sortant déjà envoyé ne peut être orienté que vers le classement.',
                ]);
            }
            $this->cycles->assertReceptionDg($courrier);
            $this->cycles->assertPeutOuvrir($courrier);
            $cycle = $this->cycles->prochainNumero($courrier);

            $signatures = [];
            foreach ($destinations as $index => $destination) {
                $type = DispatchTypeDestination::from($destination['type']);
                $signature = $this->validerDestination($type, $destination, $index);
                if (in_array($signature, $signatures, true)) {
                    throw ValidationException::withMessages(["destinations.$index" => 'Cette destination est présente plusieurs fois.']);
                }
                $signatures[] = $signature;

                $nouveauxDispatchs[] = DispatchCourrier::query()->create([
                    'courrier_id' => $courrier->id,
                    'dossier_id' => $courrier->dossier_id,
                    'cycle' => $cycle,
                    'type_destination' => $type,
                    'direction_id' => $type === DispatchTypeDestination::DIRECTION ? $destination['direction_id'] : null,
                    'destinataire_externe_nom' => $type === DispatchTypeDestination::EXTERIEUR ? $destination['destinataire_externe_nom'] : null,
                    'destinataire_externe_email' => $type === DispatchTypeDestination::EXTERIEUR ? ($destination['destinataire_externe_email'] ?? null) : null,
                    'instruction' => $destination['instruction'],
                    'decisionnaire_id' => $acteur->id,
                    'decisionnaire_poste' => $acteur->poste,
                    'autorite_poste' => $autorite,
                    'decide_at' => now(),
                    'statut' => DispatchStatut::EN_ATTENTE,
                ]);
            }

            $courrier->update([
                'avis_dg' => AvisDg::FAVORABLE,
                'avis_dg_rendu_at' => now(),
                'avis_dg_rendu_par_id' => $acteur->id,
                'avis_dg_rendu_en_interim' => $autorite !== $acteur->poste,
                'statut' => CourrierStatut::EN_DISPATCH,
            ]);
            $this->tracer($courrier, $ancienStatut, CourrierStatut::EN_DISPATCH, $acteur, Poste::SECRETARIAT_2);
            $this->audit->enregistrer('dispatch.decision_prise', $courrier, $acteur, ['nombre' => count($destinations), 'autorite_poste' => $autorite->value, 'cycle' => $cycle]);

            return $courrier;
        });

        $dispatchs = collect($nouveauxDispatchs);
        DB::afterCommit(function () use ($dispatchs) {
            User::query()->where('poste', Poste::SECRETARIAT_2)->each(function (User $user) use ($dispatchs) {
                foreach ($dispatchs as $dispatch) {
                    $this->notifications->notifier($user, new DispatchCourrierNotification($dispatch, 'a_executer'));
                }
            });
        });

        return $courrier->load(['dispatchs.direction', 'dispatchs.decisionnaire', 'dispatchs.executePar', 'dispatchs.accuseReceptionPar']);
    }

    public function executer(DispatchCourrier $dispatch, User $acteur, ?UploadedFile $preuve = null, ?string $reference = null): DispatchCourrier
    {
        if ($dispatch->type_destination === DispatchTypeDestination::CLASSEMENT) {
            throw ValidationException::withMessages(['dispatch' => 'Une décision de classement doit être exécutée par l’action Classer.']);
        }

        return $this->executerAction($dispatch, $acteur, $preuve, $reference);
    }

    public function executerClassement(DispatchCourrier $dispatch, User $acteur, ClassementDocument $classement): DispatchCourrier
    {
        if ($dispatch->type_destination !== DispatchTypeDestination::CLASSEMENT
            || $classement->dispatch_courrier_id !== $dispatch->id
            || $classement->courrier_id !== $dispatch->courrier_id
            || ! $classement->exists) {
            throw ValidationException::withMessages(['dispatch' => 'Le classement matériel correspondant est requis.']);
        }

        return $this->executerAction($dispatch, $acteur, reference: 'CLASSEMENT-'.$classement->id, classement: true);
    }

    public function verrouillerPourExecution(DispatchCourrier $dispatch, User $acteur): DispatchCourrier
    {
        $courrier = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($dispatch->courrier_id);
        $dispatch = DispatchCourrier::query()->lockForUpdate()->findOrFail($dispatch->id);
        $this->dossiers->assertCourrierActifPourNouvelleActivite($courrier);
        if ($dispatch->statut !== DispatchStatut::EN_ATTENTE
            || ! $this->delegations->utilisateurHabilite($acteur, [Poste::SECRETARIAT_2])
            || $dispatch->courrier_id !== $courrier->id
            || $dispatch->dossier_id !== $courrier->dossier_id
            || $courrier->statut !== CourrierStatut::EN_DISPATCH
            || $dispatch->cycle !== (int) $courrier->dispatchs()->max('cycle')) {
            throw ValidationException::withMessages(['dispatch' => 'Ce dispatch ne peut pas être exécuté : état, dossier ou cycle incompatible.']);
        }
        $transition = $courrier->transitions()->where('destinataire_poste', Poste::SECRETARIAT_2->value)
            ->where('statut', CourrierStatut::EN_DISPATCH)->latest('id')->first();
        if ($transition !== null && $transition->accuse_reception_at === null) {
            throw ValidationException::withMessages(['dispatch' => 'Le bordereau doit être réceptionné par SEC2 avant exécution.']);
        }

        return $dispatch;
    }

    private function executerAction(DispatchCourrier $dispatch, User $acteur, ?UploadedFile $preuve = null, ?string $reference = null, bool $classement = false): DispatchCourrier
    {
        $dispatch = DB::transaction(function () use ($dispatch, $acteur, $preuve, $reference, $classement) {
            $dispatch = $this->verrouillerPourExecution($dispatch, $acteur);
            if (($dispatch->type_destination === DispatchTypeDestination::CLASSEMENT) !== $classement
                || ($classement && ! ClassementDocument::query()->where('dispatch_courrier_id', $dispatch->id)->where('courrier_id', $dispatch->courrier_id)->exists())) {
                throw ValidationException::withMessages(['dispatch' => 'Le type de décision exige son action institutionnelle et son classement matériel.']);
            }
            if ($dispatch->type_destination === DispatchTypeDestination::EXTERIEUR && $preuve === null && blank($reference)) {
                throw ValidationException::withMessages(['preuve' => "Une référence ou une preuve d'envoi est requise pour un destinataire extérieur."]);
            }

            $piece = $preuve ? $this->stockerPreuve($dispatch, $acteur, $preuve) : null;
            $dispatch->update([
                'statut' => DispatchStatut::EXECUTE,
                'execute_par_id' => $acteur->id,
                'execute_at' => now(),
                'reference_transmission' => $reference,
                'preuve_piece_jointe_id' => $piece?->id,
            ]);
            $this->audit->enregistrer('dispatch.execute', $dispatch, $acteur, ['reference' => $reference, 'preuve_piece_jointe_id' => $piece?->id]);

            $courrier = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($dispatch->courrier_id);
            if (! $courrier->dispatchs()->where('statut', DispatchStatut::EN_ATTENTE)->exists()) {
                $ancien = $courrier->statut;
                $courrier->update(['statut' => CourrierStatut::DISPATCH_EXECUTE]);
                $this->tracer($courrier, $ancien, CourrierStatut::DISPATCH_EXECUTE, $acteur);
            }

            return $dispatch;
        });

        if ($dispatch->type_destination === DispatchTypeDestination::DIRECTION) {
            DB::afterCommit(function () use ($dispatch) {
                User::query()->where('role', UserRole::SECRETARIAT_DIRECTION)->where('direction_id', $dispatch->direction_id)
                    ->each(fn (User $user) => $this->notifications->notifier($user, new DispatchCourrierNotification($dispatch, 'recu_direction')));
            });
        }

        return $dispatch->load(['courrier', 'direction', 'decisionnaire', 'executePar']);
    }

    /** Compatibilité de l'ancien bouton SEC2 : matérialise les imputations déjà décidées, puis les exécute. */
    public function executerDepuisImputations(Courrier $courrier, User $acteur): Courrier
    {
        return DB::transaction(function () use ($courrier, $acteur) {
            $courrier = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($courrier->id);
            if (! $this->delegations->utilisateurHabilite($acteur, [Poste::SECRETARIAT_2])) {
                throw ValidationException::withMessages(['courrier' => "Vous n'êtes pas habilité à exécuter ce dispatch."]);
            }
            $this->dossiers->assertCourrierActifPourNouvelleActivite($courrier);
            if ($courrier->statut !== CourrierStatut::EN_DISPATCH) {
                throw ValidationException::withMessages(['courrier' => "Le courrier n'est pas en attente de dispatch."]);
            }
            if ($courrier->transitions()->where('statut', CourrierStatut::EN_DISPATCH)->whereNull('accuse_reception_at')->exists()) {
                throw ValidationException::withMessages(['courrier' => 'Le bordereau doit être réceptionné par SEC2 avant exécution.']);
            }

            if (! $courrier->dispatchs()->exists()) {
                DB::transaction(function () use ($courrier) {
                    $imputations = $courrier->imputations()->with('imputeePar')->lockForUpdate()->get();
                    if ($imputations->isEmpty()) {
                        throw ValidationException::withMessages(['courrier' => 'Aucune décision de destination ne peut être exécutée.']);
                    }
                    foreach ($imputations as $imputation) {
                        $auteur = $imputation->imputeePar;
                        if ($auteur === null) {
                            throw ValidationException::withMessages(['courrier' => "Cette imputation historique ne peut pas être exécutée car son autorité d'origine n'est pas identifiable."]);
                        }
                        DispatchCourrier::query()->create([
                            'courrier_id' => $courrier->id, 'dossier_id' => $courrier->dossier_id,
                            'type_destination' => DispatchTypeDestination::DIRECTION, 'direction_id' => $imputation->direction_id,
                            'instruction' => 'Orientation issue de l’imputation existante.',
                            'decisionnaire_id' => $auteur->id,
                            'decisionnaire_poste' => $auteur->poste,
                            'autorite_poste' => Poste::DG,
                            'decide_at' => $imputation->created_at,
                            'statut' => DispatchStatut::EN_ATTENTE,
                        ]);
                    }
                });
            }

            foreach (DispatchCourrier::query()->where('courrier_id', $courrier->id)->where('statut', DispatchStatut::EN_ATTENTE)->get() as $dispatch) {
                $this->executer($dispatch, $acteur);
            }

            return Courrier::withoutGlobalScopes()->findOrFail($courrier->id)->load('dispatchs.direction');
        });
    }

    public function accuserReception(DispatchCourrier $dispatch, User $acteur): DispatchCourrier
    {
        return DB::transaction(function () use ($dispatch, $acteur) {
            $dispatch = DispatchCourrier::query()->lockForUpdate()->findOrFail($dispatch->id);
            $this->dossiers->assertActifPourNouvelleActivite($dispatch->dossier_id);
            if ($dispatch->type_destination !== DispatchTypeDestination::DIRECTION || $dispatch->statut !== DispatchStatut::EXECUTE
                || $dispatch->accuse_reception_at !== null || $acteur->role !== UserRole::SECRETARIAT_DIRECTION
                || $acteur->direction_id !== $dispatch->direction_id) {
                throw ValidationException::withMessages(['dispatch' => 'La réception de ce dispatch ne peut pas être confirmée.']);
            }
            $dispatch->update(['accuse_reception_par_id' => $acteur->id, 'accuse_reception_at' => now()]);
            $this->traitementsDirection->creerDepuisReception($dispatch, $acteur);
            $this->audit->enregistrer('dispatch.reception_confirmee', $dispatch, $acteur);

            return $dispatch->load(['courrier', 'direction', 'decisionnaire', 'executePar', 'accuseReceptionPar']);
        });
    }

    private function autoriteDe(User $acteur): Poste
    {
        if ($acteur->poste === Poste::DG) {
            return Poste::DG;
        }
        if ($acteur->poste === Poste::DGA && ! DgDisponibilite::estDisponible()) {
            return Poste::DG;
        }
        $delegue = $this->delegations->posteDelegueAujourdhui($acteur);
        if ($delegue === Poste::DG) {
            return Poste::DG;
        }
        throw ValidationException::withMessages(['dispatch' => "Vous n'êtes pas habilité à décider ce dispatch."]);
    }

    /** @param array<string, mixed> $destination */
    private function validerDestination(DispatchTypeDestination $type, array $destination, int $index): string
    {
        if ($type === DispatchTypeDestination::DIRECTION) {
            $direction = Direction::query()->find($destination['direction_id'] ?? null);
            if (! $direction?->est_operationnelle) {
                throw ValidationException::withMessages(["destinations.$index.direction_id" => 'La direction doit être opérationnelle.']);
            }
            if (filled($destination['destinataire_externe_nom'] ?? null)) {
                throw ValidationException::withMessages(["destinations.$index" => 'Une destination interne ne peut pas porter un destinataire extérieur.']);
            }

            return 'direction:'.$direction->id;
        }
        if ($type === DispatchTypeDestination::EXTERIEUR) {
            if (blank($destination['destinataire_externe_nom'] ?? null) || filled($destination['direction_id'] ?? null)) {
                throw ValidationException::withMessages(["destinations.$index" => 'Le destinataire extérieur est incomplet ou incohérent.']);
            }

            return 'exterieur:'.mb_strtolower(trim($destination['destinataire_externe_nom'])).':'.mb_strtolower(trim($destination['destinataire_externe_email'] ?? ''));
        }
        if (filled($destination['direction_id'] ?? null) || filled($destination['destinataire_externe_nom'] ?? null)) {
            throw ValidationException::withMessages(["destinations.$index" => 'Un classement ne porte aucune destination.']);
        }

        return 'classement';
    }

    private function stockerPreuve(DispatchCourrier $dispatch, User $acteur, UploadedFile $preuve): CourrierPieceJointe
    {
        $chemin = $preuve->store("courriers/{$dispatch->courrier_id}/dispatchs", 'local');

        return CourrierPieceJointe::query()->create([
            'courrier_id' => $dispatch->courrier_id,
            'libelle' => 'Preuve de dispatch #'.$dispatch->id,
            'chemin' => $chemin,
            'type_mime' => $preuve->getMimeType(),
            'taille_octets' => $preuve->getSize(),
            'ordre' => ((int) CourrierPieceJointe::query()->where('courrier_id', $dispatch->courrier_id)->max('ordre')) + 1,
            'uploaded_by_id' => $acteur->id,
        ]);
    }

    private function tracer(Courrier $courrier, ?CourrierStatut $ancien, CourrierStatut $nouveau, User $acteur, ?Poste $destinataire = null): void
    {
        CourrierTransition::query()->create([
            'courrier_id' => $courrier->id, 'statut' => $nouveau, 'ancien_statut' => $ancien, 'nouveau_statut' => $nouveau,
            'tour' => $courrier->tour, 'changed_by_id' => $acteur->id, 'expediteur_poste' => $acteur->poste?->value,
            'agi_en_interim' => $this->delegations->agitEnInterimPour($acteur, [Poste::DG, Poste::SECRETARIAT_2]),
            'destinataire_poste' => $destinataire?->value, 'created_at' => now(),
        ]);
    }
}
