<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class DoitChangerMotDePasseTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_compte_fraichement_cree_par_ladministrateur_doit_changer_son_mot_de_passe(): void
    {
        $administrateur = User::factory()->administrateur()->create();

        $reponse = $this->actingAs($administrateur)->postJson('/api/v1/users', [
            'name' => 'Nouvel Agent',
            'email' => 'nouvel.agent@ont.cd',
            'password' => 'Xk9mQprT4vLw#26',
            'role' => 'agent_dfp',
        ])->assertCreated();

        $this->assertTrue(User::query()->find($reponse->json('data.id'))->doit_changer_mot_de_passe);
    }

    public function test_lapi_est_bloquee_tant_que_le_mot_de_passe_nest_pas_change(): void
    {
        $utilisateur = User::factory()->agentDfp()->create(['doit_changer_mot_de_passe' => true]);
        $token = $utilisateur->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/notifications')
            ->assertStatus(423)
            ->assertJsonPath('code', 'doit_changer_mot_de_passe');
    }

    public function test_se_deconnecter_consulter_son_profil_et_changer_son_mot_de_passe_restent_accessibles(): void
    {
        $utilisateur = User::factory()->create([
            'password' => 'AncienMotDePasse#12',
            'doit_changer_mot_de_passe' => true,
        ]);
        $token = $utilisateur->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/auth/me')->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/mot-de-passe/changer', [
                'ancien_mot_de_passe' => 'AncienMotDePasse#12',
                'mot_de_passe' => 'Xk9mQprT4vLw#26',
                'mot_de_passe_confirmation' => 'Xk9mQprT4vLw#26',
            ])
            ->assertOk();

        // Une fois changé, l'API redevient accessible normalement.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/notifications')
            ->assertOk();
    }

    public function test_un_compte_a_jour_nest_jamais_bloque(): void
    {
        $utilisateur = User::factory()->agentDfp()->create(['doit_changer_mot_de_passe' => false]);
        $token = $utilisateur->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/notifications')
            ->assertOk();
    }
}
