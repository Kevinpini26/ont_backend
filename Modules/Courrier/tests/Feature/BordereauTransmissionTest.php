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

/**
 * Vérifie le mécanisme du bordereau de transmission et de la décharge
 * obligatoire lui-même (voir CourrierCircuitService::tracerTransition()/
 * assertDechargeDonnee()/accuserReception()), indépendamment des tests de
 * circuit existants qui, eux, se contentent d'insérer les appels
 * "accuser-reception" nécessaires pour progresser.
 */
class BordereauTransmissionTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_un_courrier_fraichement_cree_est_en_transit_pour_son_destinataire(): void
    {
        Storage::fake('local');

        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);

        $courrier = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Demande de partenariat',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire externe',
            'mode_reception' => 'porteur',
            'direction_destination_id' => $direction->id,
            'piece_jointe' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data');

        $this->assertTrue($courrier['en_transit']);
        $this->assertCount(1, $courrier['transitions']);
        $this->assertSame(CourrierStatut::RECU->value, $courrier['transitions'][0]['statut']);
        $this->assertSame($reception->name, $courrier['transitions'][0]['emetteur']);
        // Le Protocole n'est plus le destinataire par défaut (Lot 1,
        // bouclage) : la Réception transmet directement au tri du
        // Secrétariat 01, sans Protocole.
        $this->assertSame('Secrétariat 01', $courrier['transitions'][0]['destinataire']);
        $this->assertNull($courrier['transitions'][0]['accuse_reception_at']);

        $id = $courrier['id'];

        $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$id}/transmettre-tri")
            ->assertStatus(422);

        $this->assertSame(CourrierStatut::RECU, Courrier::withoutGlobalScopes()->findOrFail($id)->statut);
    }

    public function test_la_decharge_debloque_la_transmission_suivante(): void
    {
        Storage::fake('local');

        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);

        $id = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Demande de partenariat',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire externe',
            'mode_reception' => 'porteur',
            'direction_destination_id' => $direction->id,
            'piece_jointe' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data.id');

        $decharge = $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$id}/accuser-reception")
            ->assertOk()
            ->json('data');

        $this->assertFalse($decharge['en_transit']);
        $this->assertSame($secretariat1->name, $decharge['transitions'][0]['accuse_reception_par']);
        $this->assertNotNull($decharge['transitions'][0]['accuse_reception_at']);

        $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$id}/transmettre-tri")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::EN_ATTENTE_TRI->value)
            ->assertJsonPath('data.en_transit', false)
            ->assertJsonPath('data.transitions.1.ancien_statut', CourrierStatut::RECU->value)
            ->assertJsonPath('data.transitions.1.nouveau_statut', CourrierStatut::EN_ATTENTE_TRI->value)
            ->assertJsonPath('data.transitions.1.destinataire', null)
            ->assertJsonPath('data.transitions.1.accuse_reception_at', null);
    }

    public function test_le_fil_de_bordereaux_reflete_fidelement_chaque_transmission_dans_lordre(): void
    {
        Storage::fake('local');

        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $dg = $this->agent(Poste::DG, $direction);

        $id = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Demande de partenariat',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire externe',
            'mode_reception' => 'porteur',
            'direction_destination_id' => $direction->id,
            'piece_jointe' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data.id');

        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();
        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$id}/transmettre-tri")->assertOk();
        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$id}/transmettre-avis-dg", ['degre_urgence' => 'urgent'])->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();

        $transitions = $this->actingAs($dg)
            ->getJson("/api/v1/courriers/{$id}")
            ->assertOk()
            ->json('data.transitions');

        $this->assertCount(3, $transitions);

        $this->assertSame(CourrierStatut::RECU->value, $transitions[0]['statut']);
        $this->assertSame($reception->name, $transitions[0]['emetteur']);
        $this->assertSame('Secrétariat 01', $transitions[0]['destinataire']);
        $this->assertSame($secretariat1->name, $transitions[0]['accuse_reception_par']);

        $this->assertSame(CourrierStatut::EN_ATTENTE_TRI->value, $transitions[1]['statut']);
        $this->assertSame($secretariat1->name, $transitions[1]['emetteur']);
        $this->assertNull($transitions[1]['destinataire']);
        $this->assertNull($transitions[1]['accuse_reception_at']);

        $this->assertSame(CourrierStatut::EN_ATTENTE_AVIS_DG->value, $transitions[2]['statut']);
        $this->assertSame($secretariat1->name, $transitions[2]['emetteur']);
        $this->assertSame('Directeur Général', $transitions[2]['destinataire']);
        $this->assertSame($dg->name, $transitions[2]['accuse_reception_par']);
        $this->assertNotNull($transitions[2]['accuse_reception_at']);

        // Ordre chronologique strict, pas seulement un regroupement par statut.
        $dates = array_column($transitions, 'created_at');
        $tries = $dates;
        sort($tries);
        $this->assertSame($tries, $dates);
    }

    public function test_un_poste_non_habilite_ne_peut_pas_accuser_reception(): void
    {
        Storage::fake('local');

        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $dg = $this->agent(Poste::DG, $direction);

        $id = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Demande de partenariat',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire externe',
            'mode_reception' => 'porteur',
            'direction_destination_id' => $direction->id,
            'piece_jointe' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data.id');

        // La DG n'est pas destinataire du bordereau fraîchement remis :
        // celui-ci vise explicitement le Secrétariat 01.
        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$id}/accuser-reception")
            ->assertStatus(403);
    }

    public function test_une_decharge_deja_donnee_ne_peut_pas_etre_redonnee(): void
    {
        $direction = Direction::factory()->create();
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::RECU]);
        $this->marquerDecharge($courrier);

        $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")
            ->assertStatus(422)
            ->assertJsonPath('message', 'La réception de ce dossier a déjà été accusée.');
    }

    public function test_un_ancien_circuit_court_reste_fige_meme_avec_une_decharge(): void
    {
        $directionEmettrice = Direction::factory()->create();
        $directionDestinataire = Direction::factory()->create();
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $directionEmettrice);
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::RECU,
            'necessite_avis_dg' => false,
            'direction_destination_id' => $directionDestinataire->id,
        ]);

        $this->actingAs($secretariat2)
            ->postJson("/api/v1/courriers/{$courrier->id}/enregistrer", [
                'classification' => 'interne',
                'note_technique' => 'RAS',
            ])
            ->assertNotFound();

        $this->assertSame(CourrierStatut::RECU, $courrier->fresh()->statut);
    }

    public function test_le_bordereau_en_relecture_vise_le_relecteur_designe_pas_un_poste(): void
    {
        $direction = Direction::factory()->create();
        $redacteur = $this->agent(Poste::ASSISTANT_2, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_1, $direction);
        $autreAssistant = $this->agent(Poste::ASSISTANT_2, $direction);

        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::PROJET_A_REDIGER,
            'created_by' => $redacteur->id,
        ]);
        $this->marquerDecharge($courrier);

        $this->actingAs($redacteur)->postJson("/api/v1/courriers/{$courrier->id}/soumettre-projet-reponse", [
            'projet_reponse_contenu' => ['type' => 'doc', 'content' => []],
            'relecteur_id' => $relecteur->id,
        ])->assertOk();

        $fiche = $this->actingAs($relecteur)
            ->getJson("/api/v1/courriers/{$courrier->id}")
            ->assertOk()
            ->json('data');

        $this->assertTrue($fiche['en_transit']);
        $dernier = end($fiche['transitions']);
        $this->assertSame(CourrierStatut::PROJET_A_VALIDER->value, $dernier['statut']);
        $this->assertSame($relecteur->name, $dernier['destinataire']);

        // Un autre assistant, même poste éligible en général, n'est pas le
        // destinataire précis de ce bordereau.
        $this->actingAs($autreAssistant)
            ->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")
            ->assertNotFound();

        $this->actingAs($relecteur)
            ->postJson("/api/v1/courriers/{$courrier->id}/valider-relecture")
            ->assertStatus(422);

        $this->actingAs($relecteur)->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")->assertOk();

        $this->actingAs($relecteur)
            ->postJson("/api/v1/courriers/{$courrier->id}/valider-relecture")
            ->assertOk();
    }
}
