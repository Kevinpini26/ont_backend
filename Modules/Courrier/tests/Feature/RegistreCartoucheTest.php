<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * Lot 6 : l'ONT n'a pas de Secrétaire Général (SEC1 et SEC2 tiennent ce rôle). Le cartouche de signature
 * du registre se règle dans « Paramètres de l'organisation », SEC2 par défaut.
 */
class RegistreCartoucheTest extends CourrierTestCase
{
    use RefreshDatabase;

    private ?object $capture = null;

    private function htmlRegistre(string $type): string
    {
        // Une seule capture par test : le générateur du registre garde le service PDF reçu au premier appel.
        $capture = $this->capture ??= new class implements PdfGenerationService
        {
            public string $html = '';

            public function genererDepuisVue(string $vue, array $donnees = []): string
            {
                $this->html = view($vue, $donnees)->render();

                return '%PDF-1.4';
            }
        };
        $this->app->instance(PdfGenerationService::class, $capture);
        $capture->html = '';

        $this->actingAs($this->agent(Poste::SECRETARIAT_2, Direction::factory()->create()))
            ->get("/api/v1/courriers/registre?type={$type}&debut=".now()->subDay()->toDateString().'&fin='.now()->addDay()->toDateString())
            ->assertOk();

        return $capture->html;
    }

    public function test_le_registre_nevoque_plus_le_secretaire_general_et_porte_sec2_par_defaut(): void
    {
        $html = $this->htmlRegistre('arrivee');

        $this->assertStringNotContainsString('Secrétaire Général', $html);
        $this->assertStringContainsString('Vu et certifié par : Le Secrétariat 02', $html);
    }

    public function test_ladministrateur_regle_le_cartouche_de_chaque_registre(): void
    {
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->putJson('/api/v1/parametres/registre.depart.signataire_poste', ['valeur' => 'dg'])->assertOk();
        $this->actingAs($admin)->putJson('/api/v1/parametres/registre.depart.signataire_intitule', ['valeur' => 'Le Directeur Général'])->assertOk();
        $this->actingAs($admin)->putJson('/api/v1/parametres/registre.depart.signataire_poste', ['valeur' => 'pas_un_poste'])->assertUnprocessable();

        $this->assertStringContainsString('Vu et certifié par : Le Directeur Général', $this->htmlRegistre('depart'));
        $this->assertStringContainsString('Vu et certifié par : Le Secrétariat 02', $this->htmlRegistre('arrivee'));
    }
}
