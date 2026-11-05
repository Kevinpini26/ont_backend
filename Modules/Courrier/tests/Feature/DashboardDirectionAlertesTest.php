<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierTransition;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class DashboardDirectionAlertesTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_les_courriers_recus_non_traites_depuis_plus_de_48h_sont_comptes(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        $ancien = Courrier::factory()->create(['direction_destination_id' => $direction->id, 'statut' => CourrierStatut::DISPATCH_EXECUTE]);
        CourrierTransition::query()->create(['courrier_id' => $ancien->id, 'statut' => CourrierStatut::DISPATCH_EXECUTE, 'created_at' => now()->subHours(72)]);

        $recent = Courrier::factory()->create(['direction_destination_id' => $direction->id, 'statut' => CourrierStatut::DISPATCH_EXECUTE]);
        CourrierTransition::query()->create(['courrier_id' => $recent->id, 'statut' => CourrierStatut::DISPATCH_EXECUTE, 'created_at' => now()->subHours(2)]);

        $response = $this->actingAs($responsable)
            ->getJson('/api/v1/courriers/statistiques-direction')
            ->assertOk();

        $this->assertSame(1, $response->json('courriers_recus_non_traites_48h'));
    }

    public function test_la_tendance_des_candidatures_est_reservee_a_la_dfp(): void
    {
        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create(['direction_id' => $direction->id]);

        Courrier::factory()->create(['type' => CourrierType::DEMANDE_STAGE, 'created_at' => now()]);

        $response = $this->actingAs($dfp)
            ->getJson('/api/v1/courriers/statistiques-direction')
            ->assertOk();

        $this->assertNotNull($response->json('tendance_candidatures_12_mois'));
    }

    public function test_la_tendance_des_candidatures_est_absente_pour_un_responsable_de_direction(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        $response = $this->actingAs($responsable)
            ->getJson('/api/v1/courriers/statistiques-direction')
            ->assertOk();

        $this->assertNull($response->json('tendance_candidatures_12_mois'));
    }
}
