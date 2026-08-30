<?php

namespace Modules\Courrier\Support;

use Modules\Courrier\Contracts\CircuitTransitionRules;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Kernel\Enums\Poste;

class ConfigCircuitTransitionRules implements CircuitTransitionRules
{
    public function statutSuivant(CourrierStatut $statut, bool $necessiteAvisDg, bool $initieParDg = false, bool $sortant = false): ?CourrierStatut
    {
        $circuit = $this->circuit($necessiteAvisDg, $initieParDg, $sortant);
        $suivant = config("courrier.circuit_transitions.{$circuit}.{$statut->value}.suivant");

        return $suivant ? CourrierStatut::from($suivant) : null;
    }

    public function postesAutorises(CourrierStatut $statutCourant, bool $necessiteAvisDg, bool $initieParDg = false, bool $sortant = false): array
    {
        $circuit = $this->circuit($necessiteAvisDg, $initieParDg, $sortant);
        $postes = config("courrier.circuit_transitions.{$circuit}.{$statutCourant->value}.postes", []);

        return array_map(fn (string $poste) => Poste::from($poste), $postes);
    }

    private function circuit(bool $necessiteAvisDg, bool $initieParDg, bool $sortant = false): string
    {
        // Un courrier sortant (réponse à un courrier d'arrivée, voir
        // Courrier::sens) suit toujours le même circuit de relecture et de
        // signature, quel que soit necessite_avis_dg/initie_par_dg — ces
        // deux drapeaux n'ont de sens que pour un courrier entrant.
        if ($sortant) {
            return 'sortant';
        }

        if ($initieParDg) {
            return 'dg_initie';
        }

        return $necessiteAvisDg ? 'complet' : 'court';
    }

    public function posteDeCreation(): Poste
    {
        return Poste::from(config('courrier.poste_creation'));
    }
}
