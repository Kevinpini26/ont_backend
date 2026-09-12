<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * Lot C, point 3 : un signataire (DG/DGA) qui reçoit un courrier mal
 * orienté vers l'avis DG doit pouvoir le renvoyer au tri sans que ce soit
 * jamais une faute — le tour ne doit pas bouger, et le geste alimente une
 * statistique de justesse visible du seul Secrétariat 01.
 */
class ReorientationTriTest extends CourrierTestCase
{
    use RefreshDatabase;

    private function courrierChezLaDg(Direction $direction, User $secretariat1): Courrier
    {
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'tour' => 1]);
        $courrier->transitions()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'tour' => 1,
            'changed_by_id' => $secretariat1->id,
            'created_at' => now(),
            'accuse_reception_at' => now(),
        ]);

        return $courrier;
    }

    public function test_la_dg_renvoie_un_courrier_mal_oriente_au_tri_sans_incrementer_le_tour(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);

        $courrier = $this->courrierChezLaDg($direction, $secretariat1);

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/renvoyer-au-tri", ['motif' => "N'aurait jamais dû m'être présenté"])
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::EN_ATTENTE_TRI->value);

        $courrier->refresh();
        $this->assertSame(1, $courrier->tour);

        $this->assertDatabaseHas('reorientations_tri', [
            'courrier_id' => $courrier->id,
            'trie_par_id' => $secretariat1->id,
            'reoriente_par_id' => $dg->id,
        ]);
    }

    public function test_seule_la_dg_ou_la_dga_en_interim_peut_reorienter_au_tri(): void
    {
        $direction = Direction::factory()->create();
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $courrier = $this->courrierChezLaDg($direction, $secretariat1);

        $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$courrier->id}/renvoyer-au-tri", [])
            ->assertStatus(403);
    }

    public function test_la_justesse_du_tri_nest_visible_que_du_secretariat_01(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $direction);

        $courrier = $this->courrierChezLaDg($direction, $secretariat1);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/renvoyer-au-tri", ['motif' => 'test'])->assertOk();

        $this->actingAs($secretariat2)->getJson('/api/v1/courriers/justesse-tri')->assertStatus(403);

        $reponse = $this->actingAs($secretariat1)
            ->getJson('/api/v1/courriers/justesse-tri')
            ->assertOk();

        $this->assertSame(1, $reponse->json('global.nombre_tries'));
        $this->assertSame(1, $reponse->json('global.nombre_reoriented'));
        $this->assertEquals(0, $reponse->json('global.taux_justesse'));
    }
}
