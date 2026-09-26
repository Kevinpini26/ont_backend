<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;

class ExportCourriersTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_un_agent_peut_exporter_les_courriers_visibles_en_csv(): void
    {
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::RECEPTION, $direction);
        Courrier::factory()->create(['objet' => 'Objet visible dans l\'export']);

        $reponse = $this->actingAs($agent)->get('/api/v1/courriers/export');

        $reponse->assertOk();
        $this->assertStringContainsString('text/csv', $reponse->headers->get('Content-Type'));
        $this->assertStringContainsString("Objet visible dans l'export", $reponse->streamedContent());
    }

    public function test_lexport_respecte_le_filtre_de_statut(): void
    {
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::RECEPTION, $direction);
        Courrier::factory()->create(['objet' => 'Courrier recu', 'statut' => CourrierStatut::RECU]);
        Courrier::factory()->create(['objet' => 'Courrier enregistre', 'statut' => CourrierStatut::ENREGISTRE]);

        $contenu = $this->actingAs($agent)->get('/api/v1/courriers/export?statut=recu')->streamedContent();

        $this->assertStringContainsString('Courrier recu', $contenu);
        $this->assertStringNotContainsString('Courrier enregistre', $contenu);
    }
}
