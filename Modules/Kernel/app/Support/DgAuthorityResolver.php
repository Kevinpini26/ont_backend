<?php

namespace Modules\Kernel\Support;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\DelegationPoste;
use Modules\Kernel\Models\DgInterim;
use Modules\Kernel\Models\User;

/** L'unique résolution de l'autorité opérationnelle DG du circuit courrier. */
class DgAuthorityResolver
{
    public function __construct(private readonly DelegationResolver $delegations) {}

    public function interimOuvert(): ?DgInterim
    {
        $query = DgInterim::query()->whereNull('ended_at');
        if (DB::transactionLevel() > 0) {
            // Même verrou que la fermeture ; une action commencée avant la
            // fermeture peut terminer, une action ultérieure est refusée.
            $this->verrouillerTitulaire();
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function source(User $acteur): ?string
    {
        // Ne verrouille pas la DG pour chaque transition SEC1/SEC2 : ces
        // acteurs ne peuvent jamais devenir autorité DG par leur poste.
        if (! in_array($acteur->poste, [Poste::DG, Poste::DGA], true)
            && ! DelegationPoste::query()->where('delegataire_id', $acteur->id)
                ->where('poste', Poste::DG->value)->activesLe(Date::today())->exists()) {
            return null;
        }
        $interim = $this->interimOuvert();
        if ($interim !== null) {
            return $acteur->id === $interim->dga_interimaire_id ? 'interim_dga' : null;
        }

        if ($acteur->poste === Poste::DG && $acteur->id === $this->titulaire()?->id) {
            return 'titulaire';
        }

        return $this->delegations->posteDelegueAujourdhui($acteur) === Poste::DG ? 'delegation' : null;
    }

    public function estAutorite(User $acteur): bool
    {
        return $this->source($acteur) !== null;
    }

    public function estAutoritePourTeleverserScanSigne(User $acteur): bool
    {
        return in_array($this->source($acteur), ['titulaire', 'interim_dga'], true);
    }

    public function titulaire(): ?User
    {
        return User::query()->where('poste', Poste::DG->value)->orderBy('id')->first();
    }

    public function verrouillerTitulaire(): ?User
    {
        return User::query()->where('poste', Poste::DG->value)->orderBy('id')->lockForUpdate()->first();
    }
}
