<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\ConfiageDgaStatut;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\ConfiageDga;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Notifications\CourrierDgNotification;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DgAuthorityResolver;

/**
 * Le DG confie un dossier au DGA « pour instruction » : le DGA (avec son
 * assistant, via une mission) l'analyse puis le retourne au DG avec une
 * recommandation. Le DG reste le décideur : la décision par le DGA lui-même
 * n'existe que par intérim ou délégation (voir DgAuthorityResolver).
 */
class ConfiageDgaService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly DgAuthorityResolver $autoriteDg,
        private readonly CycleDecisionnelService $cycles,
        private readonly DossierWorkflowGuard $dossiers,
    ) {}

    public function confier(Courrier $courrier, User $dg, string $consigne): ConfiageDga
    {
        [$confiage, $dga] = DB::transaction(function () use ($courrier, $dg, $consigne) {
            $courrier = $this->verrouillerSousControleDg($courrier);

            if ($this->autoriteDg->source($dg) !== 'titulaire') {
                throw ValidationException::withMessages(['courrier' => 'Seule la DG titulaire peut confier un dossier au DGA.']);
            }
            if ($courrier->confiageDgaEnCours() !== null) {
                throw ValidationException::withMessages(['courrier' => 'Ce dossier est déjà confié au DGA.']);
            }
            $dga = User::query()->where('poste', Poste::DGA->value)->where('actif', true)->orderBy('id')->first();
            if ($dga === null) {
                throw ValidationException::withMessages(['courrier' => 'Aucun DGA actif n’est disponible.']);
            }

            $confiage = ConfiageDga::query()->create([
                'courrier_id' => $courrier->id,
                'confie_par_id' => $dg->id,
                'dga_id' => $dga->id,
                'consigne' => $consigne,
                'statut' => ConfiageDgaStatut::EN_COURS,
                'confie_at' => now(),
            ]);
            $this->audit->enregistrer('courrier.confie_au_dga', $courrier, $dg, [
                'confiage_id' => $confiage->id,
                'dga_id' => $dga->id,
            ]);

            return [$confiage, $dga];
        });

        DB::afterCommit(fn () => $this->notifications->notifier(
            $dga,
            new CourrierDgNotification($confiage->courrier, 'confie_au_dga'),
        ));

        return $confiage;
    }

    public function retourner(Courrier $courrier, User $dga, string $recommandation): ConfiageDga
    {
        [$confiage, $titulaire] = DB::transaction(function () use ($courrier, $dga, $recommandation) {
            $courrier = $this->verrouillerSousControleDg($courrier);
            $confiage = $this->confiageEnCoursOuErreur($courrier);

            if ($dga->poste !== Poste::DGA) {
                throw ValidationException::withMessages(['courrier' => 'Seul le DGA peut retourner ce dossier à la DG.']);
            }

            $confiage->update([
                'statut' => ConfiageDgaStatut::RETOURNE,
                'retourne_par_id' => $dga->id,
                'retourne_at' => now(),
                'recommandation' => $recommandation,
            ]);
            $this->audit->enregistrer('courrier.retourne_au_dg', $courrier, $dga, ['confiage_id' => $confiage->id]);

            return [$confiage, $this->autoriteDg->titulaire()];
        });

        if ($titulaire !== null) {
            DB::afterCommit(fn () => $this->notifications->notifier(
                $titulaire,
                new CourrierDgNotification($confiage->courrier, 'retour_dga'),
            ));
        }

        return $confiage;
    }

    public function reprendre(Courrier $courrier, User $dg): ConfiageDga
    {
        return DB::transaction(function () use ($courrier, $dg) {
            $courrier = $this->verrouillerSousControleDg($courrier);
            $confiage = $this->confiageEnCoursOuErreur($courrier);

            if ($this->autoriteDg->source($dg) !== 'titulaire') {
                throw ValidationException::withMessages(['courrier' => 'Seule la DG titulaire peut reprendre un dossier confié au DGA.']);
            }

            $confiage->update(['statut' => ConfiageDgaStatut::REPRIS, 'repris_at' => now()]);
            $this->audit->enregistrer('courrier.repris_du_dga', $courrier, $dg, ['confiage_id' => $confiage->id]);

            return $confiage;
        });
    }

    private function verrouillerSousControleDg(Courrier $courrier): Courrier
    {
        $courrier = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($courrier->id);
        $this->dossiers->assertCourrierActifPourNouvelleActivite($courrier);

        if ($courrier->statut !== CourrierStatut::EN_ATTENTE_AVIS_DG) {
            throw ValidationException::withMessages(['courrier' => 'Le dossier n’est pas en attente d’avis de la DG.']);
        }
        $this->cycles->assertReceptionDg($courrier);

        return $courrier;
    }

    private function confiageEnCoursOuErreur(Courrier $courrier): ConfiageDga
    {
        return $courrier->confiageDgaEnCours()
            ?? throw ValidationException::withMessages(['courrier' => 'Ce dossier n’est pas confié au DGA.']);
    }
}
