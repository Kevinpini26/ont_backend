<?php

namespace Modules\Courrier\Support;

use LogicException;
use Modules\Courrier\Contracts\CircuitTransitionRules;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Kernel\Enums\Poste;

class ConfigCircuitTransitionRules implements CircuitTransitionRules
{
    public function statutSuivant(CourrierStatut $statut, bool $necessiteAvisDg, bool $initieParDg = false, bool $sortant = false, array $contexte = []): ?CourrierStatut
    {
        foreach ($this->transitionsCandidates($statut, $necessiteAvisDg, $initieParDg, $sortant) as $transition) {
            if ($this->conditionSatisfaite($transition['condition'] ?? null, $contexte)) {
                return CourrierStatut::from($transition['statut_arrivee']);
            }
        }

        return null;
    }

    public function postesAutorises(CourrierStatut $statutCourant, bool $necessiteAvisDg, bool $initieParDg = false, bool $sortant = false): array
    {
        $postes = [];
        foreach ($this->transitionsCandidates($statutCourant, $necessiteAvisDg, $initieParDg, $sortant) as $transition) {
            foreach ($transition['postes'] as $poste) {
                $postes[$poste] = true;
            }
        }

        return array_map(fn (string $poste) => Poste::from($poste), array_keys($postes));
    }

    public function postesPourTransitionResolue(CourrierStatut $statutCourant, bool $necessiteAvisDg, bool $initieParDg = false, bool $sortant = false, array $contexte = []): array
    {
        foreach ($this->transitionsCandidates($statutCourant, $necessiteAvisDg, $initieParDg, $sortant) as $transition) {
            if ($this->conditionSatisfaite($transition['condition'] ?? null, $contexte)) {
                return array_map(fn (string $poste) => Poste::from($poste), $transition['postes']);
            }
        }

        // Repli : contexte insuffisant pour départager (voir docblock de
        // l'interface) — l'union reste correcte dans ce cas.
        return $this->postesAutorises($statutCourant, $necessiteAvisDg, $initieParDg, $sortant);
    }

    /**
     * @return array<int, array{action: string, statut_arrivee: string, postes: string[], condition: ?string}>
     */
    private function transitionsCandidates(CourrierStatut $statut, bool $necessiteAvisDg, bool $initieParDg, bool $sortant): array
    {
        $circuit = $this->circuit($necessiteAvisDg, $initieParDg, $sortant);

        return config("courrier.circuit_transitions.{$circuit}.{$statut->value}", []);
    }

    /**
     * @param  array<string, mixed>  $contexte
     */
    private function conditionSatisfaite(?string $condition, array $contexte): bool
    {
        return match ($condition) {
            null => true,
            // Jamais vraie tant que 'categories_protocole' est vide — voir
            // docs/questions-ont.md.
            'protocole_requis' => in_array($contexte['type'] ?? null, config('courrier.categories_protocole', []), true),
            'avis_dg_reserve' => ($contexte['avis_dg'] ?? null) === AvisDg::RESERVE->value,
            'avis_dg_tranche' => isset($contexte['avis_dg']) && $contexte['avis_dg'] !== AvisDg::RESERVE->value,
            // Lot 3 : un avis favorable sur un courrier déjà imputé (voir
            // Courrier::imputations) part en dispatch plutôt qu'en rédaction
            // interne — vérifiée avant 'avis_dg_tranche' dans la table de
            // transitions, qui matcherait aussi un avis favorable.
            'avis_dg_favorable_impute' => ($contexte['avis_dg'] ?? null) === AvisDg::FAVORABLE->value
                && ($contexte['courrier_impute'] ?? false) === true,
            default => throw new LogicException("Condition de circuit inconnue : {$condition}"),
        };
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
