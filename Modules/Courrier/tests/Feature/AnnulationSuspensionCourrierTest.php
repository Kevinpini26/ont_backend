<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;

/** Un vrai système de courrier permet d'annuler un enregistrement par erreur et de mettre un courrier en attente. */
class AnnulationSuspensionCourrierTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_la_reception_annule_un_courrier_enregistre_par_erreur_avec_motif_et_trace(): void
    {
        Notification::fake();
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::RECU, 'mode_reception' => 'depot_en_ligne']);

        $this->actingAs($reception)->postJson("/api/v1/courriers/{$courrier->id}/annuler", ['motif' => 'x'])->assertUnprocessable();
        $this->actingAs($reception)->postJson("/api/v1/courriers/{$courrier->id}/annuler", ['motif' => 'Doublon : déjà enregistré sous 2026-0012.'])
            ->assertOk()
            ->assertJsonPath('data.annule', true)
            ->assertJsonPath('data.motif_annulation', 'Doublon : déjà enregistré sous 2026-0012.')
            ->assertJsonPath('data.peut_annuler', false);

        $this->assertDatabaseHas('audit_logs', ['action' => 'courrier.annule', 'auditable_id' => $courrier->id, 'user_id' => $reception->id]);
        $this->assertDatabaseHas('courriers', ['id' => $courrier->id]); // jamais supprimé

        // sorti des files de travail, toujours retrouvable au registre
        $this->actingAs($reception)->getJson('/api/v1/courriers')->assertOk()->assertJsonMissing(['id' => $courrier->id]);
        $this->actingAs($reception)->getJson('/api/v1/courriers?inclure_annules=1')->assertOk()->assertJsonFragment(['id' => $courrier->id]);

        // plus aucune étape possible
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $this->actingAs($reception)->postJson("/api/v1/courriers/{$courrier->id}/transmettre-sec1")->assertUnprocessable();
        $this->actingAs($reception)->postJson("/api/v1/courriers/{$courrier->id}/annuler", ['motif' => 'Deuxième annulation'])->assertForbidden();
    }

    public function test_un_courrier_deja_decide_ou_un_poste_non_habilite_ne_peut_pas_etre_annule(): void
    {
        Notification::fake();
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $dg = $this->agent(Poste::DG, $direction);
        $assistant = $this->agent(Poste::ASSISTANT_1, $direction);
        $enDispatch = Courrier::factory()->create(['statut' => CourrierStatut::EN_DISPATCH]);
        $recu = Courrier::factory()->create(['statut' => CourrierStatut::RECU, 'mode_reception' => 'depot_en_ligne']);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$enDispatch->id}/annuler", ['motif' => 'Trop tard pour annuler'])->assertForbidden();
        $this->actingAs($reception)->getJson("/api/v1/courriers/{$recu->id}")->assertOk()->assertJsonPath('data.peut_annuler', true);
        $statut = $this->actingAs($assistant)->postJson("/api/v1/courriers/{$recu->id}/annuler", ['motif' => 'Pas mon rôle ici'])->status();
        $this->assertContains($statut, [403, 404]);
    }

    public function test_un_courrier_mis_en_attente_ne_peut_plus_avancer_puis_reprend(): void
    {
        Notification::fake();
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::RECU, 'mode_reception' => 'depot_en_ligne', 'numero_enregistrement' => '2026-0100', 'enregistre_at' => now()]);

        $this->actingAs($reception)->postJson("/api/v1/courriers/{$courrier->id}/suspendre", ['motif' => 'Pièce jointe manquante, relance envoyée.'])
            ->assertOk()
            ->assertJsonPath('data.suspendu', true)
            ->assertJsonPath('data.motif_suspension', 'Pièce jointe manquante, relance envoyée.')
            ->assertJsonPath('data.peut_reprendre_suspension', true)
            ->assertJsonPath('data.peut_suspendre', false);

        $this->actingAs($reception)->postJson("/api/v1/courriers/{$courrier->id}/transmettre-sec1")
            ->assertUnprocessable()
            ->assertJsonPath('errors.courrier.0', 'Ce courrier est mis en attente : reprenez-le avant de continuer.');
        $this->assertSame(CourrierStatut::RECU, $courrier->fresh()->statut);

        $this->actingAs($reception)->postJson("/api/v1/courriers/{$courrier->id}/reprendre-suspension")
            ->assertOk()->assertJsonPath('data.suspendu', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'courrier.suspension_levee', 'auditable_id' => $courrier->id]);

        $this->actingAs($reception)->postJson("/api/v1/courriers/{$courrier->id}/transmettre-sec1")->assertOk();
    }
}
