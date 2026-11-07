<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DegreUrgence;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Notifications\CopieCourrierNotification;
use Modules\Courrier\Notifications\DispatchCourrierNotification;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/** Fonctions d'un système de courrier d'entreprise : copie pour information, échéance, relance des directions. */
class CopiesEcheancesRelancesTest extends CourrierTestCase
{
    use RefreshDatabase;

    private function secretariat(Direction $direction): User
    {
        return User::factory()->create(['role' => UserRole::SECRETARIAT_DIRECTION, 'direction_id' => $direction->id]);
    }

    public function test_la_dg_met_une_direction_en_copie_elle_voit_le_courrier_sans_pouvoir_le_traiter(): void
    {
        Notification::fake();
        $dgDirection = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $dgDirection);
        $direction = Direction::factory()->create();
        $secretariat = $this->secretariat($direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);

        $this->actingAs($secretariat)->getJson("/api/v1/courriers/{$courrier->id}")->assertNotFound();

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/copies", ['direction_ids' => [$direction->id], 'note' => 'Pour votre information.'])
            ->assertOk()
            ->assertJsonPath('data.copies.0.direction.id', $direction->id)
            ->assertJsonPath('data.copies.0.note', 'Pour votre information.');
        Notification::assertSentTo($secretariat, CopieCourrierNotification::class);

        $this->actingAs($secretariat)->getJson("/api/v1/courriers/{$courrier->id}")
            ->assertOk()
            ->assertJsonPath('data.peut_ajouter_copies', false);
        $this->actingAs($secretariat)->postJson("/api/v1/courriers/{$courrier->id}/copies", ['direction_ids' => [$direction->id]])->assertForbidden();
        $this->assertSame(CourrierStatut::EN_ATTENTE_AVIS_DG, $courrier->fresh()->statut);

        // idempotent : une seconde mise en copie ne crée pas de doublon
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/copies", ['direction_ids' => [$direction->id]])->assertOk();
        $this->assertDatabaseCount('courrier_copies', 1);
    }

    public function test_une_direction_destinataire_nest_pas_mise_en_copie(): void
    {
        Notification::fake();
        $dg = $this->agent(Poste::DG, Direction::factory()->create());
        $direction = Direction::factory()->create();
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [
            ['type' => 'direction', 'direction_id' => $direction->id, 'instruction' => 'Traiter.'],
        ]])->assertOk();

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/copies", ['direction_ids' => [$direction->id]])->assertOk();
        $this->assertDatabaseCount('courrier_copies', 0);
    }

    public function test_la_dg_fixe_une_echeance_et_la_direction_la_voit_en_retard_une_fois_depassee(): void
    {
        Notification::fake();
        $dg = $this->agent(Poste::DG, Direction::factory()->create());
        $direction = Direction::factory()->create();
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [
            ['type' => 'direction', 'direction_id' => $direction->id, 'instruction' => 'Traiter.', 'echeance' => now()->subDay()->toDateString()],
        ]])->assertUnprocessable();

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [
            ['type' => 'direction', 'direction_id' => $direction->id, 'instruction' => 'Traiter.', 'echeance' => now()->addDays(5)->toDateString()],
        ]])->assertOk()
            ->assertJsonPath('data.dispatchs.0.echeance_at', now()->addDays(5)->toDateString())
            ->assertJsonPath('data.dispatchs.0.en_retard', false);

        $dispatch = $courrier->dispatchs()->first();
        $dispatch->update(['echeance_at' => now()->subDays(2)->toDateString()]);
        $this->assertTrue($dispatch->fresh()->estEnRetard());
    }

    public function test_les_directions_en_retard_sont_relancees_une_seule_fois_et_la_dg_est_informee(): void
    {
        // Lot 6 : les délais se comptent en heures ouvrées ; date fixe un mercredi 15 h (Kinshasa) pour que le test ne dépende pas du jour où il tourne.
        $this->travelTo(Carbon::parse('2026-11-11 15:00', 'Africa/Kinshasa'));
        Notification::fake();
        $dg = $this->agent(Poste::DG, Direction::factory()->create());
        $direction = Direction::factory()->create();
        $secretariat = $this->secretariat($direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::DISPATCH_EXECUTE]);

        $creer = fn (array $attributs) => DispatchCourrier::query()->create($attributs + [
            'courrier_id' => $courrier->id, 'dossier_id' => $courrier->dossier_id,
            'type_destination' => DispatchTypeDestination::DIRECTION, 'direction_id' => $direction->id,
            'instruction' => 'Traiter.', 'decisionnaire_id' => $dg->id, 'decisionnaire_poste' => Poste::DG,
            'autorite_poste' => Poste::DG, 'decide_at' => now()->subDays(10), 'statut' => DispatchStatut::EXECUTE,
        ]);
        $enRetard = $creer(['cycle' => 1, 'execute_at' => now()->subDays(9), 'echeance_at' => now()->subDay()->toDateString()]);
        // 12 jours calendaires : nettement plus de 7 jours ouvrés (9 jours tombaient pile sur la limite).
        $sansEcheanceAncien = $creer(['cycle' => 2, 'execute_at' => now()->subDays(12)]);
        $dansLesDelais = $creer(['cycle' => 3, 'execute_at' => now()->subDays(9), 'echeance_at' => now()->addDays(3)->toDateString()]);
        $recent = $creer(['cycle' => 4, 'execute_at' => now()->subDay()]);

        $this->artisan('courrier:relancer-directions-en-retard')->assertSuccessful();

        $this->assertNotNull($enRetard->fresh()->relance_direction_envoyee_at);
        $this->assertNotNull($sansEcheanceAncien->fresh()->relance_direction_envoyee_at);
        $this->assertNull($dansLesDelais->fresh()->relance_direction_envoyee_at);
        $this->assertNull($recent->fresh()->relance_direction_envoyee_at);
        Notification::assertSentTo($secretariat, DispatchCourrierNotification::class, fn ($n) => $n->toArray($secretariat)['evenement'] === 'relance_direction');
        Notification::assertSentTo($dg, DispatchCourrierNotification::class, fn ($n) => $n->toArray($dg)['evenement'] === 'relance_direction_dg');

        Notification::fake();
        $this->artisan('courrier:relancer-directions-en-retard')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_un_courrier_normal_va_au_classeur_ou_directement_a_la_dg_selon_la_configuration(): void
    {
        Notification::fake();
        $direction = Direction::factory()->create();
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $direction);

        $trier = function () use ($sec1): Courrier {
            $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_TRI]);
            $courrier->transitions()->create([
                'statut' => CourrierStatut::EN_ATTENTE_TRI, 'nouveau_statut' => CourrierStatut::EN_ATTENTE_TRI,
                'destinataire_poste' => Poste::SECRETARIAT_1, 'accuse_reception_par_id' => $sec1->id, 'accuse_reception_at' => now(), 'created_at' => now(),
            ]);
            $this->actingAs($sec1)->postJson("/api/v1/courriers/{$courrier->id}/transmettre-avis-dg", ['degre_urgence' => DegreUrgence::NORMAL->value])->assertOk();

            return $courrier->fresh();
        };

        $this->assertSame(CourrierStatut::EN_ATTENTE_CLASSEUR, $trier()->statut);

        config(['courrier.tri.normal_vers_classeur' => false]);
        $this->assertSame(CourrierStatut::EN_ATTENTE_AVIS_DG, $trier()->statut);
    }
}
