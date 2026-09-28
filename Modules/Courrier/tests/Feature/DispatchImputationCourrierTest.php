<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * Lot 3 (orientation de la DG et dispatch) : un avis DG favorable rendu sur
 * un courrier déjà imputé part en dispatch (Secrétariat 02 -> secrétariat
 * de la direction imputée) plutôt qu'en rédaction interne de réponse. Voir
 * CourrierCircuitService::rendreAvisDg()/dispatcherVersDirection() et
 * config('courrier.circuit_transitions.complet.en_attente_avis_dg').
 */
class DispatchImputationCourrierTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_la_materialisation_conserve_lauteur_reel_de_limputation(): void
    {
        $direction = Direction::factory()->create();
        $auteur = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_DISPATCH]);
        $this->marquerDecharge($courrier, Poste::SECRETARIAT_2);
        $imputation = $courrier->imputations()->create([
            'direction_id' => $direction->id, 'mention' => 'pour_attribution',
            'est_principale' => true, 'imputee_par_id' => $auteur->id,
        ]);
        $imputation->refresh();
        $avant = $imputation->getRawOriginal();

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/dispatcher-direction")->assertOk();

        $dispatch = DispatchCourrier::query()->sole();
        $this->assertSame($auteur->id, $dispatch->decisionnaire_id);
        $this->assertNotSame($sec2->id, $dispatch->decisionnaire_id);
        $this->assertSame(Poste::DG, $dispatch->decisionnaire_poste);
        $this->assertSame($imputation->created_at->toDateTimeString(), $dispatch->decide_at->toDateTimeString());
        $this->assertSame($avant, $imputation->fresh()->getRawOriginal());
    }

    public function test_limputation_sans_auteur_est_refusee_sans_modifier_lhistorique(): void
    {
        $direction = Direction::factory()->create();
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_DISPATCH]);
        $this->marquerDecharge($courrier, Poste::SECRETARIAT_2);
        $imputation = $courrier->imputations()->create([
            'direction_id' => $direction->id, 'mention' => 'pour_attribution',
            'est_principale' => true, 'imputee_par_id' => null,
        ]);
        $imputation->refresh();
        $avant = $imputation->getRawOriginal();
        $courrierAvant = $courrier->fresh()->getRawOriginal();

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/dispatcher-direction")
            ->assertUnprocessable()->assertJsonValidationErrors('courrier');

        $this->assertSame(0, DispatchCourrier::query()->count());
        $this->assertSame($avant, $imputation->fresh()->getRawOriginal());
        $this->assertSame($courrierAvant, $courrier->fresh()->getRawOriginal());
        $this->actingAs($sec2)->getJson("/api/v1/courriers/{$courrier->id}")->assertOk();
    }

    public function test_avis_favorable_sur_courrier_impute_part_en_dispatch_plutot_quen_redaction(): void
    {
        $direction = Direction::factory()->create();
        $directionImputee = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $direction);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->marquerDecharge($courrier);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/imputer", [
            'imputations' => [
                ['direction_id' => $directionImputee->id, 'mention' => 'pour_attribution', 'est_principale' => true],
            ],
        ])->assertOk();

        $reponse = $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/rendre-avis", ['avis_dg' => 'favorable'])
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::EN_DISPATCH->value);

        $this->assertNotSame(CourrierStatut::PROJET_A_REDIGER->value, $reponse->json('data.statut'));

        // Le Secrétariat 02 doit accuser réception du bordereau avant de
        // pouvoir dispatcher — même discipline que le reste du circuit.
        $this->actingAs($secretariat2)
            ->postJson("/api/v1/courriers/{$courrier->id}/dispatcher-direction")
            ->assertStatus(422);

        $this->actingAs($secretariat2)->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")->assertOk();

        $this->actingAs($secretariat2)
            ->postJson("/api/v1/courriers/{$courrier->id}/dispatcher-direction")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::DISPATCH_EXECUTE->value)
            ->assertJsonPath('data.en_transit', false);
    }

    public function test_avis_favorable_non_impute_conserve_a_sous_controle_dg_en_attendant_la_mission_d(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->marquerDecharge($courrier);

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/rendre-avis", ['avis_dg' => 'favorable'])
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::EN_ATTENTE_AVIS_DG->value);
    }

    public function test_avis_reserve_sur_courrier_impute_retourne_quand_meme_a_la_reception(): void
    {
        $direction = Direction::factory()->create();
        $directionImputee = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->marquerDecharge($courrier);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/imputer", [
            'imputations' => [
                ['direction_id' => $directionImputee->id, 'mention' => 'pour_attribution', 'est_principale' => true],
            ],
        ])->assertOk();

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/rendre-avis", [
                'avis_dg' => 'reserve',
                'avis_dg_commentaire' => 'Complément nécessaire malgré l\'imputation.',
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::RETOUR_RECEPTION->value);
    }

    public function test_un_poste_non_habilite_ne_peut_pas_dispatcher(): void
    {
        $direction = Direction::factory()->create();
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);

        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_DISPATCH,
            'created_by' => $secretariat1->id,
        ]);
        $this->marquerDecharge($courrier);

        $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$courrier->id}/dispatcher-direction")
            ->assertForbidden();
    }

    public function test_le_secretariat_de_la_direction_imputee_voit_le_courrier_chez_direction(): void
    {
        $directionImputee = Direction::factory()->create();
        $directionAutre = Direction::factory()->create();

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::CHEZ_DIRECTION]);
        $courrier->imputations()->create([
            'direction_id' => $directionImputee->id,
            'mention' => 'pour_attribution',
            'est_principale' => true,
        ]);

        $secretariatConcerne = User::factory()->secretariatDirection($directionImputee)->create();
        $secretariatAutre = User::factory()->secretariatDirection($directionAutre)->create();
        $responsableConcerne = User::factory()->responsableDirection($directionImputee)->create();

        $this->actingAs($secretariatConcerne)
            ->getJson("/api/v1/courriers/{$courrier->id}")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::CHEZ_DIRECTION->value);

        $this->actingAs($responsableConcerne)
            ->getJson("/api/v1/courriers/{$courrier->id}")
            ->assertOk();

        $this->actingAs($secretariatAutre)
            ->getJson("/api/v1/courriers/{$courrier->id}")
            ->assertNotFound();
    }
}
