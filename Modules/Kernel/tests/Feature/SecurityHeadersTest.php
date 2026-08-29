<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_entetes_de_securite_sont_presents_sur_une_reponse_normale(): void
    {
        $utilisateur = User::factory()->create();

        $response = $this->actingAs($utilisateur)->getJson('/api/v1/directions');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Content-Security-Policy');
    }

    public function test_les_entetes_sont_aussi_presents_sur_une_reponse_derreur(): void
    {
        // Aucun jeton : 401, converti par le gestionnaire d'exceptions
        // plutôt que retourné par le middleware normalement — voir
        // SecurityHeaders::appliquer() et bootstrap/app.php::withExceptions().
        $response = $this->getJson('/api/v1/directions');

        $response->assertStatus(401);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }
}
