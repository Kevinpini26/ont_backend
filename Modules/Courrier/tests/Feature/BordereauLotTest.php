<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;

/**
 * Lot C, points 1-2 : transmission par lot — la Réception n'a plus besoin
 * qu'un destinataire accuse réception un par un pour vingt dossiers créés
 * le même jour ; elle les groupe sous un bordereau unique, et une seule
 * décharge débloque les vingt d'un coup, chacun gardant sa propre trace.
 */
class BordereauLotTest extends CourrierTestCase
{
    use RefreshDatabase;

    /**
     * Reproduit l'état laissé par creer() sans passer par tout le circuit
     * HTTP (upload de pièce jointe compris) pour chacun des N dossiers —
     * même raccourci que les autres tests de ce module (voir
     * TriUrgenceCourrierTest) : une transition RECU en attente, destinée
     * au Secrétariat 01, pas encore acquittée.
     */
    private function creerCourriersEnAttenteDeSecretariat1(int $nombre, int $receptionId): array
    {
        $courriers = [];
        for ($i = 0; $i < $nombre; $i++) {
            $courrier = Courrier::factory()->create(['statut' => CourrierStatut::RECU]);
            $courrier->transitions()->create([
                'statut' => CourrierStatut::RECU,
                'changed_by_id' => $receptionId,
                'destinataire_poste' => Poste::SECRETARIAT_1->value,
                'created_at' => now(),
            ]);
            $courriers[] = $courrier;
        }

        return $courriers;
    }

    public function test_un_bordereau_de_vingt_dossiers_produit_vingt_traces_individuelles_et_une_seule_decharge_les_debloque(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);

        $courriers = $this->creerCourriersEnAttenteDeSecretariat1(20, $reception->id);
        $ids = array_map(fn ($c) => $c->id, $courriers);

        foreach ($courriers as $courrier) {
            $this->assertTrue($courrier->refresh()->enTransit());
        }

        $reponse = $this->actingAs($reception)
            ->postJson('/api/v1/bordereaux-lot', ['courrier_ids' => $ids])
            ->assertCreated()
            ->json('data');

        $this->assertCount(20, $reponse['dossiers']);
        $this->assertSame('secretariat_1', $reponse['poste_destinataire']);
        $this->assertSame(20, \DB::table('courrier_transitions')->where('bordereau_lot_id', $reponse['id'])->count());

        // Toujours en transit tant que le bordereau lui-même n'est pas
        // déchargé — grouper n'est pas décharger.
        foreach ($courriers as $courrier) {
            $this->assertTrue($courrier->refresh()->enTransit());
        }

        $this->actingAs($secretariat1)
            ->postJson("/api/v1/bordereaux-lot/{$reponse['id']}/accuser-reception")
            ->assertOk()
            ->assertJsonPath('data.acquitte', true);

        foreach ($courriers as $courrier) {
            $this->assertFalse($courrier->refresh()->enTransit());
        }
    }

    public function test_un_bordereau_vide_est_refuse(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $this->actingAs($reception)
            ->postJson('/api/v1/bordereaux-lot', ['courrier_ids' => []])
            ->assertStatus(422);
    }

    public function test_un_lot_ne_peut_pas_melanger_deux_postes_destinataires(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $courriers = $this->creerCourriersEnAttenteDeSecretariat1(2, $reception->id);
        $autre = Courrier::factory()->create(['statut' => CourrierStatut::RECU]);
        $autre->transitions()->create([
            'statut' => CourrierStatut::RECU,
            'changed_by_id' => $reception->id,
            'destinataire_poste' => Poste::PROTOCOLE->value,
            'created_at' => now(),
        ]);

        $ids = [...array_map(fn ($c) => $c->id, $courriers), $autre->id];

        $this->actingAs($reception)
            ->postJson('/api/v1/bordereaux-lot', ['courrier_ids' => $ids])
            ->assertStatus(422);
    }

    public function test_un_dossier_deja_acquitte_ne_peut_pas_entrer_dans_un_lot(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $courriers = $this->creerCourriersEnAttenteDeSecretariat1(2, $reception->id);
        $courriers[0]->transitions()->update(['accuse_reception_at' => now()]);

        $this->actingAs($reception)
            ->postJson('/api/v1/bordereaux-lot', ['courrier_ids' => array_map(fn ($c) => $c->id, $courriers)])
            ->assertStatus(422);
    }

    public function test_le_bordereau_de_lot_est_imprimable(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $courriers = $this->creerCourriersEnAttenteDeSecretariat1(3, $reception->id);

        $idBordereau = $this->actingAs($reception)
            ->postJson('/api/v1/bordereaux-lot', ['courrier_ids' => array_map(fn ($c) => $c->id, $courriers)])
            ->json('data.id');

        $reponse = $this->actingAs($reception)->get("/api/v1/bordereaux-lot/{$idBordereau}/pdf");

        $reponse->assertOk();
        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
    }

    public function test_seul_le_secretariat_01_peut_accuser_reception_du_lot_qui_lui_est_destine(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courriers = $this->creerCourriersEnAttenteDeSecretariat1(2, $reception->id);

        $idBordereau = $this->actingAs($reception)
            ->postJson('/api/v1/bordereaux-lot', ['courrier_ids' => array_map(fn ($c) => $c->id, $courriers)])
            ->json('data.id');

        $this->actingAs($secretariat2)
            ->postJson("/api/v1/bordereaux-lot/{$idBordereau}/accuser-reception")
            ->assertStatus(403);
    }
}
