<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Kernel\Notifications\ReinitialisationMotDePasseNotification;
use Tests\TestCase;

class AdminGestionUtilisateursTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_creation_dun_compte_envoie_une_invitation_sans_mot_de_passe_choisi_par_ladministrateur(): void
    {
        Notification::fake();
        $admin = User::factory()->administrateur()->create();

        $reponse = $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Nouvel agent',
            'email' => 'nouvel.agent@ont.test',
            'role' => UserRole::AGENT_DFP->value,
        ])->assertCreated();

        $user = User::query()->findOrFail($reponse->json('data.id'));
        $this->assertTrue($user->doit_changer_mot_de_passe);
        $this->assertFalse(Hash::check('password', $user->password));
        Notification::assertSentTo($user, ReinitialisationMotDePasseNotification::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.cree', 'user_id' => $admin->id]);
    }

    public function test_un_second_titulaire_actif_sur_un_poste_unique_est_refuse_a_la_creation(): void
    {
        $direction = Direction::factory()->create();
        User::factory()->agentCircuitCourrier(Poste::DG, $direction)->create();
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Second DG',
            'email' => 'second.dg@ont.test',
            'role' => UserRole::AGENT_CIRCUIT_COURRIER->value,
            'poste' => Poste::DG->value,
            'direction_id' => $direction->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('poste');
    }

    public function test_un_poste_non_unique_tolere_plusieurs_titulaires_actifs(): void
    {
        $direction = Direction::factory()->create();
        User::factory()->agentCircuitCourrier(Poste::ASSISTANT_1, $direction)->create();
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Second assistant',
            'email' => 'second.assistant@ont.test',
            'role' => UserRole::AGENT_CIRCUIT_COURRIER->value,
            'poste' => Poste::ASSISTANT_1->value,
            'direction_id' => $direction->id,
        ])->assertCreated();
    }

    public function test_affecter_un_poste_unique_deja_occupe_par_mise_a_jour_est_refuse(): void
    {
        $direction = Direction::factory()->create();
        User::factory()->agentCircuitCourrier(Poste::RECEPTION, $direction)->create();
        $assistant = User::factory()->agentCircuitCourrier(Poste::ASSISTANT_1, $direction)->create();
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->putJson("/api/v1/users/{$assistant->id}", [
            'poste' => Poste::RECEPTION->value,
        ])->assertUnprocessable()->assertJsonValidationErrors('poste');
    }

    public function test_la_suppression_est_refusee_si_le_compte_a_une_trace_dans_le_journal_daudit(): void
    {
        $admin = User::factory()->administrateur()->create();
        $cible = User::factory()->agentDfp()->create();
        AuditLog::query()->create(['action' => 'auth.connexion', 'user_id' => $cible->id]);

        $this->actingAs($admin)->deleteJson("/api/v1/users/{$cible->id}")->assertUnprocessable();
        $this->assertDatabaseHas('users', ['id' => $cible->id]);
    }

    /**
     * Trouvé par un test manuel en direct : un compte géré uniquement par
     * un administrateur (jamais connecté lui-même) a sa trace d'audit
     * sous auditable_id (il est la cible, pas l'auteur) — se limiter à
     * user_id laissait ce cas passer à travers la garde.
     */
    public function test_la_suppression_est_refusee_si_seul_un_tiers_a_agi_sur_ce_compte(): void
    {
        $admin = User::factory()->administrateur()->create();
        $cible = User::factory()->agentDfp()->create();
        $this->actingAs($admin)->postJson("/api/v1/users/{$cible->id}/desactiver")->assertOk();

        $this->actingAs($admin)->deleteJson("/api/v1/users/{$cible->id}")->assertUnprocessable();
        $this->assertDatabaseHas('users', ['id' => $cible->id]);
    }

    public function test_la_suppression_reste_possible_pour_un_compte_sans_trace_metier(): void
    {
        $admin = User::factory()->administrateur()->create();
        $cible = User::factory()->agentDfp()->create();

        $this->actingAs($admin)->deleteJson("/api/v1/users/{$cible->id}")->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $cible->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.supprime', 'user_id' => $admin->id]);
    }

    public function test_desactiver_un_compte_bloque_sa_connexion_et_revoque_ses_jetons(): void
    {
        $admin = User::factory()->administrateur()->create();
        $cible = User::factory()->agentDfp()->create(['password' => 'password123']);
        $jeton = $cible->createToken('api')->plainTextToken;

        $this->actingAs($admin)->postJson("/api/v1/users/{$cible->id}/desactiver")->assertOk();

        $this->assertFalse($cible->fresh()->actif);
        $this->assertSame(0, $cible->fresh()->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.desactive', 'user_id' => $admin->id]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $cible->email,
            'password' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_un_administrateur_ne_peut_pas_se_desactiver_lui_meme(): void
    {
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->postJson("/api/v1/users/{$admin->id}/desactiver")->assertUnprocessable();
        $this->assertTrue($admin->fresh()->actif);
    }

    public function test_reactiver_un_compte_permet_de_nouveau_la_connexion(): void
    {
        $admin = User::factory()->administrateur()->create();
        $cible = User::factory()->agentDfp()->create(['actif' => false]);

        $this->actingAs($admin)->postJson("/api/v1/users/{$cible->id}/reactiver")->assertOk();

        $this->assertTrue($cible->fresh()->actif);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.reactive', 'user_id' => $admin->id]);
    }

    public function test_reactiver_un_compte_sur_un_poste_unique_deja_occupe_est_refuse(): void
    {
        $direction = Direction::factory()->create();
        User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_1, $direction)->create();
        $ancienTitulaire = User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_1, $direction)->create(['actif' => false]);
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->postJson("/api/v1/users/{$ancienTitulaire->id}/reactiver")->assertUnprocessable();
    }

    public function test_la_passation_de_poste_desactive_lancien_titulaire_et_active_le_nouveau(): void
    {
        $direction = Direction::factory()->create();
        $ancienDg = User::factory()->agentCircuitCourrier(Poste::DG, $direction)->create();
        $jeton = $ancienDg->createToken('api')->plainTextToken;
        $nouveauTitulaire = User::factory()->agentCircuitCourrier(Poste::ASSISTANT_1, $direction)->create(['actif' => true]);
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->postJson('/api/v1/postes-uniques/passation', [
            'poste' => Poste::DG->value,
            'nouveau_titulaire_id' => $nouveauTitulaire->id,
        ])->assertOk();

        $this->assertFalse($ancienDg->fresh()->actif);
        $this->assertSame(0, $ancienDg->fresh()->tokens()->count());
        $nouveauTitulaireFrais = $nouveauTitulaire->fresh();
        $this->assertTrue($nouveauTitulaireFrais->actif);
        $this->assertSame(Poste::DG, $nouveauTitulaireFrais->poste);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.poste_passation',
            'auditable_id' => $nouveauTitulaire->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_la_passation_de_poste_sans_titulaire_actif_actuel_active_simplement_le_nouveau(): void
    {
        $direction = Direction::factory()->create();
        $nouveauTitulaire = User::factory()->agentCircuitCourrier(Poste::ASSISTANT_1, $direction)->create();
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->postJson('/api/v1/postes-uniques/passation', [
            'poste' => Poste::RECEPTION->value,
            'nouveau_titulaire_id' => $nouveauTitulaire->id,
        ])->assertOk();

        $this->assertSame(Poste::RECEPTION, $nouveauTitulaire->fresh()->poste);
    }

    public function test_un_poste_non_unique_est_refuse_pour_la_passation(): void
    {
        $direction = Direction::factory()->create();
        $nouveauTitulaire = User::factory()->agentCircuitCourrier(Poste::ASSISTANT_2, $direction)->create();
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->postJson('/api/v1/postes-uniques/passation', [
            'poste' => Poste::ASSISTANT_1->value,
            'nouveau_titulaire_id' => $nouveauTitulaire->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('poste');
    }

    public function test_la_fiche_utilisateur_expose_la_derniere_connexion_et_les_sessions_actives(): void
    {
        $admin = User::factory()->administrateur()->create();
        $cible = User::factory()->agentDfp()->create();
        AuditLog::query()->create(['action' => 'auth.connexion', 'user_id' => $cible->id, 'created_at' => now()->subDay()]);
        $cible->createToken('poste de travail');

        $reponse = $this->actingAs($admin)->getJson("/api/v1/users/{$cible->id}")->assertOk();

        $this->assertNotNull($reponse->json('derniere_connexion_at'));
        $this->assertCount(1, $reponse->json('sessions_actives'));
        $this->assertSame('poste de travail', $reponse->json('sessions_actives.0.nom'));
    }

    public function test_une_session_individuelle_peut_etre_revoquee_sans_toucher_les_autres(): void
    {
        $admin = User::factory()->administrateur()->create();
        $cible = User::factory()->agentDfp()->create();
        $jetonA = $cible->createToken('poste A');
        $jetonB = $cible->createToken('poste B');

        $this->actingAs($admin)->deleteJson("/api/v1/users/{$cible->id}/tokens/{$jetonA->accessToken->id}")->assertOk();

        $this->assertSame(1, $cible->tokens()->count());
        $this->assertSame($jetonB->accessToken->id, $cible->tokens()->first()->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.jeton_revoque', 'user_id' => $admin->id]);
    }

    public function test_la_liste_des_utilisateurs_filtre_par_role_poste_direction_actif_et_recherche(): void
    {
        $direction = Direction::factory()->create();
        User::factory()->agentCircuitCourrier(Poste::RECEPTION, $direction)->create(['name' => 'Jean Réception']);
        User::factory()->agentDfp()->create(['name' => 'Alice DFP', 'actif' => false]);
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->getJson('/api/v1/users?role=agent_circuit_courrier')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($admin)->getJson('/api/v1/users?poste=reception')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($admin)->getJson("/api/v1/users?direction_id={$direction->id}")
            ->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($admin)->getJson('/api/v1/users?actif=0')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alice DFP');

        $this->actingAs($admin)->getJson('/api/v1/users?recherche=Jean')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Jean Réception');
    }

    public function test_la_liste_des_utilisateurs_pagine_reellement_au_dela_de_vingt_comptes(): void
    {
        User::factory()->count(25)->agentDfp()->create();
        $admin = User::factory()->administrateur()->create();

        $premierePage = $this->actingAs($admin)->getJson('/api/v1/users')->assertOk();
        $this->assertCount(20, $premierePage->json('data'));
        $this->assertSame(26, $premierePage->json('meta.total'));

        $secondePage = $this->actingAs($admin)->getJson('/api/v1/users?page=2')->assertOk();
        $this->assertCount(6, $secondePage->json('data'));
    }

    public function test_un_compte_desactive_ne_peut_plus_se_connecter(): void
    {
        $utilisateur = User::factory()->agentDfp()->create(['password' => 'password123', 'actif' => false]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $utilisateur->email,
            'password' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }
}
