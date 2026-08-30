<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;

class ExportStagiairesTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_la_dfp_peut_exporter_la_liste_des_stagiaires_en_csv(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        Stagiaire::factory()->create(['nom' => 'Jean Mukendi', 'statut' => StagiaireStatut::STAGE_EN_COURS]);
        Stagiaire::factory()->create(['nom' => 'Alice Kanku', 'statut' => StagiaireStatut::CLOTURE]);

        $reponse = $this->actingAs($dfp)->get('/api/v1/stagiaires/export');

        $reponse->assertOk();
        $this->assertStringContainsString('text/csv', $reponse->headers->get('Content-Type'));

        $contenu = $reponse->streamedContent();
        $this->assertStringContainsString('Jean Mukendi', $contenu);
        $this->assertStringContainsString('Alice Kanku', $contenu);
    }

    public function test_lexport_respecte_le_filtre_de_statut(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        Stagiaire::factory()->create(['nom' => 'En cours de stage', 'statut' => StagiaireStatut::STAGE_EN_COURS]);
        Stagiaire::factory()->create(['nom' => 'Deja cloture', 'statut' => StagiaireStatut::CLOTURE]);

        $contenu = $this->actingAs($dfp)->get('/api/v1/stagiaires/export?statut=stage_en_cours')->streamedContent();

        $this->assertStringContainsString('En cours de stage', $contenu);
        $this->assertStringNotContainsString('Deja cloture', $contenu);
    }

    public function test_une_direction_daccueil_nexporte_que_ses_propres_stagiaires(): void
    {
        $direction = Direction::factory()->create();
        $autreDirection = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        Stagiaire::factory()->create(['nom' => 'De ma direction', 'direction_id' => $direction->id]);
        Stagiaire::factory()->create(['nom' => 'Autre direction', 'direction_id' => $autreDirection->id]);

        $contenu = $this->actingAs($responsable)->get('/api/v1/stagiaires/export')->streamedContent();

        $this->assertStringContainsString('De ma direction', $contenu);
        $this->assertStringNotContainsString('Autre direction', $contenu);
    }
}
