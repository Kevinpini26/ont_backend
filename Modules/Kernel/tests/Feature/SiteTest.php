<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\Site;
use Modules\Kernel\Models\User;
use Tests\TestCase;

/**
 * Préparation multi-site (représentations provinciales) — voir la
 * migration create_sites_table.
 */
class SiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_siege_de_kinshasa_existe_par_defaut(): void
    {
        $this->assertDatabaseHas('sites', ['nom' => 'Siège — Kinshasa', 'siege' => true]);
    }

    public function test_les_directions_existantes_sont_rattachees_au_siege_par_defaut(): void
    {
        $direction = Direction::factory()->create();

        $siege = Site::query()->where('siege', true)->firstOrFail();
        $this->assertSame($siege->id, $direction->fresh()->site_id);
    }

    public function test_tout_utilisateur_authentifie_peut_lister_les_sites(): void
    {
        $agent = User::factory()->agentDfp()->create();
        Site::factory()->create(['nom' => 'Représentation du Kongo-Central']);

        $reponse = $this->actingAs($agent)->getJson('/api/v1/sites')->assertOk();

        $this->assertGreaterThanOrEqual(2, count($reponse->json('data')));
    }

    public function test_seul_ladministrateur_peut_creer_un_site(): void
    {
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->postJson('/api/v1/sites', [
            'nom' => 'Représentation du Nord-Kivu',
            'ville' => 'Goma',
            'province' => 'Nord-Kivu',
        ])->assertCreated();

        $this->assertDatabaseHas('sites', ['nom' => 'Représentation du Nord-Kivu', 'ville' => 'Goma']);
    }

    public function test_un_agent_dfp_ne_peut_pas_creer_un_site(): void
    {
        $agent = User::factory()->agentDfp()->create();

        $this->actingAs($agent)->postJson('/api/v1/sites', [
            'nom' => 'Représentation quelconque',
            'ville' => 'Kisangani',
        ])->assertForbidden();
    }

    public function test_une_direction_peut_etre_rattachee_a_un_nouveau_site(): void
    {
        $admin = User::factory()->administrateur()->create();
        $site = Site::factory()->create();
        $direction = Direction::factory()->create();

        $this->actingAs($admin)
            ->putJson("/api/v1/directions/{$direction->id}", ['site_id' => $site->id])
            ->assertOk()
            ->assertJsonPath('data.site_id', $site->id);
    }
}
