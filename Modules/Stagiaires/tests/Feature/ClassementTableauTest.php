<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Kernel\Services\DgInterimService;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Models\TableauRepartition;

/**
 * Lot D : classement retrouvable (cote individuelle par lettre et par
 * tableau, navigation bidirectionnelle) et scellement du feu vert
 * (empreinte, horodatage, auteur, mention d'intérim — jamais modifiable).
 */
class ClassementTableauTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_lapprobation_classe_chaque_lettre_retenue_ou_non_et_le_tableau_lui_meme(): void
    {
        $directionDfp = $this->directionDfp();
        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $reception = User::factory()->agentCircuitCourrier(Poste::RECEPTION, $direction)->create();
        $dg = User::factory()->agentCircuitCourrier(Poste::DG, $direction)->create();

        $retenu = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        $nonRetenu = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        $this->imputerADfp($retenu, $directionDfp);
        $this->imputerADfp($nonRetenu, $directionDfp);

        $idTableau = $this->actingAs($dfp)->postJson('/api/v1/tableaux-repartition', [
            'periode_debut' => now()->toDateString(),
            'periode_fin' => now()->addMonth()->toDateString(),
        ])->json('data.id');

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes", [
            'stagiaire_id' => $retenu->id,
            'direction_accueil_proposee_id' => $direction->id,
            'date_debut_proposee' => now()->addMonth()->toDateString(),
            'date_fin_proposee' => now()->addMonths(3)->toDateString(),
            'encadrant_pressenti' => 'x',
        ])->assertOk();

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes", [
            'stagiaire_id' => $nonRetenu->id,
            'direction_accueil_proposee_id' => $direction->id,
            'date_debut_proposee' => now()->addMonth()->toDateString(),
            'date_fin_proposee' => now()->addMonths(3)->toDateString(),
            'encadrant_pressenti' => 'x',
            'issue_proposee' => 'non_retenu',
            'motif_non_retenu' => 'places_epuisees',
        ])->assertOk();

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/soumettre")->assertOk();
        $this->actingAs($reception)->postJson("/api/v1/tableaux-repartition/{$idTableau}/representer-dg")->assertOk();

        $reponse = $this->actingAs($dg)
            ->postJson("/api/v1/tableaux-repartition/{$idTableau}/rendre-avis", ['approuve' => true])
            ->assertOk()
            ->json('data');

        $this->assertNotNull($reponse['cote_classement']);
        $this->assertNotNull($reponse['scellement']);
        $this->assertSame($dg->name, $reponse['scellement']['auteur']);
        $this->assertFalse($reponse['scellement']['mention_interim']);
        $this->assertSame(64, strlen($reponse['scellement']['pdf_sha256']));

        $tableau = TableauRepartition::query()->findOrFail($idTableau);
        $this->assertDatabaseHas('tableau_repartition_scellements', [
            'tableau_repartition_id' => $tableau->id,
            'pdf_sha256' => $reponse['scellement']['pdf_sha256'],
        ]);

        foreach ([$retenu, $nonRetenu] as $stagiaire) {
            $stagiaire->refresh()->load('courrier');
            $this->assertNotNull($stagiaire->courrier->cote_classement);
        }

        // Navigation bidirectionnelle : depuis la fiche stagiaire, on
        // retrouve la cote de la lettre ET celle du tableau qui a tranché.
        $ficheRetenu = $this->actingAs($dfp)->getJson("/api/v1/stagiaires/{$retenu->id}")->assertOk()->json('data');
        $this->assertSame($retenu->refresh()->courrier->cote_classement, $ficheRetenu['courrier_cote_classement']);
        $this->assertSame($tableau->cote_classement, $ficheRetenu['tableau_repartition']['cote_classement']);
    }

    public function test_le_scellement_porte_la_mention_dinterim_quand_la_dga_a_approuve(): void
    {
        $directionDfp = $this->directionDfp();
        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $reception = User::factory()->agentCircuitCourrier(Poste::RECEPTION, $direction)->create();
        $dga = User::factory()->agentCircuitCourrier(Poste::DGA, $direction)->create();
        $dg = User::factory()->agentCircuitCourrier(Poste::DG, $direction)->create();

        app(DgInterimService::class)->ouvrir($dg, $dga, 'Absence officielle.');

        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        $this->imputerADfp($stagiaire, $directionDfp);

        $idTableau = $this->actingAs($dfp)->postJson('/api/v1/tableaux-repartition', [
            'periode_debut' => now()->toDateString(),
            'periode_fin' => now()->addMonth()->toDateString(),
        ])->json('data.id');

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes", [
            'stagiaire_id' => $stagiaire->id,
            'direction_accueil_proposee_id' => $direction->id,
            'date_debut_proposee' => now()->addMonth()->toDateString(),
            'date_fin_proposee' => now()->addMonths(3)->toDateString(),
            'encadrant_pressenti' => 'x',
        ])->assertOk();
        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/soumettre")->assertOk();
        $this->actingAs($reception)->postJson("/api/v1/tableaux-repartition/{$idTableau}/representer-dg")->assertOk();

        $reponse = $this->actingAs($dga)
            ->postJson("/api/v1/tableaux-repartition/{$idTableau}/rendre-avis", ['approuve' => true])
            ->assertOk()
            ->json('data');

        $this->assertTrue($reponse['scellement']['mention_interim']);
    }
}
