<?php

namespace Modules\Courrier\Console;

use Illuminate\Console\Command;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Mail\AvisDgEnAttenteMail;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DgAuthorityResolver;

/**
 * Relance la DG lorsqu'un courrier attend son avis depuis plus de 48h.
 * Prévu pour tourner toutes les heures (voir configureSchedules dans
 * CourrierServiceProvider) — la fenêtre de 48h ne dépend donc pas de
 * l'heure de la journée où la commande s'exécute.
 */
class RelancerAvisDgEnAttenteCommand extends Command
{
    protected $signature = 'courrier:relancer-avis-dg-en-attente {--seuil-heures=48}';

    protected $description = "Relance la DG pour les courriers en attente d'avis depuis plus de 48h";

    public function __construct(private readonly NotificationService $notifications, private readonly DgAuthorityResolver $autoriteDg)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $seuilHeures = (int) $this->option('seuil-heures');
        $seuil = now()->subHours($seuilHeures);

        $courriers = Courrier::query()
            ->withoutGlobalScopes()
            ->where('statut', CourrierStatut::EN_ATTENTE_AVIS_DG)
            ->whereNull('relance_avis_dg_envoyee_at')
            ->whereHas('transitions', fn ($q) => $q
                ->where('statut', CourrierStatut::EN_ATTENTE_AVIS_DG)
                ->where('created_at', '<', $seuil))
            ->get();

        $interim = $this->autoriteDg->interimOuvert();
        $dg = $interim !== null
            ? User::query()->whereKey($interim->dga_interimaire_id)->get()
            : User::query()->where('role', UserRole::AGENT_CIRCUIT_COURRIER)->where('poste', Poste::DG)->get();

        foreach ($courriers as $courrier) {
            foreach ($dg as $destinataire) {
                $this->notifications->envoyerMail($destinataire->email, new AvisDgEnAttenteMail($courrier));
            }

            $courrier->update(['relance_avis_dg_envoyee_at' => now()]);
        }

        $this->info("{$courriers->count()} relance(s) d'avis DG envoyée(s).");

        return self::SUCCESS;
    }
}
