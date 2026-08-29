<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
