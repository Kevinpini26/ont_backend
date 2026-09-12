<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\IssueProposee;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;

/**
 * Lot A (tableau généré, non ressaisi) : un dossier n'est proposable à un
 * tableau que si son courrier porteur a été imputé à la DFP par la DG —
 * jamais toute la base des demandes de stage. Les contrôles de cohérence
 * (quota, dates, doublon) avertissent sans jamais bloquer.
 */
class SelectionDossiersTableauTest extends StagiaireTestCase
{
    use RefreshDatabase;

    private function creerTableau(User $dfp): int
    {
        return $this->actingAs($dfp)->postJson('/api/v1/tableaux-repartition', [
            'periode_debut' => now()->toDateString(),
            'periode_fin' => now()->addMonth()->toDateString(),
        ])->json('data.id');
    }

    public function test_un_dossier_non_impute_a_la_dfp_nest_pas_eligible(): void
    {
        $this->directionDfp();
        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);

        $idTableau = $this->creerTableau($dfp);

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes", [
            'stagiaire_id' => $stagiaire->id,
            'direction_accueil_proposee_id' => $direction->id,
            'date_debut_proposee' => now()->addMonth()->toDateString(),
            'date_fin_proposee' => now()->addMonths(3)->toDateString(),
            'encadrant_pressenti' => 'x',
        ])->assertStatus(422);
    }

    public function test_le_dossier_impute_apparait_dans_les_dossiers_eligibles_et_pas_les_autres(): void
    {
        $directionDfp = $this->directionDfp();
        $dfp = User::factory()->agentDfp()->create();

        $eligible = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        $this->imputerADfp($eligible, $directionDfp);

        $nonImpute = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        $nonExamine = Stagiaire::factory()->create(['statut' => StagiaireStatut::DOSSIER_RECU]);
        $this->imputerADfp($nonExamine, $directionDfp);

        $reponse = $this->actingAs($dfp)->getJson('/api/v1/tableaux-repartition/dossiers-eligibles')->assertOk();

        $ids = collect($reponse->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($eligible->id));
        $this->assertFalse($ids->contains($nonImpute->id));
        $this->assertFalse($ids->contains($nonExamine->id));
    }

    public function test_un_avertissement_de_quota_najoute_ni_ne_bloque_la_ligne(): void
    {
        $directionDfp = $this->directionDfp();
        $directionSaturee = Direction::factory()->create(['capacite_max' => 1]);
        $dfp = User::factory()->agentDfp()->create();

        // Un stagiaire déjà affecté sature la direction.
        Stagiaire::factory()->create(['statut' => StagiaireStatut::AFFECTE, 'direction_id' => $directionSaturee->id]);

        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        $this->imputerADfp($stagiaire, $directionDfp);

        $idTableau = $this->creerTableau($dfp);

        $reponse = $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes", [
            'stagiaire_id' => $stagiaire->id,
            'direction_accueil_proposee_id' => $directionSaturee->id,
            'date_debut_proposee' => now()->addMonth()->toDateString(),
            'date_fin_proposee' => now()->addMonths(3)->toDateString(),
            'encadrant_pressenti' => 'x',
        ])->assertOk();

        $avertissements = collect($reponse->json('data.lignes.0.avertissements'))->pluck('code');
        $this->assertTrue($avertissements->contains('quota_atteint'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'tableau_repartition.ligne_ajoutee_avec_avertissement']);
    }

    public function test_des_dates_hors_periode_sont_signalees_en_avertissement(): void
    {
        $directionDfp = $this->directionDfp();
        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        $this->imputerADfp($stagiaire, $directionDfp);

        $idTableau = $this->creerTableau($dfp);

        $reponse = $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes", [
            'stagiaire_id' => $stagiaire->id,
            'direction_accueil_proposee_id' => $direction->id,
            'date_debut_proposee' => now()->addYears(2)->toDateString(),
            'date_fin_proposee' => now()->addYears(2)->addMonth()->toDateString(),
            'encadrant_pressenti' => 'x',
        ])->assertOk();

        $avertissements = collect($reponse->json('data.lignes.0.avertissements'))->pluck('code');
        $this->assertTrue($avertissements->contains('dates_hors_periode'));
    }

    public function test_un_doublon_suspecte_est_signale_en_avertissement(): void
    {
        $directionDfp = $this->directionDfp();
        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION,
            'doublon_suspecte' => true,
        ]);
        $this->imputerADfp($stagiaire, $directionDfp);

        $idTableau = $this->creerTableau($dfp);

        $reponse = $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes", [
            'stagiaire_id' => $stagiaire->id,
            'direction_accueil_proposee_id' => $direction->id,
            'date_debut_proposee' => now()->addMonth()->toDateString(),
            'date_fin_proposee' => now()->addMonths(3)->toDateString(),
            'encadrant_pressenti' => 'x',
        ])->assertOk();

        $avertissements = collect($reponse->json('data.lignes.0.avertissements'))->pluck('code');
        $this->assertTrue($avertissements->contains('doublon_suspecte'));
    }

    public function test_une_issue_non_retenue_exige_un_motif(): void
    {
        $directionDfp = $this->directionDfp();
        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        $this->imputerADfp($stagiaire, $directionDfp);

        $idTableau = $this->creerTableau($dfp);

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes", [
            'stagiaire_id' => $stagiaire->id,
            'direction_accueil_proposee_id' => $direction->id,
            'date_debut_proposee' => now()->addMonth()->toDateString(),
            'date_fin_proposee' => now()->addMonths(3)->toDateString(),
            'encadrant_pressenti' => 'x',
            'issue_proposee' => IssueProposee::NON_RETENU->value,
        ])->assertStatus(422)->assertJsonValidationErrors('motif_non_retenu');

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes", [
            'stagiaire_id' => $stagiaire->id,
            'direction_accueil_proposee_id' => $direction->id,
            'date_debut_proposee' => now()->addMonth()->toDateString(),
            'date_fin_proposee' => now()->addMonths(3)->toDateString(),
            'encadrant_pressenti' => 'x',
            'issue_proposee' => IssueProposee::NON_RETENU->value,
            'motif_non_retenu' => 'places_epuisees',
        ])->assertOk()->assertJsonPath('data.lignes.0.issue_proposee', 'non_retenu');
    }

    public function test_lajout_en_lot_enregistre_toutes_les_lignes_en_une_fois(): void
    {
        $directionDfp = $this->directionDfp();
        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();

        $stagiaires = Stagiaire::factory()->count(3)->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        $stagiaires->each(fn ($s) => $this->imputerADfp($s, $directionDfp));

        $idTableau = $this->creerTableau($dfp);

        $lignes = $stagiaires->map(fn ($s) => [
            'stagiaire_id' => $s->id,
            'direction_accueil_proposee_id' => $direction->id,
            'date_debut_proposee' => now()->addMonth()->toDateString(),
            'date_fin_proposee' => now()->addMonths(3)->toDateString(),
            'encadrant_pressenti' => 'x',
        ])->all();

        $this->actingAs($dfp)
            ->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes/lot", ['lignes' => $lignes])
            ->assertOk()
            ->assertJsonCount(3, 'data.lignes');

        foreach ($stagiaires as $s) {
            $this->assertSame(StagiaireStatut::EN_INSTRUCTION, $s->fresh()->statut);
        }
    }

    public function test_un_seul_tableau_par_periode_quand_le_parametre_est_active(): void
    {
        config(['stagiaires.tableau_un_seul_par_periode' => true]);
        $this->directionDfp();
        $dfp = User::factory()->agentDfp()->create();

        $this->actingAs($dfp)->postJson('/api/v1/tableaux-repartition', [
            'periode_debut' => '2026-09-01',
            'periode_fin' => '2026-09-30',
        ])->assertCreated();

        $this->actingAs($dfp)->postJson('/api/v1/tableaux-repartition', [
            'periode_debut' => '2026-09-01',
            'periode_fin' => '2026-09-30',
        ])->assertStatus(422);
    }

    public function test_plusieurs_tableaux_par_periode_restent_possibles_par_defaut(): void
    {
        $this->directionDfp();
        $dfp = User::factory()->agentDfp()->create();

        $this->actingAs($dfp)->postJson('/api/v1/tableaux-repartition', [
            'periode_debut' => '2026-09-01',
            'periode_fin' => '2026-09-30',
        ])->assertCreated();

        $this->actingAs($dfp)->postJson('/api/v1/tableaux-repartition', [
            'periode_debut' => '2026-09-01',
            'periode_fin' => '2026-09-30',
        ])->assertCreated();
    }
}
