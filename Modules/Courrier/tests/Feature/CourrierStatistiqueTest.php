<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class CourrierStatistiqueTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_le_tableau_de_bord_expose_la_repartition_par_statut_et_le_temps_de_traitement(): void
    {
        Storage::fake('local');

        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);

        $id = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Test statistiques',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'direction_destination_id' => $direction->id,
            'piece_jointe' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ])->json('data.id');

        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();

        $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$id}/transmettre-tri")
            ->assertOk();

        $response = $this->actingAs($secretariat1)
            ->getJson('/api/v1/courriers/statistiques')
            ->assertOk();

        $response->assertJsonStructure([
            'par_statut',
            'en_cours_total',
            'en_attente_relecture',
            'temps_moyen_par_etape',
        ]);

        $parStatut = collect($response->json('par_statut'))->keyBy('statut');
        $this->assertSame(1, $parStatut['en_attente_tri']['total']);
    }

    public function test_une_direction_ne_peut_pas_consulter_le_tableau_de_bord_du_circuit(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        $this->actingAs($responsable)
            ->getJson('/api/v1/courriers/statistiques')
            ->assertStatus(403);
    }

    public function test_le_tableau_de_bord_expose_la_variation_et_levolution_sur_la_periode(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        Courrier::factory()->count(3)->create(['created_at' => now()]);
        Courrier::factory()->count(2)->create(['created_at' => now()->subDays(45)]);

        $response = $this->actingAs($reception)
            ->getJson('/api/v1/courriers/statistiques?periode=30j')
            ->assertOk();

        $response->assertJsonStructure(['courriers_recus_periode', 'courriers_recus_variation', 'periode' => ['cle', 'depuis', 'jusqua'], 'evolution']);
        $this->assertSame('30j', $response->json('periode.cle'));
        $this->assertGreaterThanOrEqual(3, $response->json('courriers_recus_periode'));
    }

    public function test_le_tableau_de_bord_dune_direction_daccueil_ne_montre_que_ses_propres_courriers(): void
    {
        $direction = Direction::factory()->create();
        $autreDirection = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        Courrier::factory()->create(['direction_destination_id' => $direction->id, 'direction_origine_id' => null]);
        Courrier::factory()->create(['direction_origine_id' => $direction->id, 'direction_destination_id' => null]);
        Courrier::factory()->create(['direction_destination_id' => $autreDirection->id, 'direction_origine_id' => null]);

        $response = $this->actingAs($responsable)
            ->getJson('/api/v1/courriers/statistiques-direction')
            ->assertOk();

        $this->assertSame(1, $response->json('courriers_recus_periode'));
        $this->assertSame(1, $response->json('courriers_emis_periode'));
        $this->assertSame(1, $response->json('courriers_recus_non_traites'));
        $this->assertSame(1, $response->json('courriers_emis_en_cours'));
    }

    public function test_les_files_dattente_ne_comptent_pas_les_courriers_deja_enregistres(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        Courrier::factory()->create([
            'direction_destination_id' => $direction->id,
            'direction_origine_id' => null,
            'statut' => CourrierStatut::ENREGISTRE,
        ]);

        $response = $this->actingAs($responsable)
            ->getJson('/api/v1/courriers/statistiques-direction')
            ->assertOk();

        $this->assertSame(0, $response->json('courriers_recus_non_traites'));
    }

    public function test_un_agent_du_circuit_ne_peut_pas_consulter_le_tableau_de_bord_dune_direction(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $this->actingAs($reception)
            ->getJson('/api/v1/courriers/statistiques-direction')
            ->assertStatus(403);
    }

    /**
     * Un compte DFP n'a structurellement pas de direction_id (voir
     * UserFactory::agentDfp(), sans override ici — c'est exactement ce que
     * produit DemoAccountsSeeder) : ce tableau de bord doit lui montrer une
     * vue organisation entière plutôt que de le bloquer (régression
     * corrigée : la policy exigeait auparavant un direction_id non nul même
     * pour ce rôle, ce qui rendait cette route inatteignable pour toute
     * DFP réelle).
     */
    public function test_la_dfp_peut_consulter_un_tableau_de_bord_organisation_entiere_sans_direction_id(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $this->assertNull($dfp->direction_id);

        $directionA = Direction::factory()->create();
        $directionB = Direction::factory()->create();
        Courrier::factory()->create(['direction_destination_id' => $directionA->id]);
        Courrier::factory()->create(['direction_destination_id' => $directionB->id]);

        $response = $this->actingAs($dfp)
            ->getJson('/api/v1/courriers/statistiques-direction')
            ->assertOk();

        // Les deux courriers comptent, bien qu'aucun n'appartienne à une
        // direction "à elle" — c'est précisément la vue organisation
        // entière attendue pour ce rôle.
        $this->assertSame(2, $response->json('courriers_recus_non_traites'));
    }

    public function test_un_responsable_de_direction_ne_voit_que_sa_propre_direction_sur_ce_tableau_de_bord(): void
    {
        $saDirection = Direction::factory()->create();
        $autreDirection = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($saDirection)->create();

        Courrier::factory()->create(['direction_destination_id' => $saDirection->id]);
        Courrier::factory()->create(['direction_destination_id' => $autreDirection->id]);

        $response = $this->actingAs($responsable)
            ->getJson('/api/v1/courriers/statistiques-direction')
            ->assertOk();

        $this->assertSame(1, $response->json('courriers_recus_non_traites'));
    }
}
