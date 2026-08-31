<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Models\Stagiaire;

class ArchiveAnnuelleTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_la_dfp_peut_telecharger_larchive_annuelle(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $direction = Direction::factory()->create();
        Stagiaire::factory()->create(['direction_id' => $direction->id, 'cloture_at' => '2026-05-01', 'note_finale' => 70]);

        $reponse = $this->actingAs($dfp)->get('/api/v1/archives/annuelle?annee=2026');

        $reponse->assertOk();
        $this->assertSame('application/zip', $reponse->headers->get('Content-Type'));
    }

    public function test_une_direction_daccueil_ne_peut_pas_telecharger_larchive_annuelle(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        $this->actingAs($responsable)->get('/api/v1/archives/annuelle?annee=2026')->assertStatus(403);
    }
}
