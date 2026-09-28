<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Models\InstructionCourrierDg;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;
use Modules\Kernel\Support\DgAuthorityResolver;

class InstructionCourrierDgService
{
    public function __construct(
        private readonly DelegationResolver $delegations,
        private readonly AuditLogger $audit,
        private readonly DgAuthorityResolver $autoriteDg,
    ) {}

    public function peutDonner(User $acteur): bool
    {
        return ! $this->estSec1($acteur)
            && $this->autoriteDg->estAutorite($acteur);
    }

    public function peutExecuter(User $acteur, InstructionCourrierDg $instruction): bool
    {
        return $this->estSec1($acteur)
            && ($instruction->destinataire_user_id === null || $instruction->destinataire_user_id === $acteur->id);
    }

    public function estSec1(User $acteur): bool
    {
        return $this->delegations->utilisateurHabilite($acteur, [Poste::SECRETARIAT_1]);
    }

    public function peutVoir(User $acteur, InstructionCourrierDg $instruction): bool
    {
        return $this->peutDonner($acteur) || $this->peutExecuter($acteur, $instruction);
    }

    public function creer(User $donneur, array $donnees): InstructionCourrierDg
    {
        if (isset($donnees['destinataire_user_id'])) {
            $destinataire = User::query()->findOrFail($donnees['destinataire_user_id']);
            if (! $this->delegations->utilisateurHabilite($destinataire, [Poste::SECRETARIAT_1])) {
                throw ValidationException::withMessages(['destinataire_user_id' => 'Le destinataire doit être habilité au poste SEC1.']);
            }
        }

        return DB::transaction(function () use ($donneur, $donnees) {
            abort_unless($this->peutDonner($donneur), 403);
            $instruction = InstructionCourrierDg::query()->create([
                'donneur_id' => $donneur->id,
                'destinataire_user_id' => $donnees['destinataire_user_id'] ?? null,
                'instruction' => $donnees['instruction'],
                'expire_at' => $donnees['expire_at'] ?? null,
            ]);
            $this->audit->enregistrer('instruction_courrier_dg.creee', $instruction, $donneur, [
                'destinataire_user_id' => $instruction->destinataire_user_id,
                'instruction' => $instruction->instruction,
                'expire_at' => $instruction->expire_at?->toIso8601String(),
                'source_autorite' => $this->autoriteDg->source($donneur),
            ]);

            return $instruction;
        });
    }

    public function ouvrir(User $acteur, InstructionCourrierDg $instruction): InstructionCourrierDg
    {
        abort_unless($this->peutVoir($acteur, $instruction), 404);
        if (! $this->peutExecuter($acteur, $instruction) || ! $instruction->active()) {
            return $instruction;
        }

        return DB::transaction(function () use ($acteur, $instruction) {
            $verrouillee = InstructionCourrierDg::query()->lockForUpdate()->findOrFail($instruction->id);
            if ($verrouillee->active() && $verrouillee->ouvert_at === null) {
                $verrouillee->update(['ouvert_at' => now(), 'ouvert_par_id' => $acteur->id]);
                $this->audit->enregistrer('instruction_courrier_dg.ouverte', $verrouillee, $acteur);
            }

            return $verrouillee;
        });
    }

    public function annuler(User $acteur, InstructionCourrierDg $instruction): InstructionCourrierDg
    {
        return DB::transaction(function () use ($acteur, $instruction) {
            abort_unless($this->peutDonner($acteur), 403);
            abort_unless($this->peutVoir($acteur, $instruction), 404);
            $verrouillee = InstructionCourrierDg::query()->lockForUpdate()->findOrFail($instruction->id);
            if (! $verrouillee->active()) {
                throw ValidationException::withMessages(['instruction' => 'Cette instruction ne peut plus être annulée.']);
            }
            $verrouillee->update(['annule_at' => now()]);
            $this->audit->enregistrer('instruction_courrier_dg.annulee', $verrouillee, $acteur, ['source_autorite' => $this->autoriteDg->source($acteur)]);

            return $verrouillee;
        });
    }
}
