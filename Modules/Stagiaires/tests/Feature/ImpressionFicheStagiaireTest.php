<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Models\Stagiaire;

class ImpressionFicheStagiaireTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_la_dfp_peut_imprimer_la_fiche_dun_stagiaire(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create();

        $reponse = $this->actingAs($dfp)->get("/api/v1/stagiaires/{$stagiaire->id}/imprimer");

        $reponse->assertOk();
        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
    }
}
