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

/**
 * Vérifie le bouclage interne introduit au Lot 1 : un avis DG "réservé"
 * renvoie le dossier à la Réception (tour+1) plutôt que de le faire
 * avancer comme si une décision avait été prise — voir
 * CourrierCircuitService::rendreAvisDg()/representerDg() et
 * config('courrier.circuit_transitions.complet.en_attente_avis_dg').
 */
class BouclageCircuitCourrierTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_le_circuit_boucle_sur_avis_reserve_puis_se_termine_sur_avis_favorable(): void
    {
        Storage::fake('local');

        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $dg = $this->agent(Poste::DG, $direction);

        $id = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Demande de partenariat',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'direction_destination_id' => $direction->id,
            'piece_jointe' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data.id');

        // Reçu, trié, présenté à la DG — premier tour.
        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();
        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$id}/transmettre-tri")->assertOk();
        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();
        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$id}/transmettre-avis-dg", ['degre_urgence' => 'normal'])->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();

        $courrier = Courrier::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame(1, $courrier->tour);

        // Avis réservé avec observation : le dossier boucle, pas d'avance
        // comme si une décision avait été prise.
        $reponse = $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$id}/rendre-avis", [
                'avis_dg' => 'reserve',
                'avis_dg_commentaire' => "Il manque l'avis de la direction concernée.",
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::RETOUR_RECEPTION->value);

        $this->assertSame(1, $reponse->json('data.tour'));

        // La Réception représente le dossier à la DG — tour suivant.
        $this->actingAs($reception)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();
        $representation = $this->actingAs($reception)
            ->postJson("/api/v1/courriers/{$id}/representer-dg")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::EN_ATTENTE_AVIS_DG->value);

        $this->assertSame(2, $representation->json('data.tour'));

        // La garde d'intérim de la DGA s'applique à ce chemin comme aux
        // deux autres (favorable/défavorable) — même mécanisme, pas
        // dupliqué.
        $dga = $this->agent(Poste::DGA, $direction);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$id}/accuser-reception")->assertOk();
        $this->actingAs($dga)
            ->postJson("/api/v1/courriers/{$id}/rendre-avis", ['avis_dg' => 'reserve', 'avis_dg_commentaire' => 'x'])
            ->assertStatus(422);

        // La DG rend cette fois un avis favorable : le circuit reprend son
        // cours normal, tour inchangé.
        $final = $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$id}/rendre-avis", ['avis_dg' => 'favorable'])
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::PROJET_REPONSE_EN_COURS->value);

        $this->assertSame(2, $final->json('data.tour'));
    }

    public function test_un_avis_reserve_sans_observation_est_refuse(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->marquerDecharge($courrier);

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/rendre-avis", ['avis_dg' => 'reserve'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['avis_dg_commentaire']);

        $courrier->refresh();
        $this->assertSame(CourrierStatut::EN_ATTENTE_AVIS_DG, $courrier->statut);
    }

    public function test_un_dossier_qui_boucle_trop_souvent_remonte_en_alerte_sur_le_tableau_de_bord_dg(): void
    {
        config(['courrier.circuit.tours_avant_alerte' => 3]);

        $direction = Direction::factory()->create();
        $administrateur = User::factory()->administrateur()->create();

        $courrierBloque = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'tour' => 4,
            'avis_dg_commentaire' => "Toujours en attente d'un complément.",
        ]);
        Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'tour' => 1]);

        $reponse = $this->actingAs($administrateur)
            ->getJson('/api/v1/courriers/statistiques-dg')
            ->assertOk();

        $this->assertSame(3, $reponse->json('seuil_tours'));
        $dossiersEnBoucle = collect($reponse->json('dossiers_en_boucle'));
        $this->assertCount(1, $dossiersEnBoucle);
        $this->assertSame($courrierBloque->id, $dossiersEnBoucle->first()['id']);
        $this->assertSame(4, $dossiersEnBoucle->first()['tour']);
    }
}
