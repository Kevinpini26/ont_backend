<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Models\Stagiaire;

class RapportAnnuelTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_la_dfp_peut_telecharger_le_rapport_annuel(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $direction = Direction::factory()->create();
        Stagiaire::factory()->create([
            'direction_id' => $direction->id,
            'cloture_at' => '2026-06-15',
            'note_finale' => 78.5,
        ]);

        $reponse = $this->actingAs($dfp)->get('/api/v1/stagiaires/rapport-annuel?annee=2026');

        $reponse->assertOk();
        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
    }

    public function test_une_direction_daccueil_ne_peut_pas_telecharger_le_rapport_annuel(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        $this->actingAs($responsable)->get('/api/v1/stagiaires/rapport-annuel?annee=2026')->assertStatus(403);
    }

    public function test_lannee_est_obligatoire(): void
    {
        $dfp = User::factory()->agentDfp()->create();

        $this->actingAs($dfp)->getJson('/api/v1/stagiaires/rapport-annuel')->assertStatus(422);
    }
}
