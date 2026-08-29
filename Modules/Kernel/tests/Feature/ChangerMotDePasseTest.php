<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class ChangerMotDePasseTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_utilisateur_peut_changer_son_propre_mot_de_passe(): void
    {
        $utilisateur = User::factory()->create(['password' => 'AncienMotDePasse#12']);
        $token = $utilisateur->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/mot-de-passe/changer', [
                'ancien_mot_de_passe' => 'AncienMotDePasse#12',
                'mot_de_passe' => 'Xk9mQprT4vLw#26',
                'mot_de_passe_confirmation' => 'Xk9mQprT4vLw#26',
            ])
            ->assertOk();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Xk9mQprT4vLw#26', $utilisateur->fresh()->password));
    }

    public function test_lancien_mot_de_passe_incorrect_est_rejete(): void
    {
        $utilisateur = User::factory()->create(['password' => 'AncienMotDePasse#12']);
        $token = $utilisateur->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/mot-de-passe/changer', [
                'ancien_mot_de_passe' => 'MauvaisMotDePasse',
                'mot_de_passe' => 'Xk9mQprT4vLw#26',
                'mot_de_passe_confirmation' => 'Xk9mQprT4vLw#26',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ancien_mot_de_passe']);
    }

    public function test_un_mot_de_passe_trop_court_est_rejete(): void
    {
        $utilisateur = User::factory()->create(['password' => 'AncienMotDePasse#12']);
        $token = $utilisateur->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/mot-de-passe/changer', [
                'ancien_mot_de_passe' => 'AncienMotDePasse#12',
                'mot_de_passe' => 'Court1A',
                'mot_de_passe_confirmation' => 'Court1A',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mot_de_passe']);
    }

    public function test_le_changement_revoque_les_autres_jetons_mais_garde_le_jeton_courant(): void
    {
        $utilisateur = User::factory()->create(['password' => 'AncienMotDePasse#12']);
        $tokenCourant = $utilisateur->createToken('session-actuelle')->plainTextToken;
        $utilisateur->createToken('autre-appareil');

        $this->assertCount(2, $utilisateur->tokens);

        $this->withHeader('Authorization', "Bearer {$tokenCourant}")
            ->postJson('/api/v1/auth/mot-de-passe/changer', [
                'ancien_mot_de_passe' => 'AncienMotDePasse#12',
                'mot_de_passe' => 'Xk9mQprT4vLw#26',
                'mot_de_passe_confirmation' => 'Xk9mQprT4vLw#26',
            ])
            ->assertOk();

        $this->assertCount(1, $utilisateur->fresh()->tokens);

        // Le jeton courant reste utilisable juste après le changement.
        $this->withHeader('Authorization', "Bearer {$tokenCourant}")
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_le_changement_leve_lobligation_de_changer_le_mot_de_passe(): void
    {
        $utilisateur = User::factory()->create([
            'password' => 'AncienMotDePasse#12',
            'doit_changer_mot_de_passe' => true,
        ]);
        $token = $utilisateur->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/mot-de-passe/changer', [
                'ancien_mot_de_passe' => 'AncienMotDePasse#12',
                'mot_de_passe' => 'Xk9mQprT4vLw#26',
                'mot_de_passe_confirmation' => 'Xk9mQprT4vLw#26',
            ])
            ->assertOk();

        $this->assertFalse($utilisateur->fresh()->doit_changer_mot_de_passe);
    }
}
