<?php

namespace Modules\Stagiaires\Console;

use Illuminate\Console\Command;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Mail\StageEcheanceProcheMail;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Notifications\StageEcheanceProcheNotification;

/**
 * Alerte automatique dix jours avant l'échéance prévue d'un stage.
 * Prévu pour tourner quotidiennement (voir configureSchedules dans
 * StagiairesServiceProvider).
 */
class VerifierEcheancesStageCommand extends Command
{
    protected $signature = 'stagiaires:verifier-echeances';

    protected $description = "Notifie la DFP et la direction d'accueil des stages se terminant dans 10 jours";

    public function __construct(private readonly NotificationService $notifications)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $echeance = now()->addDays(10)->toDateString();

        $stagiaires = Stagiaire::query()
            ->where('statut', StagiaireStatut::STAGE_EN_COURS)
            ->whereDate('date_fin_stage', $echeance)
            ->whereNull('alerte_echeance_envoyee_at')
            ->get();

        $dfp = User::query()->where('role', UserRole::AGENT_DFP)->get();

        foreach ($stagiaires as $stagiaire) {
            $destinataires = $dfp->merge(
                User::query()
                    ->whereIn('role', [UserRole::DIRECTEUR_DIRECTION, UserRole::RESPONSABLE_DIRECTION])
                    ->where('direction_id', $stagiaire->direction_id)
                    ->get()
            );

            foreach ($destinataires as $destinataire) {
                $this->notifications->notifier($destinataire, new StageEcheanceProcheNotification($stagiaire));
                $this->notifications->envoyerMail($destinataire->email, new StageEcheanceProcheMail($stagiaire));
            }

            $stagiaire->update(['alerte_echeance_envoyee_at' => now()]);
        }

        $this->info("{$stagiaires->count()} alerte(s) d'échéance envoyée(s).");

        return self::SUCCESS;
    }
}
