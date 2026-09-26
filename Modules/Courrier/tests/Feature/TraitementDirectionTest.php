<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\NiveauConfidentialite;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Courrier\Notifications\TraitementDirectionNotification;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class TraitementDirectionTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_reception_cree_un_unique_traitement_puis_secretariat_transmet_au_directeur_canonique(): void
    {
        Notification::fake();
        [$courrier, $dispatch, $secretariat, $directeur, $sec2] = $this->dispatchInterneExecute();
        $identite = [$courrier->id, $courrier->dossier_id, $courrier->numero_enregistrement, $courrier->reference_documentaire, $courrier->numero_depart];

        $this->actingAs($secretariat)->postJson("/api/v1/dispatchs/{$dispatch->id}/accuser-reception")->assertOk();
        $traitement = TraitementDirection::query()->firstOrFail();
        $this->assertSame('recu_secretariat', $traitement->statut->value);
        $this->assertSame($dispatch->id, $traitement->dispatch_courrier_id);
        $this->assertDatabaseCount('traitements_direction', 1);

        $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/transmettre-directeur", ['note' => 'Pour traitement prioritaire.'])
            ->assertOk()->assertJsonPath('data.statut', 'transmis_directeur')->assertJsonPath('data.directeur.id', $directeur->id);
        Notification::assertSentTo($directeur, TraitementDirectionNotification::class);
        $this->assertSame($secretariat->id, $traitement->fresh()->transmis_par_secretariat_id);
        $this->assertSame($identite, [$courrier->fresh()->id, $courrier->dossier_id, $courrier->numero_enregistrement, $courrier->reference_documentaire, $courrier->numero_depart]);
        $this->assertDatabaseCount('courriers', 1);
        $this->assertDatabaseCount('dossiers', 1);
    }

    public function test_directeur_designe_prend_en_charge_et_enregistre_une_decision_finale_immuable(): void
    {
        [$courrier, $dispatch, $secretariat, $directeur] = $this->dispatchInterneExecute();
        $this->actingAs($secretariat)->postJson("/api/v1/dispatchs/{$dispatch->id}/accuser-reception")->assertOk();
        $traitement = TraitementDirection::query()->firstOrFail();
        $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/transmettre-directeur")->assertOk();

        $this->actingAs($directeur)->getJson('/api/v1/traitements-direction')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($directeur)->postJson("/api/v1/traitements-direction/{$traitement->id}/prendre-en-charge")
            ->assertOk()->assertJsonPath('data.statut', 'en_traitement');
        $this->actingAs($directeur)->postJson("/api/v1/traitements-direction/{$traitement->id}/decision", [
            'decision' => 'retour_a_preparer', 'commentaire' => 'Préparer une réponse sans encore la créer.',
        ])->assertOk()->assertJsonPath('data.statut', 'termine_directeur')->assertJsonPath('data.decision_directeur', 'retour_a_preparer');
        $this->assertDatabaseHas('audit_logs', ['action' => 'traitement_direction.decision_directeur', 'auditable_id' => $traitement->id]);
        $this->actingAs($directeur)->postJson("/api/v1/traitements-direction/{$traitement->id}/decision", ['decision' => 'traitement_termine'])
            ->assertForbidden();
        $this->actingAs($directeur)->putJson("/api/v1/traitements-direction/{$traitement->id}", ['decision' => 'autre'])->assertNotFound();
        $this->assertSame('ONT/DG/2026/000901', $courrier->fresh()->reference_documentaire);
    }

    public function test_acces_horizontaux_admin_et_secretariat_sont_refuses_sur_courrier_secret(): void
    {
        [$courrier, $dispatch, $secretariat, $directeur] = $this->dispatchInterneExecute(NiveauConfidentialite::SECRET);
        $autreDirection = Direction::factory()->create();
        $autreSecretariat = User::factory()->secretariatDirection($autreDirection)->create();
        $autreDirecteur = User::factory()->directeurDirection($autreDirection)->create();
        $admin = User::factory()->administrateur()->create();
        $this->actingAs($secretariat)->postJson("/api/v1/dispatchs/{$dispatch->id}/accuser-reception")->assertOk();
        $traitement = TraitementDirection::query()->firstOrFail();

        $this->actingAs($autreSecretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/transmettre-directeur")->assertForbidden();
        $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/transmettre-directeur")->assertOk();
        $this->actingAs($autreDirecteur)->getJson('/api/v1/traitements-direction')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($autreDirecteur)->postJson("/api/v1/traitements-direction/{$traitement->id}/prendre-en-charge")->assertForbidden();
        $this->actingAs($admin)->postJson("/api/v1/traitements-direction/{$traitement->id}/prendre-en-charge")->assertForbidden();
        $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/prendre-en-charge")->assertForbidden();
        $this->actingAs($secretariat)->postJson("/api/v1/courriers/{$courrier->id}/rendre-avis", ['avis_dg' => 'favorable'])->assertForbidden();
        $this->actingAs($secretariat)->postJson("/api/v1/courriers/{$courrier->id}/dispatcher-direction")->assertForbidden();
        $this->actingAs($directeur)->postJson("/api/v1/traitements-direction/{$traitement->id}/prendre-en-charge")->assertOk();
    }

    public function test_absence_ou_multiplicite_de_directeur_bloque_sans_choix_arbitraire(): void
    {
        [$courrier, $dispatch, $secretariat, $directeur] = $this->dispatchInterneExecute();
        $directeur->delete();
        $this->actingAs($secretariat)->postJson("/api/v1/dispatchs/{$dispatch->id}/accuser-reception")->assertOk();
        $traitement = TraitementDirection::query()->firstOrFail();
        $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/transmettre-directeur")
            ->assertUnprocessable()->assertJsonValidationErrors('directeur');

        User::factory()->directeurDirection($secretariat->direction)->count(2)->create();
        $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/transmettre-directeur")
            ->assertUnprocessable()->assertJsonValidationErrors('directeur');
        $this->assertSame('recu_secretariat', $traitement->fresh()->statut->value);
    }

    public function test_role_historique_est_accepte_seulement_en_repli_et_deux_directions_restent_independantes(): void
    {
        Notification::fake();
        $centrale = Direction::factory()->create();
        $directionA = Direction::factory()->create();
        $directionB = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $centrale);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $centrale);
        $secretariatA = User::factory()->secretariatDirection($directionA)->create();
        $secretariatB = User::factory()->secretariatDirection($directionB)->create();
        $historiqueA = User::factory()->responsableDirection($directionA)->create(['role' => 'responsable_direction']);
        $directeurB = User::factory()->directeurDirection($directionB)->create();
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [
            ['type' => 'direction', 'direction_id' => $directionA->id, 'instruction' => 'A'],
            ['type' => 'direction', 'direction_id' => $directionB->id, 'instruction' => 'B'],
        ]])->assertOk();
        foreach ($courrier->dispatchs as $dispatch) {
            $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertOk();
        }
        $dispatchA = $courrier->dispatchs()->where('direction_id', $directionA->id)->firstOrFail();
        $dispatchB = $courrier->dispatchs()->where('direction_id', $directionB->id)->firstOrFail();
        $this->actingAs($secretariatA)->postJson("/api/v1/dispatchs/{$dispatchA->id}/accuser-reception")->assertOk();
        $this->actingAs($secretariatB)->postJson("/api/v1/dispatchs/{$dispatchB->id}/accuser-reception")->assertOk();
        [$traitementA, $traitementB] = TraitementDirection::query()->orderBy('id')->get();
        $this->actingAs($secretariatA)->postJson("/api/v1/traitements-direction/{$traitementA->id}/transmettre-directeur")->assertOk()->assertJsonPath('data.directeur.id', $historiqueA->id);
        $this->actingAs($secretariatB)->postJson("/api/v1/traitements-direction/{$traitementB->id}/transmettre-directeur")->assertOk()->assertJsonPath('data.directeur.id', $directeurB->id);
        $this->actingAs($historiqueA)->postJson("/api/v1/traitements-direction/{$traitementA->id}/prendre-en-charge")->assertOk();
        $this->assertSame('transmis_directeur', $traitementB->fresh()->statut->value);
        $this->assertDatabaseCount('courriers', 1);
        $this->assertDatabaseCount('dossiers', 1);
        $this->assertDatabaseCount('traitements_direction', 2);
    }

    /** @return array{Courrier, DispatchCourrier, User, User, User} */
    private function dispatchInterneExecute(NiveauConfidentialite $confidentialite = NiveauConfidentialite::ORDINAIRE): array
    {
        $centrale = Direction::factory()->create();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $centrale);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $centrale);
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        $directeur = User::factory()->directeurDirection($direction)->create();
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'niveau_confidentialite' => $confidentialite,
            'numero_enregistrement' => '2026-000901', 'reference_documentaire' => 'ONT/DG/2026/000901', 'numero_depart' => 'DEP-2026-0901',
        ]);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [[
            'type' => 'direction', 'direction_id' => $direction->id, 'instruction' => 'Pour traitement.',
        ]]])->assertOk();
        $dispatch = $courrier->dispatchs()->firstOrFail();
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertOk();

        return [$courrier, $dispatch, $secretariat, $directeur, $sec2];
    }
}
