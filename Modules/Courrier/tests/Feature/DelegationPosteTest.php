<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\DelegationPoste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;

class DelegationPosteTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_lancien_poste_protocole_ne_peut_plus_etre_delegue(): void
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

        $response->assertUnprocessable()->assertJsonValidationErrors('poste');
    }

    public function test_lancien_poste_assistant_protocole_ne_peut_plus_etre_delegue(): void
    {
        $admin = User::factory()->administrateur()->create();
        $delegataire = User::factory()->responsableDirection()->create();

        $response = $this->actingAs($admin)->postJson('/api/v1/delegations-poste', [
            'poste' => Poste::ASSISTANT_PROTOCOLE->value,
            'delegataire_id' => $delegataire->id,
            'debut' => now()->toDateString(),
            'fin' => now()->addDays(5)->toDateString(),
            'motif' => "Suppression de l'ancien poste",
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('poste');
    }

    public function test_un_non_administrateur_ne_peut_pas_creer_une_delegation(): void
    {
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::SECRETARIAT_1, $direction);
        $delegataire = User::factory()->responsableDirection()->create();

        $this->actingAs($agent)->postJson('/api/v1/delegations-poste', [
            'poste' => Poste::SECRETARIAT_1->value,
            'delegataire_id' => $delegataire->id,
            'debut' => now()->toDateString(),
            'fin' => now()->addDays(5)->toDateString(),
        ])->assertForbidden();
    }

    public function test_chevauchement_de_delegation_du_meme_poste_est_refuse_et_creation_auditee(): void
    {
        $admin = User::factory()->administrateur()->create();
        $premier = User::factory()->responsableDirection()->create();
        $second = User::factory()->responsableDirection()->create();
        $periode = [
            'poste' => Poste::DG->value,
            'debut' => now()->toDateString(),
            'fin' => now()->addDays(5)->toDateString(),
            'motif' => 'Absence du titulaire',
        ];
        $this->actingAs($admin)->postJson('/api/v1/delegations-poste', [
            ...$periode, 'delegataire_id' => $premier->id,
        ])->assertCreated();
        $this->actingAs($admin)->postJson('/api/v1/delegations-poste', [
            ...$periode, 'delegataire_id' => $second->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('poste');
        $this->assertDatabaseCount('delegations_poste', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'delegation_poste.creee', 'user_id' => $admin->id]);
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

        $this->actingAs($responsable)->postJson("/api/v1/courriers/{$courrier->id}/transmettre-tri")
            ->assertForbidden();
    }

    public function test_une_delegation_expiree_nest_plus_prise_en_compte(): void
    {
        $direction = Direction::factory()->create();
        $admin = User::factory()->administrateur()->create();
        $delegataire = User::factory()->responsableDirection($direction)->create();
        DelegationPoste::query()->create([
            'poste' => Poste::SECRETARIAT_1,
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

        $this->actingAs($delegataire)->postJson("/api/v1/courriers/{$courrier->id}/transmettre-tri")
            ->assertForbidden();
    }

    public function test_revocation_active_retire_immediatement_les_droits_sans_modifier_lhistorique(): void
    {
        $admin = User::factory()->administrateur()->create();
        $delegataire = User::factory()->responsableDirection()->create();
        $debut = now()->subDay()->toDateString();
        $fin = now()->addDays(5)->toDateString();
        $delegation = DelegationPoste::query()->create([
            'poste' => Poste::DG, 'delegataire_id' => $delegataire->id,
            'debut' => $debut, 'fin' => $fin, 'motif' => 'Absence DG', 'cree_par_id' => $admin->id,
        ]);
        $resolver = app(DelegationResolver::class);
        $this->assertTrue($resolver->utilisateurHabilite($delegataire, [Poste::DG]));
        $this->actingAs($delegataire)->postJson("/api/v1/delegations-poste/{$delegation->id}/revoquer", ['motif' => 'Usurpation'])
            ->assertForbidden();
        $this->actingAs($admin)->postJson("/api/v1/delegations-poste/{$delegation->id}/revoquer", ['motif' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors('motif');
        $this->actingAs($admin)->postJson("/api/v1/delegations-poste/{$delegation->id}/revoquer", ['motif' => 'Retour de la titulaire'])
            ->assertOk()->assertJsonPath('data.etat', 'revoquee');
        $delegation->refresh();
        $date = $delegation->revoquee_at;
        $this->assertNotNull($date);
        $this->assertSame($admin->id, $delegation->revoquee_par_id);
        $this->assertSame('Retour de la titulaire', $delegation->motif_revocation);
        $this->assertSame($debut, $delegation->debut->toDateString());
        $this->assertSame($fin, $delegation->fin->toDateString());
        $this->assertFalse($resolver->utilisateurHabilite($delegataire, [Poste::DG]));
        $this->actingAs($admin)->getJson('/api/v1/delegations-poste')->assertOk()
            ->assertJsonPath('data.0.motif_revocation', 'Retour de la titulaire');
        $this->actingAs($admin)->postJson("/api/v1/delegations-poste/{$delegation->id}/revoquer", ['motif' => 'Autre motif'])
            ->assertUnprocessable();
        $this->assertSame($date->toIso8601String(), $delegation->fresh()->revoquee_at->toIso8601String());
        $this->assertSame('Retour de la titulaire', $delegation->fresh()->motif_revocation);
        $this->assertDatabaseHas('audit_logs', ['action' => 'delegation_poste.revoquee', 'user_id' => $admin->id]);
        $nouveau = User::factory()->responsableDirection()->create();
        $this->actingAs($admin)->postJson('/api/v1/delegations-poste', [
            'poste' => Poste::DG->value, 'delegataire_id' => $nouveau->id,
            'debut' => now()->toDateString(), 'fin' => $fin, 'motif' => 'Nouvel intérim',
        ])->assertCreated();
    }

    public function test_delegation_future_peut_etre_revoquee_et_expiree_ne_peut_pas_letre(): void
    {
        $admin = User::factory()->administrateur()->create();
        $delegataire = User::factory()->responsableDirection()->create();
        $future = DelegationPoste::query()->create([
            'poste' => Poste::DG, 'delegataire_id' => $delegataire->id,
            'debut' => now()->addDays(2), 'fin' => now()->addDays(4),
            'motif' => 'Absence prévue', 'cree_par_id' => $admin->id,
        ]);
        $expiree = DelegationPoste::query()->create([
            'poste' => Poste::SECRETARIAT_1, 'delegataire_id' => $delegataire->id,
            'debut' => now()->subDays(4), 'fin' => now()->subDay(),
            'motif' => 'Absence passée', 'cree_par_id' => $admin->id,
        ]);
        $this->actingAs($admin)->getJson('/api/v1/delegations-poste')->assertOk()
            ->assertJsonPath('data.0.etat', 'expiree')->assertJsonPath('data.1.etat', 'future');
        $this->actingAs($admin)->postJson("/api/v1/delegations-poste/{$future->id}/revoquer", ['motif' => 'Annulation prévisionnelle'])
            ->assertOk()->assertJsonPath('data.etat', 'revoquee');
        $this->actingAs($admin)->postJson("/api/v1/delegations-poste/{$expiree->id}/revoquer", ['motif' => 'Trop tard'])
            ->assertUnprocessable();
        $this->assertNull($expiree->fresh()->revoquee_at);
    }

    public function test_un_delegataire_dg_revoque_ne_peut_plus_decider_ni_signer(): void
    {
        $direction = Direction::factory()->create();
        $admin = User::factory()->administrateur()->create();
        $delegataire = User::factory()->responsableDirection($direction)->create();
        $delegation = DelegationPoste::query()->create([
            'poste' => Poste::DG, 'delegataire_id' => $delegataire->id,
            'debut' => now()->subDay(), 'fin' => now()->addDay(),
            'motif' => 'Intérim DG', 'cree_par_id' => $admin->id,
        ]);
        $a = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'direction_destination_id' => $direction->id,
        ]);
        $d = Courrier::factory()->create([
            'statut' => CourrierStatut::PROJET_A_VALIDER,
            'sens' => 'sortant', 'direction_destination_id' => $direction->id,
        ]);
        $this->actingAs($admin)->postJson("/api/v1/delegations-poste/{$delegation->id}/revoquer", [
            'motif' => 'Fin de mission',
        ])->assertOk();
        $this->actingAs($delegataire)->postJson("/api/v1/courriers/{$a->id}/dispatchs", [
            'destinations' => [['type' => 'classement', 'instruction' => 'Interdit']],
        ])->assertForbidden();
        $this->actingAs($delegataire)->postJson("/api/v1/courriers/{$d->id}/signer")->assertForbidden();
        $d->refresh();
        $this->assertNull($d->numero_depart);
        $this->assertNull($d->signe_at);
        $this->assertNull($d->signataire_id);
        $this->assertNull($d->pdf_chemin);
        $this->assertNull($d->pdf_sha256);
        $this->assertSame(0, $d->transitions()->where('destinataire_poste', Poste::SECRETARIAT_2->value)->count());
    }
}
