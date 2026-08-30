<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;

class SuiviPeriodiqueStagiaireTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_la_direction_daccueil_peut_ajouter_une_fiche_de_suivi(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $stagiaire = Stagiaire::factory()->create([
            'direction_id' => $direction->id,
            'statut' => StagiaireStatut::STAGE_EN_COURS,
        ]);

        $response = $this->actingAs($responsable)->postJson("/api/v1/stagiaires/{$stagiaire->id}/suivis", [
            'date_suivi' => now()->toDateString(),
            'observations' => 'Bonne intégration, autonomie croissante sur les tâches confiées.',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('stagiaire_suivis', [
            'stagiaire_id' => $stagiaire->id,
            'redige_par_id' => $responsable->id,
        ]);
    }

    public function test_le_maitre_de_stage_lie_peut_ajouter_une_fiche_de_suivi(): void
    {
        $direction = Direction::factory()->create();
        $maitreStage = User::factory()->responsableDirection($direction)->create();
        $stagiaire = Stagiaire::factory()->create([
            'direction_id' => $direction->id,
            'maitre_stage_id' => $maitreStage->id,
            'statut' => StagiaireStatut::STAGE_EN_COURS,
        ]);

        $this->actingAs($maitreStage)->postJson("/api/v1/stagiaires/{$stagiaire->id}/suivis", [
            'date_suivi' => now()->toDateString(),
            'observations' => 'Point hebdomadaire réalisé.',
            'difficultes_signalees' => 'Retard ponctuel un jour, sans conséquence.',
        ])->assertCreated();
    }

    public function test_une_direction_non_concernee_ne_peut_pas_ajouter_de_fiche_de_suivi(): void
    {
        $direction = Direction::factory()->create();
        $autreDirection = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($autreDirection)->create();
        $stagiaire = Stagiaire::factory()->create(['direction_id' => $direction->id]);

        // 404, pas 403 : StagiairePolicy::view() (et le Global Scope
        // Eloquent de Stagiaire) rend ce dossier invisible pour une
        // direction qui n'est pas la sienne, avant même que gererSuivi()
        // n'ait à se prononcer.
        $this->actingAs($responsable)->postJson("/api/v1/stagiaires/{$stagiaire->id}/suivis", [
            'date_suivi' => now()->toDateString(),
            'observations' => 'Ne devrait jamais être enregistré.',
        ])->assertNotFound();
    }

    public function test_la_liste_des_suivis_est_triee_du_plus_recent_au_plus_ancien(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $stagiaire = Stagiaire::factory()->create(['direction_id' => $direction->id]);

        $this->actingAs($responsable)->postJson("/api/v1/stagiaires/{$stagiaire->id}/suivis", [
            'date_suivi' => now()->subWeeks(2)->toDateString(),
            'observations' => 'Premier point.',
        ])->assertCreated();
        $this->actingAs($responsable)->postJson("/api/v1/stagiaires/{$stagiaire->id}/suivis", [
            'date_suivi' => now()->toDateString(),
            'observations' => 'Second point, plus récent.',
        ])->assertCreated();

        $reponse = $this->actingAs($responsable)->getJson("/api/v1/stagiaires/{$stagiaire->id}/suivis")->assertOk();

        $reponse->assertJsonPath('data.0.observations', 'Second point, plus récent.');
    }
}
