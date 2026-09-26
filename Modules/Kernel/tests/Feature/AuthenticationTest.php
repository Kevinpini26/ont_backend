<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->administrateur()->create([
            'email' => 'admin@ont.cd',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@ont.cd',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['user', 'token', 'expires_at']);

        // Le frontend programme l'avertissement de fin de session dessus
        // (voir useSessionExpiryWatcher) : doit être dans le futur.
        $this->assertTrue(Carbon::parse($response->json('expires_at'))->isFuture());
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::factory()->administrateur()->create([
            'email' => 'admin@ont.cd',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@ont.cd',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
    }

    public function test_an_authenticated_user_can_fetch_its_own_profile(): void
    {
        $direction = Direction::factory()->create();
        $user = User::factory()->responsableDirection($direction)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/auth/me');

        $response->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_a_token_stops_working_after_logout(): void
    {
        $user = User::factory()->administrateur()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Le guard Sanctum met en cache l'utilisateur résolu pour la durée
        // de la requête ; comme deux appels HTTP sont simulés dans le même
        // process de test, on force son oubli pour rejouer une résolution
        // à partir du jeton (désormais supprimé) comme le ferait une vraie
        // requête HTTP indépendante.
        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_only_administrateur_can_manage_users(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        $this->actingAs($responsable)
            ->getJson('/api/v1/users')
            ->assertStatus(403);

        $this->actingAs($responsable)
            ->postJson('/api/v1/directions', ['code' => 'ZZZ', 'nom' => 'Zone Z'])
            ->assertStatus(403);
    }

    public function test_any_authenticated_user_can_list_directions(): void
    {
        $attendu = Direction::query()->count() + 3;
        Direction::factory()->count(3)->create();
        $user = User::factory()->administrateur()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/directions')
            ->assertOk()
            ->assertJsonCount($attendu, 'data');
    }
}
