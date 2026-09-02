<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * Correction du circuit DG/DGA : la DGA n'intervient que lorsque la DG est
 * explicitement marquée indisponible (intérim), jamais par défaut. Vérifie
 * aussi que la correction s'applique à tout type de courrier exigeant un
 * avis DG, pas seulement aux demandes de stage.
 */
class DgInterimTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_disponibilite_par_defaut(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);

        $this->actingAs($dg)
            ->getJson('/api/v1/dg-disponibilite')
            ->assertOk()
            ->assertJsonPath('disponible', true);
    }

    public function test_la_dg_peut_se_marquer_indisponible(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);

        $this->actingAs($dg)
            ->postJson('/api/v1/dg-disponibilite', ['disponible' => false])
            ->assertOk()
            ->assertJsonPath('disponible', false);

        $this->actingAs($dg)
            ->getJson('/api/v1/dg-disponibilite')
            ->assertJsonPath('disponible', false);
    }

    public function test_ladministrateur_peut_basculer_la_disponibilite(): void
    {
        $direction = Direction::factory()->create();
        $this->agent(Poste::DG, $direction);
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/dg-disponibilite', ['disponible' => false])
            ->assertOk()
            ->assertJsonPath('disponible', false);
    }

    public function test_un_responsable_de_direction_ne_peut_pas_basculer_la_disponibilite(): void
    {
        $direction = Direction::factory()->create();
        $this->agent(Poste::DG, $direction);
        $responsable = User::factory()->responsableDirection($direction)->create();

        $this->actingAs($responsable)
            ->postJson('/api/v1/dg-disponibilite', ['disponible' => false])
            ->assertStatus(403);
    }

    public function test_la_dga_peut_rendre_lavis_une_fois_la_dg_indisponible(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $dga = $this->agent(Poste::DGA, $direction);

        $this->actingAs($dg)->postJson('/api/v1/dg-disponibilite', ['disponible' => false])->assertOk();

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->marquerDecharge($courrier);

        $response = $this->actingAs($dga)
            ->postJson("/api/v1/courriers/{$courrier->id}/rendre-avis", ['avis_dg' => 'favorable'])
            ->assertOk()
            ->assertJsonPath('data.avis_dg_rendu_en_interim', true)
            ->assertJsonPath('data.avis_dg_rendu_par', $dga->name);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'courrier.avis_dg_rendu',
            'auditable_id' => $courrier->id,
        ]);

        $response->assertJsonPath('data.statut', CourrierStatut::PROJET_REPONSE_EN_COURS->value);
    }

    public function test_la_dg_reste_toujours_habilitee_meme_marquee_indisponible(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);

        $this->actingAs($dg)->postJson('/api/v1/dg-disponibilite', ['disponible' => false])->assertOk();

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->marquerDecharge($courrier);

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/rendre-avis", ['avis_dg' => 'favorable'])
            ->assertOk()
            ->assertJsonPath('data.avis_dg_rendu_en_interim', false);
    }

    /**
     * La correction s'applique à tout courrier exigeant un avis DG, pas
     * seulement aux demandes de stage — ici une correspondance générale
     * routée vers la DG (pas de direction_destination_id, donc circuit
     * complet, voir CourrierCircuitService::creer()).
     */
    public function test_le_circuit_direct_sapplique_a_la_correspondance_generale(): void
    {
        $direction = Direction::factory()->create();
        $protocole = $this->agent(Poste::PROTOCOLE, $direction);
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $dg = $this->agent(Poste::DG, $direction);

        $courrier = Courrier::factory()->create([
            'type' => CourrierType::CORRESPONDANCE_GENERALE,
            'statut' => CourrierStatut::AU_PROTOCOLE,
            'necessite_avis_dg' => true,
        ]);
        $this->marquerDecharge($courrier);

        // Même si le Protocole a été emprunté (hypothèse simulée ici — voir
        // config('courrier.categories_protocole'), vide en pratique
        // aujourd'hui), il transmet au tri du Secrétariat 01, jamais
        // directement à la DG : le tri précède toujours la DG.
        $this->actingAs($protocole)
            ->postJson("/api/v1/courriers/{$courrier->id}/transmettre-au-tri-depuis-protocole")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::EN_ATTENTE_TRI->value);

        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")->assertOk();

        $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$courrier->id}/transmettre-avis-dg")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::EN_ATTENTE_AVIS_DG->value);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")->assertOk();

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/rendre-avis", ['avis_dg' => 'favorable'])
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::PROJET_REPONSE_EN_COURS->value);

        // Aucun événement de création de fiche stagiaire pour ce type.
        $this->assertDatabaseMissing('stagiaires', ['courrier_id' => $courrier->id]);
    }
}
