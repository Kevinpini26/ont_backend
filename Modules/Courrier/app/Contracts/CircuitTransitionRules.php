<?php

namespace Modules\Courrier\Contracts;

use Modules\Courrier\Enums\CourrierStatut;
use Modules\Kernel\Enums\Poste;

/**
 * Point d'extension du circuit courrier : détermine le statut suivant
 * autorisé et le(s) poste(s) habilité(s) à déclencher chaque transition.
 * Une implémentation alternative peut être bindée dans le conteneur pour
 * faire évoluer le circuit sans toucher aux contrôleurs.
 */
interface CircuitTransitionRules
{
    public function statutSuivant(CourrierStatut $statut, bool $necessiteAvisDg, bool $initieParDg = false, bool $sortant = false): ?CourrierStatut;

    /**
     * @return Poste[]
     */
    public function postesAutorises(CourrierStatut $statutCourant, bool $necessiteAvisDg, bool $initieParDg = false, bool $sortant = false): array;

    public function posteDeCreation(): Poste;
}
