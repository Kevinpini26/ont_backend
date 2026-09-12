<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;

/**
 * Lot 5 : le contrôle de quota se fait désormais à l'approbation du
 * tableau de répartition (voir StagiaireCircuitService::affecter(),
 * appelée depuis TableauRepartitionCircuitService::rendreAvis()), plus à
 * l'ancienne action directe "/affecter" (retirée). La dérogation "hors
 * quota" (forcer/justification) disparaît avec elle : une approbation sur
 * une direction saturée échoue "en bloc", sans contournement — voir
 * docs/questions-ont.md si un besoin réel de dérogation via tableau se
 * confirme un jour.
 */
class QuotaAffectationTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_lapprobation_est_bloquee_quand_la_capacite_maximale_est_atteinte(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $direction = Direction::factory()->create(['actif' => true, 'capacite_max' => 1]);

        Stagiaire::factory()->create([
            'statut' => StagiaireStatut::STAGE_EN_COURS,
            'direction_id' => $direction->id,
        ]);

        $candidat = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);

        $response = $this->affecterViaTableau($candidat, $direction, $dfp);
        $response->assertStatus(422);
        $response->assertJson(['quota_atteint' => true]);

        // Toujours "en instruction" : la proposition (voir ajouterLigne())
        // avait déjà eu lieu avant l'échec de l'approbation, aucune
        // affectation réelle n'a eu lieu.
        $this->assertSame(StagiaireStatut::EN_INSTRUCTION, $candidat->fresh()->statut);
    }

    public function test_aucune_limite_de_quota_quand_capacite_max_est_nulle(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $direction = Direction::factory()->create(['actif' => true, 'capacite_max' => null]);

        Stagiaire::factory()->count(5)->create([
            'statut' => StagiaireStatut::STAGE_EN_COURS,
            'direction_id' => $direction->id,
        ]);

        $candidat = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);

        $this->affecterViaTableau($candidat, $direction, $dfp)->assertOk();

        $this->assertSame(StagiaireStatut::AFFECTE, $candidat->fresh()->statut);
        $this->assertFalse($candidat->fresh()->affecte_hors_quota);
    }
}
