<?php

namespace Modules\Stagiaires\Support;

use Illuminate\Database\Eloquent\Builder;
use Modules\Courrier\Contracts\CircuitTransitionRules;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Kernel\Enums\Poste;
use Modules\Stagiaires\Models\Stagiaire;

/**
 * Un agent du circuit courrier (poste central, donc hors périmètre du
 * DirectionScope) n'a de raison de voir un dossier stagiaire que tant que
 * le courrier d'origine (la demande de stage) est encore dans sa propre
 * file de traitement — jamais après, une fois le courrier enregistré et le
 * dossier confié à la DFP. "Sa file" est calculée à partir des mêmes règles
 * de circuit que CircuitQueuePage côté frontend (CircuitTransitionRules),
 * jamais codé en dur ici.
 */
class VisibiliteStagiairePourCircuitCourrier
{
    public function __construct(private readonly CircuitTransitionRules $regles) {}

    public function estVisible(Poste $poste, Stagiaire $stagiaire): bool
    {
        $courrier = $stagiaire->courrier;

        if (! $courrier) {
            return false;
        }

        return in_array($poste, $this->postesAutorisesPour($courrier->statut, $courrier->necessite_avis_dg, $courrier->initie_par_dg), true);
    }

    public function appliquerFiltre(Builder $query, Poste $poste): Builder
    {
        $statutsVisibles = collect(CourrierStatut::cases())
            ->filter(fn (CourrierStatut $statut) => $this->posteAutorisePourAuMoinsUneVariante($poste, $statut))
            ->values()
            ->all();

        return $query->whereHas('courrier', fn (Builder $q) => $q->whereIn('statut', $statutsVisibles));
    }

    private function posteAutorisePourAuMoinsUneVariante(Poste $poste, CourrierStatut $statut): bool
    {
        foreach ([false, true] as $necessiteAvisDg) {
            foreach ([false, true] as $initieParDg) {
                if (in_array($poste, $this->postesAutorisesPour($statut, $necessiteAvisDg, $initieParDg), true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return Poste[]
     */
    private function postesAutorisesPour(CourrierStatut $statut, bool $necessiteAvisDg, bool $initieParDg): array
    {
        return $this->regles->postesAutorises($statut, $necessiteAvisDg, $initieParDg);
    }
}
