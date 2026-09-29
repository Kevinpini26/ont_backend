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

class EnvoiParDirectionTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_une_direction_ne_peut_plus_creer_un_courrier_entrant_ni_ouvrir_un_circuit_court(): void
    {
        $origine = Direction::factory()->create();
        $destination = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($origine)->create();

        $this->actingAs($responsable)->postJson('/api/v1/courriers', [
            'objet' => 'Tentative de contournement',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'direction_destination_id' => $destination->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('courriers', ['objet' => 'Tentative de contournement']);
    }

    public function test_une_direction_ne_peut_pas_contourner_la_regle_en_ciblant_la_dg_ou_un_stage(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        foreach ([CourrierType::CORRESPONDANCE_GENERALE, CourrierType::DEMANDE_STAGE] as $type) {
            $this->actingAs($responsable)->postJson('/api/v1/courriers', [
                'objet' => 'Entrée interdite '.$type->value,
                'type' => $type->value,
            ])->assertForbidden();
        }

        $this->assertDatabaseCount('courriers', 0);
    }

    public function test_un_courrier_recu_par_la_reception_suit_toujours_le_circuit_complet(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Courrier externe',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire externe',
            'mode_reception' => 'porteur',
            'direction_destination_id' => $direction->id,
            'piece_jointe' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('data.necessite_avis_dg', true)
            ->assertJsonPath('data.statut', CourrierStatut::RECU->value);
    }

    public function test_un_dossier_historique_du_circuit_court_reste_lisible_mais_ne_peut_plus_avancer(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::RECU,
            'necessite_avis_dg' => false,
            'direction_destination_id' => $direction->id,
        ]);

        $this->actingAs($responsable)->getJson("/api/v1/courriers/{$courrier->id}")
            ->assertOk()
            ->assertJsonPath('data.necessite_avis_dg', false);

        $this->actingAs($secretariat2)
            ->postJson("/api/v1/courriers/{$courrier->id}/enregistrer", ['classification' => 'interne'])
            ->assertNotFound();

        $this->assertSame(CourrierStatut::RECU, $courrier->fresh()->statut);
    }

    public function test_lancienne_route_de_retour_direct_vers_la_dg_nest_plus_exposee(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::RETOUR_RECEPTION]);

        $this->actingAs($reception)
            ->postJson("/api/v1/courriers/{$courrier->id}/representer-dg")
            ->assertNotFound();
    }

    public function test_un_agent_dfp_ne_peut_pas_creer_de_courrier(): void
    {
        $dfp = User::factory()->agentDfp()->create();

        $this->actingAs($dfp)->postJson('/api/v1/courriers', [
            'objet' => 'Test',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
        ])->assertForbidden();
    }

    public function test_un_administrateur_nest_pas_un_acteur_generique_du_circuit(): void
    {
        $admin = User::factory()->administrateur()->create();
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::RECU]);

        $this->actingAs($admin)->postJson('/api/v1/courriers', [
            'objet' => 'Création administrative interdite',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
        ])->assertForbidden();

        $this->actingAs($admin)
            ->postJson("/api/v1/courriers/{$courrier->id}/transmettre-tri")
            ->assertForbidden();

        $this->assertSame(CourrierStatut::RECU, $courrier->fresh()->statut);
    }

    public function test_la_reception_ne_peut_pas_transmettre_directement_a_la_dg(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::RECU,
            'necessite_avis_dg' => true,
            'created_by' => $reception->id,
        ]);

        $this->actingAs($reception)
            ->postJson("/api/v1/courriers/{$courrier->id}/transmettre-avis-dg", ['degre_urgence' => 'urgent'])
            ->assertForbidden();

        $this->assertSame(CourrierStatut::RECU, $courrier->fresh()->statut);
    }

    public function test_la_trace_documente_avant_apres_acteur_destinataire_date_et_instruction(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);

        $id = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Traçabilité Phase 4',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire externe',
            'mode_reception' => 'porteur',
            'piece_jointe' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data.id');

        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();
        $reponse = $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$id}/transmettre-tri", [
            'instruction' => 'Vérifier la priorité et les annexes.',
        ])->assertOk();

        $trace = collect($reponse->json('data.transitions'))->last();
        $traceInitiale = collect($reponse->json('data.transitions'))->first();
        $this->assertNull($traceInitiale['ancien_statut']);
        $this->assertSame(CourrierStatut::RECU->value, $traceInitiale['nouveau_statut']);
        $this->assertSame($reception->name, $traceInitiale['emetteur']);
        $this->assertSame(CourrierStatut::RECU->value, $trace['ancien_statut']);
        $this->assertSame(CourrierStatut::EN_ATTENTE_TRI->value, $trace['nouveau_statut']);
        $this->assertSame($secretariat1->name, $trace['emetteur']);
        $this->assertSame(Poste::SECRETARIAT_1->value, $trace['expediteur_poste']);
        $this->assertNull($trace['destinataire']);
        $this->assertNull($trace['accuse_reception_at']);
        $this->assertSame('Vérifier la priorité et les annexes.', $trace['instruction']);
        $this->assertNotNull($trace['created_at']);
        $this->assertFalse($reponse->json('data.en_transit'));
    }
}
