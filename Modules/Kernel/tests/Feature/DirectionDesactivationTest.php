<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class DirectionDesactivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_direction_avec_un_courrier_en_cours_ne_peut_pas_etre_desactivee(): void
    {
        $admin = User::factory()->administrateur()->create();
        $direction = Direction::factory()->create(['actif' => true]);
        Courrier::factory()->create([
            'direction_destination_id' => $direction->id,
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
        ]);

        $this->actingAs($admin)->putJson("/api/v1/directions/{$direction->id}", ['actif' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('actif');

        $this->assertTrue($direction->fresh()->actif);
    }

    public function test_une_direction_sans_courrier_en_cours_peut_etre_desactivee(): void
    {
        $admin = User::factory()->administrateur()->create();
        $direction = Direction::factory()->create(['actif' => true]);
        Courrier::factory()->create([
            'direction_destination_id' => $direction->id,
            'statut' => CourrierStatut::ENREGISTRE,
        ]);

        $this->actingAs($admin)->putJson("/api/v1/directions/{$direction->id}", ['actif' => false])
            ->assertOk();

        $this->assertFalse($direction->fresh()->actif);
    }

    public function test_desactiver_une_direction_deja_desactivee_ne_declenche_pas_le_garde_fou(): void
    {
        $admin = User::factory()->administrateur()->create();
        $direction = Direction::factory()->create(['actif' => false]);
        Courrier::factory()->create([
            'direction_destination_id' => $direction->id,
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
        ]);

        $this->actingAs($admin)->putJson("/api/v1/directions/{$direction->id}", ['nom' => 'Renommée'])
            ->assertOk();
    }
}
