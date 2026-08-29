<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;

/**
 * Deux mécanismes distincts selon le type de stage : modification directe
 * des dates (académique, sans historique) vs prolongation tracée
 * (professionnel, voir stagiaire_prolongations).
 */
class DureeStageTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_le_stage_professionnel_recoit_une_duree_initiale_de_trois_mois(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $direction = Direction::factory()->create(['actif' => true]);
        $stagiaire = Stagiaire::factory()->professionnel()->create([
            'statut' => StagiaireStatut::AFFECTE,
            'direction_id' => $direction->id,
        ]);

        $dateDebut = now()->addDays(5)->startOfDay();

        // Une date_fin_stage envoyée par le client est ignorée pour un
        // stage professionnel : seule la durée fixe de 3 mois s'applique.
        $response = $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/valider-arrivee", [
                'date_debut_stage' => $dateDebut->toDateString(),
                'date_fin_stage' => $dateDebut->copy()->addDays(10)->toDateString(),
            ])
            ->assertOk();

        $this->assertSame($dateDebut->copy()->addMonths(3)->toDateString(), $response->json('data.date_fin_stage'));
    }

    public function test_le_stage_academique_garde_la_date_de_fin_choisie_par_la_dfp(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $direction = Direction::factory()->create(['actif' => true]);
        $stagiaire = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::AFFECTE,
            'direction_id' => $direction->id,
        ]);

        $response = $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/valider-arrivee", [
                'date_debut_stage' => now()->addDays(5)->toDateString(),
                'date_fin_stage' => now()->addDays(65)->toDateString(),
            ])
            ->assertOk();

        $this->assertSame(now()->addDays(65)->toDateString(), $response->json('data.date_fin_stage'));
    }

    public function test_le_stage_academique_sans_date_de_fin_est_rejete(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::AFFECTE]);

        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/valider-arrivee", [
                'date_debut_stage' => now()->addDays(5)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_fin_stage']);
    }

    public function test_la_dfp_peut_prolonger_un_stage_professionnel_en_cours(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->professionnel()->create([
            'statut' => StagiaireStatut::STAGE_EN_COURS,
            'date_debut_stage' => now()->subMonth(),
            'date_fin_stage' => now()->addDays(10),
            'alerte_echeance_envoyee_at' => now(),
        ]);

        $nouvelleDateFin = now()->addDays(40)->toDateString();

        $response = $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/prolonger", [
                'nouvelle_date_fin' => $nouvelleDateFin,
                'motif' => 'Charge de travail supplémentaire confiée par la direction.',
            ])
            ->assertOk();

        $this->assertSame($nouvelleDateFin, $response->json('data.date_fin_stage'));
        $this->assertCount(1, $response->json('data.prolongations'));
        $this->assertSame($nouvelleDateFin, $response->json('data.prolongations.0.nouvelle_date_fin'));

        $stagiaire->refresh();
        $this->assertNull($stagiaire->alerte_echeance_envoyee_at);
        $this->assertDatabaseHas('stagiaire_prolongations', [
            'stagiaire_id' => $stagiaire->id,
            'prolonge_par_id' => $dfp->id,
        ]);
    }

    public function test_deux_prolongations_successives_sont_conservees_dans_lordre(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->professionnel()->create([
            'statut' => StagiaireStatut::STAGE_EN_COURS,
            'date_debut_stage' => now()->subMonth(),
            'date_fin_stage' => now()->addDays(10),
        ]);

        // Fige l'horloge pour forcer les deux prolongations à partager le
        // même created_at (précision seconde) : c'est exactement le cas
        // qui rendait l'ancien tri ->latest() (created_at seul) ambigu.
        Carbon::setTestNow(now());

        $this->actingAs($dfp)->postJson("/api/v1/stagiaires/{$stagiaire->id}/prolonger", [
            'nouvelle_date_fin' => now()->addDays(20)->toDateString(),
            'motif' => 'Premiere prolongation.',
        ])->assertOk();

        $this->actingAs($dfp)->postJson("/api/v1/stagiaires/{$stagiaire->id}/prolonger", [
            'nouvelle_date_fin' => now()->addDays(40)->toDateString(),
            'motif' => 'Deuxieme prolongation.',
        ])->assertOk();

        Carbon::setTestNow();

        $prolongations = $stagiaire->fresh()->prolongations;

        $this->assertCount(2, $prolongations);
        $this->assertSame('Deuxieme prolongation.', $prolongations[0]->motif);
        $this->assertSame('Premiere prolongation.', $prolongations[1]->motif);
    }

    public function test_une_prolongation_qui_ne_repousse_pas_lecheance_est_rejetee(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->professionnel()->create([
            'statut' => StagiaireStatut::STAGE_EN_COURS,
            'date_debut_stage' => now()->subMonth(),
            'date_fin_stage' => now()->addDays(10),
        ]);

        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/prolonger", [
                'nouvelle_date_fin' => now()->addDays(5)->toDateString(),
                'motif' => 'Test.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nouvelle_date_fin']);
    }

    public function test_on_ne_peut_pas_prolonger_un_stage_academique(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::STAGE_EN_COURS,
            'date_debut_stage' => now()->subMonth(),
            'date_fin_stage' => now()->addDays(10),
        ]);

        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/prolonger", [
                'nouvelle_date_fin' => now()->addDays(40)->toDateString(),
                'motif' => 'Test.',
            ])
            ->assertStatus(422);
    }

    public function test_le_responsable_de_direction_ne_peut_pas_prolonger(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $stagiaire = Stagiaire::factory()->professionnel()->create([
            'statut' => StagiaireStatut::STAGE_EN_COURS,
            'direction_id' => $direction->id,
            'date_debut_stage' => now()->subMonth(),
            'date_fin_stage' => now()->addDays(10),
        ]);

        $this->actingAs($responsable)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/prolonger", [
                'nouvelle_date_fin' => now()->addDays(40)->toDateString(),
                'motif' => 'Test.',
            ])
            ->assertStatus(403);
    }

    public function test_la_dfp_peut_modifier_directement_les_dates_dun_stage_academique(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::STAGE_EN_COURS,
            'date_debut_stage' => now()->subMonth(),
            'date_fin_stage' => now()->addDays(10),
            'alerte_echeance_envoyee_at' => now(),
        ]);

        $nouvelleDateDebut = now()->subWeeks(2)->toDateString();
        $nouvelleDateFin = now()->addDays(30)->toDateString();

        $response = $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/modifier-dates", [
                'date_debut_stage' => $nouvelleDateDebut,
                'date_fin_stage' => $nouvelleDateFin,
            ])
            ->assertOk();

        $this->assertSame($nouvelleDateDebut, $response->json('data.date_debut_stage'));
        $this->assertSame($nouvelleDateFin, $response->json('data.date_fin_stage'));
        $this->assertSame([], $response->json('data.prolongations'));

        $this->assertDatabaseCount('stagiaire_prolongations', 0);
        $this->assertNull($stagiaire->fresh()->alerte_echeance_envoyee_at);
    }

    public function test_on_ne_peut_pas_modifier_directement_les_dates_dun_stage_professionnel(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->professionnel()->create([
            'statut' => StagiaireStatut::STAGE_EN_COURS,
            'date_debut_stage' => now()->subMonth(),
            'date_fin_stage' => now()->addDays(10),
        ]);

        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/modifier-dates", [
                'date_debut_stage' => now()->subWeeks(2)->toDateString(),
                'date_fin_stage' => now()->addDays(30)->toDateString(),
            ])
            ->assertStatus(422);
    }

    public function test_le_responsable_de_direction_ne_peut_pas_modifier_les_dates(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $stagiaire = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::STAGE_EN_COURS,
            'direction_id' => $direction->id,
            'date_debut_stage' => now()->subMonth(),
            'date_fin_stage' => now()->addDays(10),
        ]);

        $this->actingAs($responsable)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/modifier-dates", [
                'date_debut_stage' => now()->subWeeks(2)->toDateString(),
                'date_fin_stage' => now()->addDays(30)->toDateString(),
            ])
            ->assertStatus(403);
    }
}
