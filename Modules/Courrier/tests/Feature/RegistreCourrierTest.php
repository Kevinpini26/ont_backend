<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class RegistreCourrierTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_un_administrateur_peut_telecharger_le_registre_arrivee_dune_periode(): void
    {
        $admin = User::factory()->administrateur()->create();
        Courrier::factory()->count(3)->create(['created_at' => now()]);

        $response = $this->actingAs($admin)->get('/api/v1/courriers/registre?debut='.now()->subDay()->toDateString().'&fin='.now()->addDay()->toDateString());

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_un_registre_depart_est_genere_meme_vide_faute_de_courrier_sortant(): void
    {
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)
            ->get('/api/v1/courriers/registre?type=depart&debut=2026-01-01&fin=2026-01-31')
            ->assertOk();
    }

    public function test_un_responsable_de_direction_ne_peut_pas_telecharger_le_registre(): void
    {
        $responsable = User::factory()->responsableDirection(Direction::factory()->create())->create();

        $this->actingAs($responsable)
            ->get('/api/v1/courriers/registre?debut=2026-01-01&fin=2026-01-31')
            ->assertForbidden();
    }

    public function test_une_periode_invalide_est_rejetee(): void
    {
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)
            ->get('/api/v1/courriers/registre?debut=2026-01-31&fin=2026-01-01')
            ->assertUnprocessable();
    }
}
