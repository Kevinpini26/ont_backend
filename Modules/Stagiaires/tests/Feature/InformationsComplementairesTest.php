<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Models\EtablissementFormation;
use Modules\Stagiaires\Models\Stagiaire;

class InformationsComplementairesTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_la_dfp_peut_lier_un_etablissement_du_referentiel_et_un_maitre_de_stage_reel(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $etablissement = EtablissementFormation::factory()->create();
        $stagiaire = Stagiaire::factory()->create(['direction_id' => $direction->id]);

        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/informations-complementaires", [
                'etablissement_id' => $etablissement->id,
                'maitre_stage_id' => $responsable->id,
                'session_promotion' => '2026-2027',
            ])
            ->assertOk()
            ->assertJsonPath('data.etablissement_id', $etablissement->id)
            ->assertJsonPath('data.maitre_stage_id', $responsable->id)
            ->assertJsonPath('data.session_promotion', '2026-2027');

        $this->assertSame($etablissement->id, $stagiaire->fresh()->etablissement_id);
        $this->assertSame($responsable->id, $stagiaire->fresh()->maitre_stage_id);
    }

    public function test_la_dfp_peut_definir_les_informations_complementaires(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create();

        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/informations-complementaires", [
                'lieu_naissance' => 'Kinshasa',
                'filiere_formation' => 'Informatique de gestion',
                'niveau_formation' => 'Licence',
                'maitre_stage' => 'Mme Kalala',
                'conseiller_stage' => 'M. Mbuyi',
            ])
            ->assertOk()
            ->assertJsonPath('data.lieu_naissance', 'Kinshasa')
            ->assertJsonPath('data.filiere_formation', 'Informatique de gestion')
            ->assertJsonPath('data.niveau_formation', 'Licence')
            ->assertJsonPath('data.maitre_stage', 'Mme Kalala')
            ->assertJsonPath('data.conseiller_stage', 'M. Mbuyi');

        $this->assertDatabaseHas('stagiaires', [
            'id' => $stagiaire->id,
            'lieu_naissance' => 'Kinshasa',
            'maitre_stage' => 'Mme Kalala',
        ]);
    }

    public function test_le_responsable_de_direction_ne_peut_pas_definir_les_informations_complementaires(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $stagiaire = Stagiaire::factory()->create(['direction_id' => $direction->id]);

        $this->actingAs($responsable)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/informations-complementaires", [
                'lieu_naissance' => 'Kinshasa',
            ])
            ->assertStatus(403);
    }
}
