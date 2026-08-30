<?php

namespace Modules\Public\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Models\Direction;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;
use Tests\TestCase;

class StatistiquesPubliquesTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_statistiques_publiques_sont_accessibles_sans_authentification(): void
    {
        $this->getJson('/api/v1/public/statistiques')->assertOk();
    }

    public function test_dossiers_traites_additionne_les_courriers_enregistres_et_les_stages_clotures(): void
    {
        Courrier::factory()->create(['statut' => CourrierStatut::ENREGISTRE]);
        Courrier::factory()->create(['statut' => CourrierStatut::ENREGISTRE]);
        Courrier::factory()->create(['statut' => CourrierStatut::AU_PROTOCOLE]);
        Stagiaire::factory()->create(['statut' => StagiaireStatut::CLOTURE]);
        Stagiaire::factory()->create(['statut' => StagiaireStatut::STAGE_EN_COURS]);

        $this->getJson('/api/v1/public/statistiques')
            ->assertOk()
            ->assertJsonPath('dossiers_traites', 3);
    }

    /**
     * Aucune donnée nominative (nom de candidat, de stagiaire, d'agent,
     * numéro de dossier) ne doit apparaître dans cette réponse — seuls les
     * trois agrégats globaux sont exposés.
     */
    public function test_la_reponse_ne_contient_aucune_donnee_nominative(): void
    {
        Courrier::factory()->create([
            'statut' => CourrierStatut::ENREGISTRE,
            'expediteur_externe_nom' => 'Agence Voyage Congo SARL',
            'numero_accuse_reception' => 'AR-2026-000099',
        ]);

        $response = $this->getJson('/api/v1/public/statistiques');

        $response->assertOk()
            ->assertExactJson([
                'dossiers_traites' => $response->json('dossiers_traites'),
                'delai_moyen_jours' => $response->json('delai_moyen_jours'),
                'directions_actives' => $response->json('directions_actives'),
            ]);
    }

    public function test_directions_actives_compte_toutes_les_directions(): void
    {
        Direction::query()->delete();
        Direction::factory()->count(3)->create();

        $this->getJson('/api/v1/public/statistiques')
            ->assertOk()
            ->assertJsonPath('directions_actives', 3);
    }
}
