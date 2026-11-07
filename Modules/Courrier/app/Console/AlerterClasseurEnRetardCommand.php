<?php

namespace Modules\Courrier\Console;

use Illuminate\Console\Command;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Notifications\ClasseurEnRetardNotification;
use Modules\Courrier\Support\ClasseurEnRetard;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DgAuthorityResolver;

/**
 * Lot 6 : alerte SEC1 et relance la DG une seule fois pour chaque courrier au classeur au-delà du délai
 * (heures ouvrées). Ne change jamais le statut : SEC1 reste seul à transmettre le courrier à la DG.
 */
class AlerterClasseurEnRetardCommand extends Command
{
    protected $signature = 'courrier:alerter-classeur';

    protected $description = "Alerte SEC1 et la DG (une fois) pour les courriers au classeur d'attente au-delà du délai en heures ouvrées";

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly DgAuthorityResolver $autoriteDg,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $courriers = ClasseurEnRetard::filtrer(Courrier::query()->withoutGlobalScopes())
            ->whereNull('classeur_alerte_envoyee_at')
            ->get();

        $sec1 = User::query()->where('role', UserRole::AGENT_CIRCUIT_COURRIER)->where('poste', Poste::SECRETARIAT_1)->where('actif', true)->get();
        $interim = $this->autoriteDg->interimOuvert();
        $dg = $interim !== null
            ? User::query()->whereKey($interim->dga_interimaire_id)->get()
            : User::query()->where('role', UserRole::AGENT_CIRCUIT_COURRIER)->where('poste', Poste::DG)->where('actif', true)->get();

        foreach ($courriers as $courrier) {
            /** @var Courrier $courrier */
            $sec1->each(fn (User $u) => $this->notifications->notifier($u, new ClasseurEnRetardNotification($courrier, 'sec1')));
            $dg->each(fn (User $u) => $this->notifications->notifier($u, new ClasseurEnRetardNotification($courrier, 'dg')));
            $courrier->forceFill(['classeur_alerte_envoyee_at' => now()])->save();
            $this->audit->enregistrer('courrier.classeur_en_retard_alerte', $courrier, null, [
                'delai_heures_ouvrees' => ClasseurEnRetard::delaiHeures(),
            ]);
        }

        $this->info("{$courriers->count()} alerte(s) de classeur envoyée(s).");

        return self::SUCCESS;
    }
}
