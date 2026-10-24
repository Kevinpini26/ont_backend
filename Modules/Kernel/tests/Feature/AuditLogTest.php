<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_connexion_reussie_est_journalisee(): void
    {
        $user = User::factory()->administrateur()->create([
            'email' => 'admin@ont.cd',
            'password' => 'password123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@ont.cd',
            'password' => 'password123',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.connexion',
            'user_id' => $user->id,
        ]);
    }

    public function test_une_tentative_de_connexion_echouee_est_journalisee(): void
    {
        User::factory()->administrateur()->create([
            'email' => 'admin@ont.cd',
            'password' => 'password123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@ont.cd',
            'password' => 'mauvais-mot-de-passe',
        ])->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.echec_connexion',
        ]);
    }

    public function test_seul_ladministrateur_peut_consulter_le_journal_daudit(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($responsable)
            ->getJson('/api/v1/audit-logs')
            ->assertStatus(403);

        $this->actingAs($admin)
            ->getJson('/api/v1/audit-logs')
            ->assertOk();
    }

    public function test_un_administrateur_peut_revoquer_les_jetons_dun_compte(): void
    {
        $admin = User::factory()->administrateur()->create();
        $cible = User::factory()->administrateur()->create();
        $cible->createToken('appareil-perdu');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/users/{$cible->id}/tokens")
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_le_journal_filtre_par_objet_et_par_recherche_libre(): void
    {
        $admin = User::factory()->administrateur()->create();
        $cible = User::factory()->agentDfp()->create();
        AuditLog::query()->create([
            'action' => 'user.desactive', 'user_id' => $admin->id,
            'auditable_type' => $cible->getMorphClass(), 'auditable_id' => $cible->id,
            'description' => 'Compte désactivé pour départ.',
        ]);
        AuditLog::query()->create(['action' => 'auth.deconnexion', 'user_id' => $admin->id]);

        $this->actingAs($admin)
            ->getJson("/api/v1/audit-logs?sujet_type={$cible->getMorphClass()}&sujet_id={$cible->id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'user.desactive');

        $this->actingAs($admin)
            ->getJson('/api/v1/audit-logs?recherche=départ')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'user.desactive');
    }

    public function test_le_journal_filtre_par_periode_depuis_et_jusqu_a(): void
    {
        $admin = User::factory()->administrateur()->create();
        AuditLog::query()->create(['action' => 'ancien.evenement', 'created_at' => now()->subDays(10)]);
        AuditLog::query()->create(['action' => 'evenement.recent', 'created_at' => now()]);

        $this->actingAs($admin)
            ->getJson('/api/v1/audit-logs?depuis='.now()->subDay()->toDateString())
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'evenement.recent');

        $this->actingAs($admin)
            ->getJson('/api/v1/audit-logs?jusqu_a='.now()->subDays(5)->toDateString())
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'ancien.evenement');
    }

    public function test_aucune_route_ne_permet_de_modifier_ou_supprimer_une_entree_du_journal(): void
    {
        $admin = User::factory()->administrateur()->create();
        $log = AuditLog::query()->create(['action' => 'auth.connexion', 'user_id' => $admin->id]);

        $this->actingAs($admin)->putJson("/api/v1/audit-logs/{$log->id}", ['action' => 'modifie'])->assertStatus(404);
        $this->actingAs($admin)->deleteJson("/api/v1/audit-logs/{$log->id}")->assertStatus(404);
        $this->assertDatabaseHas('audit_logs', ['id' => $log->id, 'action' => 'auth.connexion']);
    }
}
