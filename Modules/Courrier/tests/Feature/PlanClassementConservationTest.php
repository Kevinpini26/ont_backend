<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\Dossier;
use Modules\Courrier\Models\PlanClassementRubrique;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * Lot 6, point 6 : plan de classement et conservation réglables par l'administrateur. Aucune durée inventée
 * (référentiel vide par défaut), cote calculée depuis le plan sans toucher aux cotes existantes, et jamais
 * de destruction automatique : élimination et versement passent par un bordereau validé par une autre personne.
 */
class PlanClassementConservationTest extends CourrierTestCase
{
    use RefreshDatabase;

    private Direction $direction;

    private User $admin;

    private User $sec2;

    private User $dg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->direction = Direction::factory()->create();
        $this->admin = User::factory()->administrateur()->create();
        $this->sec2 = $this->agent(Poste::SECRETARIAT_2, $this->direction);
        $this->dg = $this->agent(Poste::DG, $this->direction);
    }

    private function rubrique(string $code, ?int $duree, ?string $sort): PlanClassementRubrique
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/plan-classement', [
            'code' => $code, 'libelle' => "Rubrique {$code}", 'duree_conservation_annees' => $duree, 'sort_final' => $sort,
        ])->assertCreated()->json('data.id');

        return PlanClassementRubrique::query()->findOrFail($id);
    }

    /** Dossier classé dans une rubrique et archivé à une date donnée (raccourci de test). */
    private function dossierArchive(PlanClassementRubrique $rubrique, string $archiveLe): Dossier
    {
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::ENREGISTRE]);
        $dossier = Dossier::query()->findOrFail($courrier->dossier_id);
        $dossier->forceFill(['rubrique_classement_id' => $rubrique->id, 'statut_archivage' => 'archive', 'archive_at' => $archiveLe])->save();

        return $dossier->fresh();
    }

    public function test_le_referentiel_est_vide_par_defaut_et_seul_ladministrateur_le_regle_avec_audit(): void
    {
        $this->actingAs($this->sec2)->getJson('/api/v1/plan-classement')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->dg)->postJson('/api/v1/plan-classement', ['code' => 'X', 'libelle' => 'X'])->assertForbidden();

        $rubrique = $this->rubrique('ADM-01', 10, 'verser');
        $this->actingAs($this->admin)->putJson("/api/v1/plan-classement/{$rubrique->id}", ['duree_conservation_annees' => 5, 'sort_final' => 'detruire'])->assertOk();
        $this->actingAs($this->admin)->postJson('/api/v1/plan-classement', ['code' => 'Y', 'libelle' => 'Y', 'sort_final' => 'bruler'])->assertUnprocessable();

        $this->assertSame(2, AuditLog::query()->where('action', 'like', 'plan_classement.%')->count());
    }

    public function test_la_cote_se_calcule_depuis_le_plan_sans_changer_les_cotes_existantes(): void
    {
        $rubrique = $this->rubrique('COR-01', null, null);
        $ancien = Courrier::factory()->create(['cote_classement' => 'DMC-2026-0007']);

        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->actingAs($this->dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [['type' => 'classement', 'instruction' => 'À classer']]])->assertOk();
        $dispatch = $courrier->dispatchs()->firstOrFail();
        $this->receptionnerDispatchSec2($dispatch, $this->sec2);
        $this->actingAs($this->sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/classer", [
            'emplacement' => 'Salle 1', 'rubrique_classement_id' => $rubrique->id,
        ])->assertOk();

        $this->assertSame('COR-01-'.now()->year.'-0001', $courrier->fresh()->cote_classement);
        $this->assertSame($rubrique->id, Dossier::query()->findOrFail($courrier->dossier_id)->rubrique_classement_id);
        $this->assertSame('DMC-2026-0007', $ancien->fresh()->cote_classement);
    }

    public function test_le_dossier_affiche_sa_rubrique_sa_fin_de_conservation_et_son_sort_final(): void
    {
        $dossier = $this->dossierArchive($this->rubrique('FIN-01', 5, 'detruire'), '2020-03-15 10:00:00');
        $courrier = Courrier::query()->withoutGlobalScopes()->where('dossier_id', $dossier->id)->firstOrFail();

        $this->actingAs($this->dg)->getJson("/api/v1/courriers/{$courrier->id}")->assertOk()
            ->assertJsonPath('data.conservation.rubrique', 'FIN-01 — Rubrique FIN-01')
            ->assertJsonPath('data.conservation.fin_conservation_le', '2025-03-15')
            ->assertJsonPath('data.conservation.sort_final', 'detruire');
    }

    public function test_la_liste_de_fin_de_conservation_est_reservee_aux_postes_autorises(): void
    {
        $echu = $this->dossierArchive($this->rubrique('E-01', 5, 'detruire'), '2019-01-10 10:00:00');
        $this->dossierArchive($this->rubrique('E-02', 50, 'detruire'), '2020-01-10 10:00:00');

        $this->actingAs($this->sec2)->getJson('/api/v1/plan-classement/fin-conservation')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.dossier_id', $echu->id);
        $this->actingAs(User::factory()->directeurDirection($this->direction)->create())
            ->getJson('/api/v1/plan-classement/fin-conservation')->assertForbidden();
    }

    public function test_une_elimination_exige_un_bordereau_valide_par_une_autre_personne_et_ne_supprime_rien(): void
    {
        $aDetruire = $this->dossierArchive($this->rubrique('D-01', 5, 'detruire'), '2019-01-10 10:00:00');
        $aVerser = $this->dossierArchive($this->rubrique('V-01', 5, 'verser'), '2019-01-10 10:00:00');

        $this->actingAs($this->sec2)->postJson('/api/v1/plan-classement/bordereaux', [
            'type' => 'elimination', 'dossier_ids' => [$aVerser->id], 'motif' => 'Sort final',
        ])->assertUnprocessable();
        $bordereau = $this->actingAs($this->sec2)->postJson('/api/v1/plan-classement/bordereaux', [
            'type' => 'elimination', 'dossier_ids' => [$aDetruire->id], 'motif' => 'Durée d’utilité administrative échue',
        ])->assertCreated()->assertJsonPath('data.statut', 'propose')->json('data.id');

        $this->assertNull($aDetruire->fresh()->sort_final_execute_at, 'Rien ne se passe avant la validation humaine.');
        $this->actingAs($this->sec2)->postJson("/api/v1/plan-classement/bordereaux/{$bordereau}/valider")->assertForbidden();
        $this->actingAs($this->dg)->postJson("/api/v1/plan-classement/bordereaux/{$bordereau}/valider")->assertOk()->assertJsonPath('data.statut', 'valide');

        $this->assertNotNull($aDetruire->fresh()->sort_final_execute_at);
        $this->assertTrue(Courrier::query()->withoutGlobalScopes()->where('dossier_id', $aDetruire->id)->exists(), 'Aucune suppression de données.');
        $this->assertTrue(AuditLog::query()->where('action', 'plan_classement.bordereau_valide')->exists());

        $capture = new class implements PdfGenerationService
        {
            public string $html = '';

            public function genererDepuisVue(string $vue, array $donnees = []): string
            {
                $this->html = view($vue, $donnees)->render();

                return '%PDF-1.4';
            }
        };
        $this->app->instance(PdfGenerationService::class, $capture);
        $this->actingAs($this->dg)->get("/api/v1/plan-classement/bordereaux/{$bordereau}/pdf")->assertOk();
        $this->assertStringContainsString('Bordereau d’élimination', $capture->html);
        $this->assertStringContainsString('D-01', $capture->html);
    }

    public function test_le_versement_aux_archives_produit_un_bordereau_de_versement(): void
    {
        $aVerser = $this->dossierArchive($this->rubrique('V-02', 5, 'verser'), '2019-01-10 10:00:00');

        $bordereau = $this->actingAs($this->sec2)->postJson('/api/v1/plan-classement/bordereaux', [
            'type' => 'versement', 'dossier_ids' => [$aVerser->id], 'motif' => 'Versement aux Archives nationales',
        ])->assertCreated()->json('data');

        $this->assertStringStartsWith('BV-', $bordereau['numero']);
    }
}
