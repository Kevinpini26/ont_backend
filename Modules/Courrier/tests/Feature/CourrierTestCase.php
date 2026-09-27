<?php

namespace Modules\Courrier\Tests\Feature;

use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Tests\TestCase;

abstract class CourrierTestCase extends TestCase
{
    protected function agent(Poste $poste, Direction $direction): User
    {
        return User::factory()->agentCircuitCourrier($poste, $direction)->create();
    }

    /**
     * Un courrier créé directement via factory à un statut intermédiaire
     * (raccourci de test, sans passer par le circuit réel) n'a aucun
     * bordereau : sans cet appel, il apparaît "en transit" indéfiniment et
     * bloque toute action — voir Courrier::enTransit(). N'a d'utilité que
     * pour ces raccourcis ; le circuit réel crée déjà des bordereaux
     * acquittés au fil des accusés de réception.
     */
    protected function marquerDecharge(Courrier $courrier, ?Poste $destinataire = null): void
    {
        $courrier->transitions()->create([
            'statut' => $courrier->statut,
            'destinataire_poste' => $destinataire?->value,
            'accuse_reception_at' => now(),
            'created_at' => now(),
        ]);
    }
}
