<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\DelegationPoste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class Secretariat1PerimetreTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_historique_de_a_ne_revele_ni_mission_dg_ni_projet_d(): void
    {
        $direction = Direction::factory()->create();
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $dg = $this->agent(Poste::DG, $direction);
        $dg1 = $this->agent(Poste::ASSISTANT_1, $direction);
        $this->agent(Poste::ASSISTANT_2, $direction);
        $a = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $a->transitions()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'changed_by_id' => $sec1->id,
            'expediteur_poste' => Poste::SECRETARIAT_1->value,
            'destinataire_poste' => Poste::DG->value,
            'accuse_reception_at' => now(),
            'created_at' => now(),
        ]);

        $missionId = $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/demander-preparation-reponse", [
            'assistant_id' => $dg1->id,
            'instruction' => 'Instruction confidentielle de la DG',
        ])->assertCreated()->json('data.id');
        $this->actingAs($dg1)->postJson("/api/v1/missions-documentaires/{$missionId}/prendre-en-charge")->assertOk();
        $d = $this->actingAs($dg1)->postJson("/api/v1/missions-documentaires/{$missionId}/projet-reponse", [
            'objet' => 'Projet D secret',
            'destinataire_externe_nom' => 'Destinataire D secret',
            'projet_reponse_contenu' => ['type' => 'doc', 'content' => []],
        ])->assertCreated()->json('projet.id');

        $this->actingAs($dg)->getJson("/api/v1/courriers/{$a->id}")
            ->assertOk()->assertJsonPath('data.missions_documentaires.0.projet_courrier_id', $d);
        $this->actingAs($dg1)->getJson("/api/v1/courriers/{$a->id}")
            ->assertOk()->assertJsonPath('data.missions_documentaires.0.projet_courrier_id', $d);

        $this->actingAs($sec1)->getJson("/api/v1/courriers/{$a->id}")
            ->assertOk()->assertJsonCount(0, 'data.missions_documentaires')
            ->assertJsonCount(0, 'data.correspondance')
            ->assertDontSee('Instruction confidentielle de la DG')
            ->assertDontSee('Projet D secret')
            ->assertDontSee('Destinataire D secret');
        $this->actingAs($sec1)->getJson("/api/v1/courriers/{$a->id}/missions")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($sec1)->getJson("/api/v1/courriers/{$d}")->assertNotFound();
        $this->actingAs($sec1)->getJson("/api/v1/dossiers/{$a->dossier_id}")->assertOk()->assertJsonCount(1, 'data.documents');
    }

    public function test_sec1_peut_lire_a_sans_annoter_et_sans_creer_de_reponse_ou_mission(): void
    {
        $direction = Direction::factory()->create();
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $dg = $this->agent(Poste::DG, $direction);
        $dg1 = $this->agent(Poste::ASSISTANT_1, $direction);
        $a = Courrier::factory()->create(['necessite_avis_dg' => false]);
        $a->transitions()->create([
            'statut' => $a->statut,
            'destinataire_poste' => Poste::SECRETARIAT_1->value,
            'accuse_reception_at' => now(),
            'created_at' => now(),
        ]);
        $this->actingAs($sec1)->getJson("/api/v1/courriers/{$a->id}")->assertOk();
        $this->actingAs($sec1)->postJson("/api/v1/courriers/{$a->id}/annotations", ['contenu' => 'Décision DG usurpée'])->assertForbidden();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/annotations", ['contenu' => 'Instruction DG'])->assertCreated();
        $this->actingAs($sec1)->getJson("/api/v1/courriers/{$a->id}/annotations")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($sec1)->postJson("/api/v1/courriers/{$a->id}/initier-reponse", [
            'relecteur_id' => $dg1->id,
            'projet_reponse_contenu' => ['type' => 'doc', 'content' => []],
        ])->assertForbidden();
        $this->actingAs($sec1)->postJson('/api/v1/courriers/initier-sortant', [])->assertForbidden();
        $this->actingAs($sec1)->postJson("/api/v1/courriers/{$a->id}/missions", [
            'assistant_id' => $dg1->id, 'instruction' => 'Usurpation',
        ])->assertForbidden();
    }

    public function test_une_delegation_dg_active_donne_les_droits_dg_sans_les_donner_au_poste_sec1(): void
    {
        $direction = Direction::factory()->create();
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $dg = $this->agent(Poste::DG, $direction);
        $assistant = $this->agent(Poste::ASSISTANT_1, $direction);
        $a = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $a->transitions()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'changed_by_id' => $sec1->id,
            'destinataire_poste' => Poste::DG->value,
            'created_at' => now(),
        ]);
        $missionId = $this->actingAs($dg)->postJson("/api/v1/courriers/{$a->id}/demander-preparation-reponse", [
            'assistant_id' => $assistant->id,
            'instruction' => 'Instruction DG réservée',
        ])->assertCreated()->json('data.id');

        $this->actingAs($sec1)->getJson("/api/v1/courriers/{$a->id}/missions")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($sec1)->postJson("/api/v1/courriers/{$a->id}/annotations", ['contenu' => 'Annotation déléguée'])->assertForbidden();

        DelegationPoste::query()->create([
            'poste' => Poste::DG,
            'delegataire_id' => $sec1->id,
            'debut' => now()->subDay()->toDateString(),
            'fin' => now()->addDay()->toDateString(),
            'motif' => 'Intérim DG explicite',
            'cree_par_id' => User::factory()->administrateur()->create()->id,
        ]);

        $this->actingAs($sec1)->getJson("/api/v1/courriers/{$a->id}/missions")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $missionId)
            ->assertJsonPath('data.0.instruction', 'Instruction DG réservée');
        $this->actingAs($sec1)->postJson("/api/v1/courriers/{$a->id}/annotations", ['contenu' => 'Annotation déléguée'])->assertCreated();
    }

    public function test_recherche_pieces_kpi_et_pagination_restant_dans_le_perimetre_sec1(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create();
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $visible = Courrier::factory()->create(['objet' => 'VISIBLE-SEC1', 'statut' => CourrierStatut::RECU]);
        $visible->transitions()->create([
            'statut' => CourrierStatut::RECU, 'changed_by_id' => $reception->id,
            'destinataire_poste' => Poste::SECRETARIAT_1->value, 'created_at' => now(),
        ]);
        $secret = Courrier::factory()->create([
            'objet' => 'ETRANGER-SEC1', 'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'numero_enregistrement' => 'ENR-SECRET-SEC1', 'reference_documentaire' => 'REF-SECRET-SEC1',
            'expediteur_externe_nom' => 'EXPEDITEUR-SECRET-SEC1',
            'piece_jointe_chemin' => UploadedFile::fake()->create('secret.pdf', 20, 'application/pdf')->store('courriers', 'local'),
        ]);

        $this->actingAs($sec1)->getJson('/api/v1/courriers')->assertOk()->assertSee('VISIBLE-SEC1')->assertDontSee('ETRANGER-SEC1');
        foreach (['ETRANGER-SEC1', 'ENR-SECRET-SEC1', 'REF-SECRET-SEC1', 'EXPEDITEUR-SECRET-SEC1'] as $terme) {
            $this->actingAs($sec1)->getJson('/api/v1/courriers?recherche='.urlencode($terme))->assertOk()->assertJsonCount(0, 'data');
        }
        $this->actingAs($sec1)->getJson("/api/v1/courriers/{$secret->id}")->assertNotFound();
        $this->actingAs($sec1)->get("/api/v1/courriers/{$secret->id}/piece-jointe")->assertNotFound();
        $this->actingAs($sec1)->getJson('/api/v1/courriers/statistiques')->assertOk()
            ->assertDontSee('ETRANGER-SEC1')->assertDontSee('ENR-SECRET-SEC1')
            ->assertJsonPath('courriers_non_tries_en_alerte', [])
            ->assertJsonPath('en_cours_total', 1);

        $autresVisibles = Courrier::factory()->count(21)->create(['statut' => CourrierStatut::RECU, 'necessite_avis_dg' => true]);
        foreach ($autresVisibles as $courrier) {
            $courrier->transitions()->create([
                'statut' => CourrierStatut::RECU, 'changed_by_id' => $reception->id,
                'destinataire_poste' => Poste::SECRETARIAT_1->value, 'created_at' => now(),
            ]);
        }
        $premiere = $this->actingAs($sec1)->getJson('/api/v1/courriers?statut=recu&necessite_avis_dg=1&page=1')->assertOk();
        $seconde = $this->actingAs($sec1)->getJson('/api/v1/courriers?statut=recu&necessite_avis_dg=1&page=2')->assertOk();
        $this->assertSame(22, $premiere->json('meta.total'));
        $this->assertCount(20, $premiere->json('data'));
        $this->assertCount(2, $seconde->json('data'));
        $this->assertCount(22, array_unique(array_merge(array_column($premiere->json('data'), 'id'), array_column($seconde->json('data'), 'id'))));
    }

    public function test_un_poste_etranger_ne_peut_pas_creer_un_lot_destine_a_sec1_et_la_confirmation_repetee_est_refusee(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::RECU]);
        $courrier->transitions()->create([
            'statut' => CourrierStatut::RECU, 'changed_by_id' => $reception->id,
            'destinataire_poste' => Poste::SECRETARIAT_1->value, 'created_at' => now(),
        ]);
        $this->actingAs($sec2)->postJson('/api/v1/bordereaux-lot', ['courrier_ids' => [$courrier->id]])->assertUnprocessable();
        $lotId = $this->actingAs($sec1)->postJson('/api/v1/bordereaux-lot', ['courrier_ids' => [$courrier->id]])->assertCreated()->json('data.id');
        $this->actingAs($sec1)->postJson("/api/v1/bordereaux-lot/{$lotId}/accuser-reception")->assertOk();
        $this->actingAs($sec1)->postJson("/api/v1/bordereaux-lot/{$lotId}/accuser-reception")->assertUnprocessable();
    }
}
