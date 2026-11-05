<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class LimiteAppelsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('api');
    }

    public function test_un_utilisateur_connecte_peut_charger_un_ecran_charge_sans_erreur_429(): void
    {
        $user = User::factory()->administrateur()->create();

        for ($i = 0; $i < 120; $i++) {
            $this->actingAs($user)->getJson('/api/v1/auth/me')->assertOk();
        }
    }

    public function test_un_utilisateur_connecte_est_limite_au_dela_du_plafond_avec_un_message_clair(): void
    {
        $user = User::factory()->administrateur()->create();
        $plafond = (int) config('kernel.limite_api_connecte');

        for ($i = 0; $i < $plafond; $i++) {
            $this->actingAs($user)->getJson('/api/v1/auth/me')->assertOk();
        }

        $this->actingAs($user)->getJson('/api/v1/auth/me')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_un_visiteur_anonyme_garde_la_limite_stricte_de_60_par_minute(): void
    {
        $limite = RateLimiter::limiter('api')(request());

        $this->assertSame(60, $limite->maxAttempts);
    }
}
