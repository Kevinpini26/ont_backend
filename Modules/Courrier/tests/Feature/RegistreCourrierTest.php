<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\PdfGenerationService;
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

    /** @return array<string, mixed> données transmises à la vue du registre */
    private function capturerRegistreArrivee(): array
    {
        $capture = new class implements PdfGenerationService
        {
            public array $donnees = [];

            public function genererDepuisVue(string $vue, array $donnees = []): string
            {
                $this->donnees = $donnees;

                return '%PDF-1.4';
            }
        };
        $this->app->instance(PdfGenerationService::class, $capture);

        $this->actingAs(User::factory()->administrateur()->create())
            ->get('/api/v1/courriers/registre?debut='.now()->subDay()->toDateString().'&fin='.now()->addDay()->toDateString())
            ->assertOk();

        return $capture->donnees;
    }

    public function test_un_courrier_du_guichet_sans_accuse_en_ligne_figure_au_registre_arrivee(): void
    {
        $guichet = Courrier::factory()->create([
            'numero_accuse_reception' => null,
            'numero_enregistrement' => now()->year.'-0001',
            'enregistre_at' => now(),
        ]);

        $ids = $this->capturerRegistreArrivee()['courriers']->pluck('id')->all();

        $this->assertContains($guichet->id, $ids);
    }

    public function test_un_depot_en_ligne_pas_encore_enregistre_ne_figure_pas_au_registre_arrivee(): void
    {
        $enAttente = Courrier::factory()->create([
            'numero_accuse_reception' => 'AR-'.now()->year.'-000009',
            'numero_enregistrement' => null,
            'enregistre_at' => null,
        ]);
        $enregistre = Courrier::factory()->create([
            'numero_accuse_reception' => 'AR-'.now()->year.'-000010',
            'numero_enregistrement' => now()->year.'-0002',
            'enregistre_at' => now(),
        ]);

        $ids = $this->capturerRegistreArrivee()['courriers']->pluck('id')->all();

        $this->assertNotContains($enAttente->id, $ids);
        $this->assertContains($enregistre->id, $ids);
    }
}
