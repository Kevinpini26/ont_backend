<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\Setting;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_parametre_jamais_modifie_retourne_sa_valeur_par_defaut(): void
    {
        $this->assertSame('repli', Setting::valeur('inconnu.jamais.defini', 'repli'));
    }

    public function test_definir_un_parametre_le_rend_immediatement_lisible(): void
    {
        $admin = User::factory()->administrateur()->create();

        Setting::definir('organisation.nom', 'Nouveau Nom ONT', $admin);

        $this->assertSame('Nouveau Nom ONT', Setting::valeur('organisation.nom', 'défaut'));
        $this->assertSame($admin->id, Setting::query()->find('organisation.nom')->modifie_par_id);
    }

    public function test_un_non_administrateur_ne_peut_pas_consulter_les_parametres(): void
    {
        $responsable = User::factory()->responsableDirection(Direction::factory()->create())->create();

        $this->actingAs($responsable)->getJson('/api/v1/parametres')->assertStatus(403);
    }

    public function test_la_liste_des_parametres_expose_valeur_et_defaut(): void
    {
        $admin = User::factory()->administrateur()->create();

        $reponse = $this->actingAs($admin)->getJson('/api/v1/parametres')->assertOk();

        $reponse->assertJsonFragment(['cle' => 'organisation.nom', 'valeur' => 'Office National du Tourisme']);
    }

    public function test_modifier_un_parametre_le_persiste_et_laudite(): void
    {
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->putJson('/api/v1/parametres/organisation.nom', ['valeur' => 'ONT Renommé'])
            ->assertOk()->assertJsonPath('valeur', 'ONT Renommé');

        $this->assertSame('ONT Renommé', Setting::valeur('organisation.nom'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'parametre.modifie',
            'user_id' => $admin->id,
        ]);
    }

    public function test_une_cle_de_parametre_inconnue_est_refusee(): void
    {
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->putJson('/api/v1/parametres/inconnu.jamais.defini', ['valeur' => 'x'])
            ->assertStatus(404);
    }

    public function test_un_parametre_entier_refuse_une_valeur_non_numerique(): void
    {
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->putJson('/api/v1/parametres/courrier.lien_ttl_minutes', ['valeur' => 'pas-un-nombre'])
            ->assertUnprocessable()->assertJsonValidationErrors('valeur');
    }

    public function test_le_signataire_par_defaut_doit_etre_un_utilisateur_existant(): void
    {
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->putJson('/api/v1/parametres/organisation.signataire_defaut_id', ['valeur' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('valeur');

        $this->actingAs($admin)->putJson('/api/v1/parametres/organisation.signataire_defaut_id', ['valeur' => $admin->id])
            ->assertOk();
    }
}
