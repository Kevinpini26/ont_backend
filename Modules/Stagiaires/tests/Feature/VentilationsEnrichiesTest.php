<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Enums\StagiaireTypeStage;
use Modules\Stagiaires\Models\EtablissementFormation;
use Modules\Stagiaires\Models\Stagiaire;

class VentilationsEnrichiesTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_le_tableau_de_bord_ventile_par_type_de_stage(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        Stagiaire::factory()->count(2)->create(['type_stage' => StagiaireTypeStage::ACADEMIQUE, 'statut' => StagiaireStatut::STAGE_EN_COURS]);
        Stagiaire::factory()->create(['type_stage' => StagiaireTypeStage::PROFESSIONNEL, 'statut' => StagiaireStatut::STAGE_EN_COURS]);

        $reponse = $this->actingAs($dfp)->getJson('/api/v1/stagiaires/statistiques')->assertOk();

        $parType = collect($reponse->json('par_type_stage'))->keyBy('type_stage');
        $this->assertSame(2, $parType[StagiaireTypeStage::ACADEMIQUE->value]['total']);
        $this->assertSame(1, $parType[StagiaireTypeStage::PROFESSIONNEL->value]['total']);
    }

    public function test_le_tableau_de_bord_ventile_par_etablissement_du_referentiel(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $etablissement = EtablissementFormation::factory()->create(['nom' => 'Université Officielle de Kinshasa']);
        Stagiaire::factory()->count(3)->create(['etablissement_id' => $etablissement->id]);
        Stagiaire::factory()->create();

        $reponse = $this->actingAs($dfp)->getJson('/api/v1/stagiaires/statistiques')->assertOk();

        $parEtablissement = collect($reponse->json('par_etablissement'))->firstWhere('etablissement_id', $etablissement->id);
        $this->assertSame(3, $parEtablissement['total']);
    }
}
