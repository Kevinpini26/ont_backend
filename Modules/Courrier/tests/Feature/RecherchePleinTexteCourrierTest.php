<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class RecherchePleinTexteCourrierTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_recherche_retrouve_un_courrier_par_un_mot_de_lobjet(): void
    {
        $admin = User::factory()->administrateur()->create();
        $ciblee = Courrier::factory()->create(['objet' => 'Demande de financement pour la formation des agents']);
        Courrier::factory()->create(['objet' => 'Invitation à la cérémonie de clôture']);

        $reponse = $this->actingAs($admin)->getJson('/api/v1/courriers?recherche=financement')->assertOk();

        $reponse->assertJsonCount(1, 'data');
        $reponse->assertJsonPath('data.0.id', $ciblee->id);
    }

    public function test_la_recherche_supporte_la_racinisation_francaise(): void
    {
        $admin = User::factory()->administrateur()->create();
        $ciblee = Courrier::factory()->create(['objet' => 'Formations organisées au profit des directions']);

        // "formation" (singulier) doit retrouver "Formations" (pluriel) :
        // c'est la racinisation française du tsvector qui fait ce travail,
        // pas un ILIKE littéral.
        $this->actingAs($admin)->getJson('/api/v1/courriers?recherche=formation')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ciblee->id);
    }

    public function test_un_numero_exact_est_priorise_devant_une_meilleure_pertinence_textuelle(): void
    {
        $admin = User::factory()->administrateur()->create();
        // Correspondance textuelle plus dense sur "rapport", mais pas de
        // numéro exact.
        $meilleurTexte = Courrier::factory()->create([
            'objet' => 'Rapport rapport rapport annuel',
            'numero_enregistrement' => '2026-E9999',
        ]);
        // Correspondance textuelle plus faible ("rapport" une seule fois),
        // mais c'est SON numéro exact qui est recherché : doit sortir en
        // tête malgré un score ts_rank plus bas.
        $numeroExact = Courrier::factory()->create([
            'objet' => 'Transmission du rapport de mission',
            'numero_enregistrement' => '2026-E0001',
        ]);

        $reponse = $this->actingAs($admin)->getJson('/api/v1/courriers?recherche=2026-E0001')->assertOk();

        $reponse->assertJsonPath('data.0.id', $numeroExact->id);
        $this->assertNotSame($meilleurTexte->id, $reponse->json('data.0.id'));
    }

    public function test_la_recherche_porte_aussi_sur_le_nom_du_candidat(): void
    {
        $admin = User::factory()->administrateur()->create();
        $ciblee = Courrier::factory()->demandeStage()->create(['candidat_nom' => 'Marie-Josée Kabongo']);
        Courrier::factory()->create(['objet' => 'Sans rapport avec la recherche']);

        $this->actingAs($admin)->getJson('/api/v1/courriers?recherche=Kabongo')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ciblee->id)
            ->assertJsonCount(1, 'data');
    }

    public function test_un_terme_absent_ne_retourne_aucun_resultat(): void
    {
        $admin = User::factory()->administrateur()->create();
        Courrier::factory()->create(['objet' => 'Courrier ordinaire quelconque']);

        $this->actingAs($admin)->getJson('/api/v1/courriers?recherche=inexistantxyz')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
