<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;

class MatriculeStagiaireTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_un_matricule_est_attribue_a_laffectation(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $direction = Direction::factory()->create(['actif' => true]);
        $candidat = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);

        $this->affecterViaTableau($candidat, $direction, $dfp)->assertOk();

        $matricule = $candidat->fresh()->matricule;
        $this->assertNotNull($matricule);
        $this->assertStringContainsString('-STG-', $matricule);
    }

    public function test_les_matricules_sont_sequentiels_et_uniques(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $direction = Direction::factory()->create(['actif' => true]);

        $premier = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        $this->affecterViaTableau($premier, $direction, $dfp)->assertOk();

        $second = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        $this->affecterViaTableau($second, $direction, $dfp)->assertOk();

        $this->assertNotSame($premier->fresh()->matricule, $second->fresh()->matricule);
    }

    public function test_un_stagiaire_pas_encore_affecte_na_pas_de_matricule(): void
    {
        $candidat = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);

        $this->assertNull($candidat->matricule);
    }
}
