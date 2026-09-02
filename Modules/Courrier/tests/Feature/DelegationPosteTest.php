<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\DelegationPoste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class DelegationPosteTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_un_administrateur_peut_creer_une_delegation(): void
    {
        $admin = User::factory()->administrateur()->create();
        $delegataire = User::factory()->responsableDirection()->create();

        $response = $this->actingAs($admin)->postJson('/api/v1/delegations-poste', [
            'poste' => Poste::PROTOCOLE->value,
            'delegataire_id' => $delegataire->id,
            'debut' => now()->toDateString(),
            'fin' => now()->addDays(5)->toDateString(),
            'motif' => 'Congé du titulaire du Protocole',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('delegations_poste', [
            'poste' => Poste::PROTOCOLE->value,
            'delegataire_id' => $delegataire->id,
            'cree_par_id' => $admin->id,
        ]);
    }

    public function test_un_non_administrateur_ne_peut_pas_creer_une_delegation(): void
    {
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::PROTOCOLE, $direction);
        $delegataire = User::factory()->responsableDirection()->create();

        $this->actingAs($agent)->postJson('/api/v1/delegations-poste', [
            'poste' => Poste::PROTOCOLE->value,
            'delegataire_id' => $delegataire->id,
            'debut' => now()->toDateString(),
            'fin' => now()->addDays(5)->toDateString(),
        ])->assertForbidden();
    }

    public function test_un_utilisateur_avec_une_delegation_active_peut_agir_au_nom_du_poste_delegue(): void
    {
        $admin = User::factory()->administrateur()->create();
        $delegataire = User::factory()->responsableDirection()->create();
        DelegationPoste::query()->create([
            'poste' => Poste::SECRETARIAT_1,
            'delegataire_id' => $delegataire->id,
            'debut' => now()->subDay()->toDateString(),
            'fin' => now()->addDay()->toDateString(),
            'motif' => 'Titulaire absent',
            'cree_par_id' => $admin->id,
        ]);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::RECU]);
        $this->marquerDecharge($courrier);

        $response = $this->actingAs($delegataire)->postJson("/api/v1/courriers/{$courrier->id}/transmettre-tri");

        $response->assertOk();
        $this->assertSame(CourrierStatut::EN_ATTENTE_TRI->value, $response->json('data.statut'));

        // transitions() est trié du plus ancien au plus récent (voir
        // Courrier::transitions()) : la dernière ligne est donc last(), pas
        // first() sur un ->latest() qui s'ajouterait après ce tri déjà fixé.
        $derniereTransition = $courrier->transitions()->get()->last();
        $this->assertTrue((bool) $derniereTransition->agi_en_interim);
    }

    public function test_une_action_normale_par_le_titulaire_du_poste_nest_pas_marquee_en_interim(): void
    {
        $direction = Direction::factory()->create();
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::RECU]);
        $this->marquerDecharge($courrier);

        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$courrier->id}/transmettre-tri")->assertOk();

        $derniereTransition = $courrier->transitions()->get()->last();
        $this->assertFalse((bool) $derniereTransition->agi_en_interim);
    }

    public function test_un_utilisateur_sans_poste_ni_delegation_active_est_rejete(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        // Le courrier doit être visible (via le scope direction) pour que
        // le rejet testé soit bien celui de la policy (poste non habilité),
        // pas une invisibilité de scope qui rendrait 404 au lieu de 403.
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::RECU,
            'direction_destination_id' => $direction->id,
        ]);
        $this->marquerDecharge($courrier);

        $this->actingAs($responsable)->postJson("/api/v1/courriers/{$courrier->id}/transmettre-protocole")
            ->assertForbidden();
    }

    public function test_une_delegation_expiree_nest_plus_prise_en_compte(): void
    {
        $direction = Direction::factory()->create();
        $admin = User::factory()->administrateur()->create();
        $delegataire = User::factory()->responsableDirection($direction)->create();
        DelegationPoste::query()->create([
            'poste' => Poste::PROTOCOLE,
            'delegataire_id' => $delegataire->id,
            'debut' => now()->subDays(10)->toDateString(),
            'fin' => now()->subDays(3)->toDateString(),
            'motif' => 'Délégation déjà terminée',
            'cree_par_id' => $admin->id,
        ]);

        // Visible via son propre direction_destination_id, pour isoler le
        // rejet policy (délégation expirée) d'une invisibilité de scope.
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::RECU,
            'direction_destination_id' => $direction->id,
        ]);
        $this->marquerDecharge($courrier);

        $this->actingAs($delegataire)->postJson("/api/v1/courriers/{$courrier->id}/transmettre-protocole")
            ->assertForbidden();
    }
}
