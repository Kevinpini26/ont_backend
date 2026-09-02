<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\Direction;

/**
 * Vérifie le tri par degré d'urgence introduit au Lot 2 : le Secrétariat 01
 * doit choisir un degré pour transmettre à la DG (jamais une valeur par
 * défaut silencieuse), un dossier non encore trié reste visible et ne
 * bloque rien, remonte simplement en alerte passé un délai, et seule la DG
 * peut corriger le degré après coup — voir
 * CourrierCircuitService::transmettreEnAttenteAvisDg()/requalifierUrgence().
 */
class TriUrgenceCourrierTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_le_secretariat_01_doit_choisir_un_degre_durgence_pour_transmettre_a_la_dg(): void
    {
        $direction = Direction::factory()->create();
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_TRI]);
        $this->marquerDecharge($courrier);

        $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$courrier->id}/transmettre-avis-dg", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['degre_urgence']);

        $courrier->refresh();
        $this->assertSame(CourrierStatut::EN_ATTENTE_TRI, $courrier->statut);
        $this->assertNull($courrier->degre_urgence);
    }

    public function test_le_tri_enregistre_le_degre_lauteur_et_la_date(): void
    {
        $direction = Direction::factory()->create();
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_TRI]);
        $this->marquerDecharge($courrier);

        $reponse = $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$courrier->id}/transmettre-avis-dg", ['degre_urgence' => 'tres_urgent'])
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::EN_ATTENTE_AVIS_DG->value)
            ->assertJsonPath('data.degre_urgence', 'tres_urgent')
            ->assertJsonPath('data.urgence_triee_par', $secretariat1->name);

        $this->assertNotNull($reponse->json('data.urgence_triee_at'));

        $courrier->refresh();
        $this->assertTrue($courrier->urgenceTriee());
        $this->assertSame($secretariat1->id, $courrier->urgence_triee_par_id);
    }

    public function test_un_courrier_non_trie_reste_visible_et_ne_bloque_rien(): void
    {
        Storage::fake('local');

        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);

        $id = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Demande de partenariat',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'direction_destination_id' => $direction->id,
            'piece_jointe' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data.id');

        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();
        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$id}/transmettre-tri")->assertOk();

        // Le dossier reste normalement consultable (pas de garde bloquante
        // sur un degré manquant) et affiche explicitement "pas encore
        // trié" plutôt qu'une valeur par défaut.
        $fiche = $this->actingAs($secretariat1)
            ->getJson("/api/v1/courriers/{$id}")
            ->assertOk()
            ->json('data');

        $this->assertSame(CourrierStatut::EN_ATTENTE_TRI->value, $fiche['statut']);
        $this->assertNull($fiche['degre_urgence']);
        $this->assertNull($fiche['urgence_triee_at']);
    }

    public function test_un_courrier_non_trie_depuis_plus_du_delai_configure_remonte_en_alerte(): void
    {
        config(['courrier.tri.delai_alerte_heures' => 4]);

        $direction = Direction::factory()->create();
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);

        $enAlerte = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_TRI]);
        $enAlerte->transitions()->create([
            'statut' => CourrierStatut::EN_ATTENTE_TRI,
            'created_at' => now()->subHours(6),
            'accuse_reception_at' => now()->subHours(5),
        ]);

        $pasEncoreEnAlerte = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_TRI]);
        $pasEncoreEnAlerte->transitions()->create([
            'statut' => CourrierStatut::EN_ATTENTE_TRI,
            'created_at' => now()->subHour(),
            'accuse_reception_at' => now()->subMinutes(30),
        ]);

        // Déjà trié : ne doit jamais apparaître, même ancien. (Un
        // degre_urgence seul, sans urgence_triee_at, ne compterait pas
        // comme trié — voir Courrier::urgenceTriee().)
        $dejaTrie = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_TRI,
            'degre_urgence' => 'normal',
            'urgence_triee_at' => now()->subHours(9),
        ]);
        $dejaTrie->transitions()->create([
            'statut' => CourrierStatut::EN_ATTENTE_TRI,
            'created_at' => now()->subHours(10),
            'accuse_reception_at' => now()->subHours(9),
        ]);

        $reponse = $this->actingAs($secretariat1)
            ->getJson('/api/v1/courriers/statistiques')
            ->assertOk();

        $this->assertSame(4, $reponse->json('delai_alerte_tri_heures'));
        $alertes = collect($reponse->json('courriers_non_tries_en_alerte'));
        $this->assertCount(1, $alertes);
        $this->assertSame($enAlerte->id, $alertes->first()['id']);
    }

    public function test_seule_la_dg_peut_requalifier_lurgence_dun_dossier(): void
    {
        $direction = Direction::factory()->create();
        $dga = $this->agent(Poste::DGA, $direction);
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);

        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'degre_urgence' => 'normal',
            'urgence_triee_at' => now()->subHour(),
        ]);

        $this->actingAs($dga)
            ->postJson("/api/v1/courriers/{$courrier->id}/requalifier-urgence", ['degre_urgence' => 'tres_urgent'])
            ->assertStatus(403);

        $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$courrier->id}/requalifier-urgence", ['degre_urgence' => 'tres_urgent'])
            ->assertStatus(403);

        $courrier->refresh();
        $this->assertSame('normal', $courrier->degre_urgence->value);
    }

    public function test_la_dg_peut_requalifier_lurgence_et_laudit_trace_lancien_et_le_nouveau_degre(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);

        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::PROJET_REPONSE_EN_COURS,
            'degre_urgence' => 'normal',
            'urgence_triee_at' => now()->subHour(),
        ]);

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/requalifier-urgence", ['degre_urgence' => 'tres_urgent'])
            ->assertOk()
            ->assertJsonPath('data.degre_urgence', 'tres_urgent')
            // Le statut ne change pas : ce n'est pas une transition de
            // circuit, juste une correction du degré.
            ->assertJsonPath('data.statut', CourrierStatut::PROJET_REPONSE_EN_COURS->value);

        $courrier->refresh();
        $this->assertSame('tres_urgent', $courrier->degre_urgence->value);

        $audit = AuditLog::query()->where('action', 'courrier.urgence_requalifiee')->firstOrFail();
        $this->assertSame($dg->id, $audit->user_id);
        $this->assertSame('normal', $audit->meta['ancien_degre'] ?? null);
        $this->assertSame('tres_urgent', $audit->meta['nouveau_degre'] ?? null);
    }

    public function test_impossible_de_requalifier_un_dossier_pas_encore_trie(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_TRI]);

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/requalifier-urgence", ['degre_urgence' => 'urgent'])
            ->assertStatus(422);

        $courrier->refresh();
        $this->assertNull($courrier->degre_urgence);
    }

    /**
     * Un degre_urgence pré-rempli dès la création (métadonnée indicative du
     * registre, voir StoreCourrierRequest) n'équivaut PAS à un tri officiel
     * — seul le passage réel par le Secrétariat 01 pose urgence_triee_at.
     * Sans cette distinction, un simple préremplissage masquerait à tort
     * l'alerte de tri manquant et débloquerait la requalification DG.
     */
    public function test_un_degre_urgence_pre_rempli_a_la_creation_ne_vaut_pas_tri_officiel(): void
    {
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_TRI,
            'degre_urgence' => 'urgent',
            'urgence_triee_at' => null,
        ]);

        $this->assertFalse($courrier->urgenceTriee());

        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/requalifier-urgence", ['degre_urgence' => 'tres_urgent'])
            ->assertStatus(422);
    }
}
