<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;

class StagiaireStatistiqueTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_la_dfp_voit_les_statistiques_agregees_toutes_directions(): void
    {
        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();

        Stagiaire::factory()->count(2)->create([
            'statut' => StagiaireStatut::STAGE_EN_COURS,
            'direction_id' => $direction->id,
            'date_fin_stage' => now()->addDays(5),
        ]);
        Stagiaire::factory()->create(['statut' => StagiaireStatut::DOSSIER_RECU]);

        $response = $this->actingAs($dfp)
            ->getJson('/api/v1/stagiaires/statistiques')
            ->assertOk();

        $response->assertJson([
            'stagiaires_actifs' => 2,
            'total_dossiers' => 3,
            'echeance_10_jours' => 2,
            'stagiaires_affectes' => 2,
            'en_attente_affectation' => 0,
        ]);

        $parDirection = collect($response->json('par_direction'));
        $this->assertSame(2, $parDirection->firstWhere('direction_id', $direction->id)['total']);
    }

    public function test_le_compteur_de_dossiers_en_attente_daffectation_est_global(): void
    {
        $dfp = User::factory()->agentDfp()->create();

        Stagiaire::factory()->count(3)->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);

        $response = $this->actingAs($dfp)
            ->getJson('/api/v1/stagiaires/statistiques')
            ->assertOk();

        $this->assertSame(3, $response->json('en_attente_affectation'));
    }

    /**
     * Une direction d'accueil a accès au même endpoint que la DFP (pour son
     * propre tableau de bord), mais le Global Scope de Stagiaire restreint
     * automatiquement les agrégats à sa seule direction — jamais les
     * effectifs des autres directions.
     */
    public function test_une_direction_ne_voit_que_ses_propres_statistiques(): void
    {
        $direction = Direction::factory()->create();
        $autreDirection = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        Stagiaire::factory()->count(2)->create(['statut' => StagiaireStatut::STAGE_EN_COURS, 'direction_id' => $direction->id]);
        Stagiaire::factory()->count(3)->create(['statut' => StagiaireStatut::STAGE_EN_COURS, 'direction_id' => $autreDirection->id]);

        $response = $this->actingAs($responsable)
            ->getJson('/api/v1/stagiaires/statistiques')
            ->assertOk();

        $this->assertSame(2, $response->json('stagiaires_actifs'));
    }

    public function test_le_tableau_de_bord_expose_la_variation_et_levolution_sur_la_periode(): void
    {
        $dfp = User::factory()->agentDfp()->create();

        Stagiaire::factory()->count(3)->create(['created_at' => now()]);
        Stagiaire::factory()->count(1)->create(['created_at' => now()->subDays(45)]);

        $response = $this->actingAs($dfp)
            ->getJson('/api/v1/stagiaires/statistiques?periode=30j')
            ->assertOk();

        $response->assertJsonStructure([
            'dossiers_recus_periode', 'dossiers_recus_variation',
            'stages_clotures_periode', 'stages_clotures_variation',
            'periode' => ['cle', 'depuis', 'jusqua'],
            'evolution',
        ]);
        $this->assertSame('30j', $response->json('periode.cle'));
        $this->assertGreaterThanOrEqual(3, $response->json('dossiers_recus_periode'));
    }
}
