<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierClassification;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;

class CircuitCourrierTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_le_circuit_progresse_etape_par_etape_jusqua_lenregistrement(): void
    {
        Storage::fake('local');

        $direction = Direction::factory()->create();

        $reception = $this->agent(Poste::RECEPTION, $direction);
        $protocole = $this->agent(Poste::PROTOCOLE, $direction);
        $dga = $this->agent(Poste::DGA, $direction);
        $dg = $this->agent(Poste::DG, $direction);
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_1, $direction);

        $courrier = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Demande de partenariat',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'direction_destination_id' => $direction->id,
            'piece_jointe' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data');

        $this->assertSame(CourrierStatut::RECU->value, $courrier['statut']);
        $id = $courrier['id'];

        // Chaque transition exige désormais une décharge explicite du
        // destinataire avant de pouvoir transmettre à son tour — voir
        // BordereauTransmissionTest.php pour la vérification dédiée du
        // mécanisme lui-même.
        //
        // Correction du bouclage (Lot 1) : le Protocole n'est plus une étape
        // obligatoire — la Réception transmet directement au tri du
        // Secrétariat 01, sans Protocole, conformément au circuit décrit par
        // la Direction (voir config('courrier.circuit_transitions.complet'),
        // condition 'protocole_requis' jamais satisfaite tant qu'aucune
        // catégorie n'y est confirmée).
        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();

        $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$id}/transmettre-tri")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::EN_ATTENTE_TRI->value);

        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();

        // Corrigé : le Secrétariat 01 transmet directement à la DG pour
        // avis, sans étape DGA obligatoire (voir DgInterimTest pour le cas
        // d'intérim, où la DGA intervient explicitement).
        $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$id}/transmettre-avis-dg")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::EN_ATTENTE_AVIS_DG->value);

        // La DG (jamais la DGA) accuse réception du bordereau : la DGA
        // reste ensuite bloquée par la garde d'intérim ci-dessous, pas par
        // un défaut de décharge — la distinction entre les deux raisons de
        // blocage doit rester nette.
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();

        // La DGA ne peut pas rendre l'avis tant que la DG est disponible.
        $this->actingAs($dga)
            ->postJson("/api/v1/courriers/{$id}/rendre-avis", ['avis_dg' => 'favorable'])
            ->assertStatus(422);

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$id}/rendre-avis", ['avis_dg' => 'favorable'])
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::PROJET_REPONSE_EN_COURS->value)
            ->assertJsonPath('data.avis_dg_rendu_par', $dg->name)
            ->assertJsonPath('data.avis_dg_rendu_en_interim', false);

        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();

        $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$id}/soumettre-projet-reponse", [
                'projet_reponse_contenu' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
                'relecteur_id' => $relecteur->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::EN_RELECTURE->value);

        $this->actingAs($relecteur)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();

        $this->actingAs($relecteur)
            ->postJson("/api/v1/courriers/{$id}/valider-relecture")
            ->assertOk();

        // La décharge du bordereau en_relecture, donnée par le relecteur
        // ci-dessus, suffit aussi à débloquer la signature de la DG : ce
        // n'est pas un nouveau bordereau distinct (voir
        // CourrierCircuitService::tracerTransition()).
        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$id}/signer")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::SIGNE->value);

        $this->actingAs($secretariat2)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();

        // Créé par la Réception (mail physique externe, aucune direction
        // d'origine forcée) : classé externe automatiquement, voir
        // Courrier::classificationAttendue() et ConformiteCahierDesChargesTest.
        $response = $this->actingAs($secretariat2)
            ->postJson("/api/v1/courriers/{$id}/enregistrer", [
                'classification' => CourrierClassification::EXTERNE->value,
                'accuse_reception_partenaire' => 'AR-PARTENAIRE-001',
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::ENREGISTRE->value);

        $this->assertNotNull($response->json('data.numero_enregistrement'));
        $this->assertStringStartsWith((string) now()->year, $response->json('data.numero_enregistrement'));
    }

    public function test_impossible_de_sauter_une_etape_du_circuit(): void
    {
        $direction = Direction::factory()->create();
        // Le protocole est bien le poste habilité pour l'étape "au_protocole"
        // (celle qui mène à en_attente_avis_dg), mais le courrier est encore
        // à "recu" : la règle métier doit interdire de sauter "au_protocole".
        $protocole = $this->agent(Poste::PROTOCOLE, $direction);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::RECU]);

        $this->actingAs($protocole)
            ->postJson("/api/v1/courriers/{$courrier->id}/transmettre-avis-dg")
            ->assertStatus(422);

        $courrier->refresh();
        $this->assertSame(CourrierStatut::RECU, $courrier->statut);
    }

    public function test_un_poste_non_habilite_ne_peut_pas_faire_avancer_le_courrier(): void
    {
        $direction = Direction::factory()->create();
        // Ni le Protocole ni le Secrétariat 01 : aucune des deux candidates
        // depuis "recu" (transmettre-protocole, transmettre-tri) n'habilite
        // la DG.
        $dg = $this->agent(Poste::DG, $direction);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::RECU]);

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/transmettre-tri")
            ->assertStatus(403);
    }

    public function test_la_signature_est_refusee_sans_relecture_validee(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_1, $direction);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::PROJET_REPONSE_EN_COURS]);
        $this->marquerDecharge($courrier);

        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$courrier->id}/soumettre-projet-reponse", [
            'projet_reponse_contenu' => ['type' => 'doc', 'content' => []],
            'relecteur_id' => $relecteur->id,
        ])->assertOk();

        $this->actingAs($relecteur)->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")->assertOk();

        // Tentative de signature sans validation préalable de la relecture.
        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/signer")
            ->assertStatus(422);

        $courrier->refresh();
        $this->assertSame(CourrierStatut::EN_RELECTURE, $courrier->statut);
        $this->assertNull($courrier->signe_at);

        // Une fois la relecture validée, la signature devient possible.
        $this->actingAs($relecteur)->postJson("/api/v1/courriers/{$courrier->id}/valider-relecture")->assertOk();

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/signer")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::SIGNE->value);
    }

    public function test_seul_le_relecteur_designe_peut_valider_la_relecture(): void
    {
        $direction = Direction::factory()->create();
        $relecteur = $this->agent(Poste::ASSISTANT_1, $direction);
        $autreAssistant = $this->agent(Poste::ASSISTANT_2, $direction);

        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_RELECTURE,
            'relecteur_id' => $relecteur->id,
        ]);

        $this->actingAs($autreAssistant)
            ->postJson("/api/v1/courriers/{$courrier->id}/valider-relecture")
            ->assertStatus(403);

        $this->assertNull($courrier->refresh()->relecture_validee_at);
    }
}
