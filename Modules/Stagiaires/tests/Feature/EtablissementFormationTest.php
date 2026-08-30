<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Models\EtablissementFormation;

class EtablissementFormationTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_tout_utilisateur_authentifie_peut_lister_le_referentiel(): void
    {
        $agent = User::factory()->agentDfp()->create();
        EtablissementFormation::factory()->create(['nom' => 'Université de Kinshasa', 'actif' => true]);
        EtablissementFormation::factory()->create(['nom' => 'Établissement fermé', 'actif' => false]);

        $reponse = $this->actingAs($agent)->getJson('/api/v1/etablissements-formation')->assertOk();

        $reponse->assertJsonCount(1, 'data');
        $reponse->assertJsonPath('data.0.nom', 'Université de Kinshasa');
    }

    public function test_la_dfp_peut_ajouter_un_etablissement_au_referentiel(): void
    {
        $dfp = User::factory()->agentDfp()->create();

        $this->actingAs($dfp)->postJson('/api/v1/etablissements-formation', [
            'nom' => 'Institut Supérieur de Commerce',
            'ville' => 'Lubumbashi',
        ])->assertCreated();

        $this->assertDatabaseHas('etablissements_formation', [
            'nom' => 'Institut Supérieur de Commerce',
            'ville' => 'Lubumbashi',
        ]);
    }

    public function test_une_direction_daccueil_ne_peut_pas_ajouter_un_etablissement(): void
    {
        $responsable = User::factory()->responsableDirection()->create();

        $this->actingAs($responsable)->postJson('/api/v1/etablissements-formation', [
            'nom' => 'Institut quelconque',
        ])->assertForbidden();
    }
}
