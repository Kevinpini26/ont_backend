<?php

namespace Modules\Stagiaires\Tests\Feature;

use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Models\Direction;
use Tests\TestCase;

abstract class StagiaireTestCase extends TestCase
{
    /**
     * La DFP n'est jamais rattachée à une direction (voir
     * UserRole::rolesRequiringDirection()) — TableauRepartitionCircuitService
     * résout "la direction qui établit" par ce code de convention (voir
     * config('stagiaires.direction_dfp_code')), jamais présent par défaut
     * dans une base de test fraîchement migrée.
     */
    protected function directionDfp(): Direction
    {
        return Direction::factory()->create(['code' => config('stagiaires.direction_dfp_code')]);
    }

    /**
     * Un courrier créé directement via factory à un statut intermédiaire
     * (raccourci de test, sans passer par le circuit réel) n'a aucun
     * bordereau : sans cet appel, il apparaît "en transit" indéfiniment et
     * bloque toute action — voir Courrier::enTransit().
     */
    protected function marquerDecharge(Courrier $courrier): void
    {
        $courrier->transitions()->create([
            'statut' => $courrier->statut,
            'accuse_reception_at' => now(),
            'created_at' => now(),
        ]);
    }
}
