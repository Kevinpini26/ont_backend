<?php

namespace Modules\Kernel\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\DgInterim;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DgAuthorityResolver;

class DgInterimService
{
    public function __construct(
        private readonly DgAuthorityResolver $autorite,
        private readonly AuditLogger $audit,
    ) {}

    public function ouvrir(User $acteur, User $dga, string $motif): DgInterim
    {
        $this->autoriserGestion($acteur);
        $motif = trim($motif);
        if ($motif === '' || $dga->poste !== Poste::DGA) {
            throw ValidationException::withMessages(['interim' => 'Motif et DGA habilité requis.']);
        }

        return DB::transaction(function () use ($acteur, $dga, $motif): DgInterim {
            $dg = $this->autorite->verrouillerTitulaire();
            if ($dg !== null && $acteur->role !== UserRole::ADMINISTRATEUR && $acteur->id !== $dg->id) {
                abort(403);
            }
            if ($dg === null || $this->autorite->interimOuvert() !== null) {
                throw ValidationException::withMessages(['interim' => 'Aucun DG titulaire ou intérim déjà ouvert.']);
            }
            $interim = DgInterim::query()->create([
                'dg_titulaire_id' => $dg->id,
                'dga_interimaire_id' => $dga->id,
                'motif' => $motif,
                'started_at' => now(),
                'started_by_id' => $acteur->id,
            ]);
            $dg->update(['dg_disponible' => false]);
            $this->audit->enregistrer('dg.interim_ouvert', $interim, $acteur, [
                'dg_titulaire_id' => $dg->id,
                'dga_interimaire_id' => $dga->id,
                'motif' => $motif,
                'source_autorite' => 'titulaire_ou_administrateur',
            ]);

            return $interim;
        });
    }

    public function terminer(User $acteur): DgInterim
    {
        $this->autoriserGestion($acteur);

        return DB::transaction(function () use ($acteur): DgInterim {
            $dg = $this->autorite->verrouillerTitulaire();
            if ($dg !== null && $acteur->role !== UserRole::ADMINISTRATEUR && $acteur->id !== $dg->id) {
                abort(403);
            }
            $interim = $this->autorite->interimOuvert();
            if ($dg === null || $interim === null || $interim->dg_titulaire_id !== $dg->id) {
                throw ValidationException::withMessages(['interim' => 'Aucun intérim ouvert à terminer.']);
            }
            $interim->update(['ended_at' => now(), 'ended_by_id' => $acteur->id]);
            $dg->update(['dg_disponible' => true]);
            $this->audit->enregistrer('dg.interim_termine', $interim, $acteur, [
                'dg_titulaire_id' => $dg->id,
                'dga_interimaire_id' => $interim->dga_interimaire_id,
                'ended_at' => $interim->ended_at?->toIso8601String(),
            ]);

            return $interim;
        });
    }

    private function autoriserGestion(User $acteur): void
    {
        abort_unless($acteur->role === UserRole::ADMINISTRATEUR || $acteur->poste === Poste::DG, 403);
    }
}
