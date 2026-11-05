<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;

/**
 * Lot 4 : une file de travail ne doit jamais perdre un courrier ancien. Le filtre par statuts
 * s'applique côté serveur, avant la pagination.
 */
class FileDeTravailPaginationTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_un_courrier_en_attente_plus_ancien_que_les_20_derniers_reste_dans_la_file_de_la_dg(): void
    {
        $dg = $this->agent(Poste::DG, Direction::factory()->create());
        $ancien = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'objet' => 'Ancien en attente', 'created_at' => now()->subDays(10)]);
        Courrier::factory()->count(25)->create(['statut' => CourrierStatut::ENREGISTRE]);

        $reponse = $this->actingAs($dg)->getJson('/api/v1/courriers?statuts[]=en_attente_avis_dg&par_page=200')->assertOk();

        $this->assertSame([$ancien->id], array_column($reponse->json('data'), 'id'));
    }

    public function test_par_page_est_plafonne_et_un_statut_inconnu_est_refuse(): void
    {
        $dg = $this->agent(Poste::DG, Direction::factory()->create());
        Courrier::factory()->count(3)->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);

        $this->actingAs($dg)->getJson('/api/v1/courriers?statuts[]=en_attente_avis_dg&par_page=100000')
            ->assertOk()->assertJsonPath('meta.per_page', 200);
        $this->actingAs($dg)->getJson('/api/v1/courriers?statuts[]=inconnu')
            ->assertUnprocessable()->assertJsonValidationErrors('statuts.0');
    }

    public function test_le_filtre_par_statuts_ne_contourne_pas_la_visibilite(): void
    {
        $direction = Direction::factory()->create();
        Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'objet' => 'Réservé à la DG']);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);

        $reponse = $this->actingAs($sec2)->getJson('/api/v1/courriers?statuts[]=en_attente_avis_dg&par_page=200')->assertOk();

        $this->assertSame([], $reponse->json('data'));
    }

    public function test_sans_parametre_la_liste_reste_paginee_par_20(): void
    {
        $dg = $this->agent(Poste::DG, Direction::factory()->create());
        Courrier::factory()->count(25)->create();

        $this->actingAs($dg)->getJson('/api/v1/courriers')->assertOk()->assertJsonPath('meta.per_page', 20)->assertJsonCount(20, 'data');
    }
}
