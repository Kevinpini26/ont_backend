<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;

class StatistiquesDgDecisionTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_en_attente_de_decision_compte_les_avis_a_rendre_et_les_projets_prets_a_signer_seulement(): void
    {
        $dg = $this->agent(Poste::DG, Direction::factory()->create());
        Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        Courrier::factory()->create(['statut' => CourrierStatut::PROJET_A_VALIDER, 'relecture_validee_at' => now()]);
        // encore chez le relecteur : rien à faire pour la DG
        Courrier::factory()->create(['statut' => CourrierStatut::PROJET_A_VALIDER, 'relecture_validee_at' => null]);

        $this->actingAs($dg)->getJson('/api/v1/courriers/statistiques-dg')
            ->assertOk()
            ->assertJsonPath('en_attente_decision', 2)
            ->assertJsonPath('a_signer', 1)
            ->assertJsonPath('en_preparation_relecture', 1);
    }
}
