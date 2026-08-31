<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;

class ImpressionFicheCourrierTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_un_agent_peut_imprimer_la_fiche_dun_courrier_meme_en_cours_de_circuit(): void
    {
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::PROTOCOLE, $direction);
        $courrier = Courrier::factory()->create();

        $reponse = $this->actingAs($agent)->get("/api/v1/courriers/{$courrier->id}/imprimer");

        $reponse->assertOk();
        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
    }
}
