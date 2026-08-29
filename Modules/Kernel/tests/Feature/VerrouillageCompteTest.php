<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Kernel\Models\User;
use Tests\TestCase;

/**
 * Verrouillage par compte (echecs_connexion_consecutifs/verrouille_jusqu_a),
 * distinct du limiteur de débit 'auth' (par IP/e-mail, fenêtre glissante
 * d'une minute — voir AppServiceProvider) : chaque test réinitialise ce
 * limiteur entre les tentatives pour isoler le mécanisme testé ici, sans
 * quoi les deux se déclencheraient au même seuil (cinq) et masqueraient
 * l'un l'autre.
 */
class VerrouillageCompteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le limiteur 'auth' hache ses clés en interne (voir
     * ThrottleRequests::$shouldHashKeys) : les effacer une à une par leur
     * valeur brute ne fonctionne pas. Vider tout le cache entre chaque
     * tentative est plus simple et sans risque ici (CACHE_STORE=array en
     * test, propre à ce processus).
     */
    private function contournerLeLimiteurDeDebit(): void
    {
        Cache::flush();
    }

    public function test_cinq_echecs_consecutifs_verrouillent_le_compte(): void
    {
        $utilisateur = User::factory()->create(['email' => 'agent@ont.cd', 'password' => 'BonMotDePasse#12']);

        for ($i = 0; $i < 5; $i++) {
            $this->contournerLeLimiteurDeDebit();
            $this->postJson('/api/v1/auth/login', ['email' => 'agent@ont.cd', 'password' => 'mauvais'])
                ->assertStatus(422);
        }

        $this->assertNotNull($utilisateur->fresh()->verrouille_jusqu_a);

        // Même avec le bon mot de passe, le compte reste refusé tant qu'il
        // est verrouillé.
        $this->contournerLeLimiteurDeDebit();
        $this->postJson('/api/v1/auth/login', ['email' => 'agent@ont.cd', 'password' => 'BonMotDePasse#12'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Ce compte est temporairement verrouillé après plusieurs échecs de connexion. Réessayez dans quelques minutes.');
    }

    public function test_moins_de_cinq_echecs_ne_verrouille_pas_et_un_succes_reinitialise_le_compteur(): void
    {
        $utilisateur = User::factory()->create(['email' => 'agent@ont.cd', 'password' => 'BonMotDePasse#12']);

        for ($i = 0; $i < 3; $i++) {
            $this->contournerLeLimiteurDeDebit();
            $this->postJson('/api/v1/auth/login', ['email' => 'agent@ont.cd', 'password' => 'mauvais'])
                ->assertStatus(422);
        }

        $this->assertSame(3, $utilisateur->fresh()->echecs_connexion_consecutifs);

        $this->contournerLeLimiteurDeDebit();
        $this->postJson('/api/v1/auth/login', ['email' => 'agent@ont.cd', 'password' => 'BonMotDePasse#12'])
            ->assertOk();

        $utilisateur->refresh();
        $this->assertSame(0, $utilisateur->echecs_connexion_consecutifs);
        $this->assertNull($utilisateur->verrouille_jusqu_a);
    }

    public function test_le_verrouillage_expire_automatiquement(): void
    {
        $utilisateur = User::factory()->create([
            'email' => 'agent@ont.cd',
            'password' => 'BonMotDePasse#12',
            'echecs_connexion_consecutifs' => 5,
            'verrouille_jusqu_a' => now()->subMinute(),
        ]);

        $this->contournerLeLimiteurDeDebit();
        $this->postJson('/api/v1/auth/login', ['email' => 'agent@ont.cd', 'password' => 'BonMotDePasse#12'])
            ->assertOk();

        $this->assertSame(0, $utilisateur->fresh()->echecs_connexion_consecutifs);
    }

    public function test_ladministrateur_peut_deverrouiller_manuellement_un_compte(): void
    {
        $administrateur = User::factory()->administrateur()->create();
        $utilisateur = User::factory()->create([
            'echecs_connexion_consecutifs' => 5,
            'verrouille_jusqu_a' => now()->addMinutes(15),
        ]);

        $this->actingAs($administrateur)
            ->postJson("/api/v1/users/{$utilisateur->id}/deverrouiller")
            ->assertOk();

        $utilisateur->refresh();
        $this->assertSame(0, $utilisateur->echecs_connexion_consecutifs);
        $this->assertNull($utilisateur->verrouille_jusqu_a);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.compte_deverrouille_manuellement',
            'auditable_id' => $utilisateur->id,
        ]);
    }

    public function test_un_responsable_de_direction_ne_peut_pas_deverrouiller_un_compte(): void
    {
        $responsable = User::factory()->responsableDirection()->create();
        $utilisateur = User::factory()->create(['verrouille_jusqu_a' => now()->addMinutes(15)]);

        $this->actingAs($responsable)
            ->postJson("/api/v1/users/{$utilisateur->id}/deverrouiller")
            ->assertForbidden();
    }
}
