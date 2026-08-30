<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;

class BadgeStagiaireTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_le_badge_est_telechargeable_une_fois_le_stagiaire_affecte(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $direction = Direction::factory()->create(['actif' => true]);
        $candidat = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);

        $this->actingAs($dfp)->postJson("/api/v1/stagiaires/{$candidat->id}/affecter", ['direction_id' => $direction->id])->assertOk();

        $reponse = $this->actingAs($dfp)->get("/api/v1/stagiaires/{$candidat->id}/badge")->assertOk();

        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
    }

    public function test_le_badge_est_refuse_avant_affectation(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $candidat = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);

        $this->actingAs($dfp)->get("/api/v1/stagiaires/{$candidat->id}/badge")->assertStatus(422);
    }
}
