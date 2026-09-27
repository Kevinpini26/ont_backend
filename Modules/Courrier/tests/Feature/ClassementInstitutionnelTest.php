<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\ClassementDocument;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\DelegationPoste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class ClassementInstitutionnelTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_decision_classement_est_executee_uniquement_par_sec2_puis_archivee_et_corrigee_avec_audit(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $admin = User::factory()->administrateur()->create();
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'numero_enregistrement' => '2026-009999', 'reference_documentaire' => '001/ONT/DMC/2026', 'numero_depart' => 'DEP-1']);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [['type' => 'classement', 'instruction' => 'À classer']]])->assertOk();
        $dispatch = $courrier->dispatchs()->firstOrFail();
        $this->actingAs($admin)->postJson("/api/v1/dispatchs/{$dispatch->id}/classer", ['emplacement' => 'Armoire A'])->assertUnprocessable();
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/classer", ['cote' => 'COTE-1', 'emplacement' => 'Salle 1 / Armoire A', 'observation' => 'Original physique'])->assertOk();
        $classement = ClassementDocument::firstOrFail();
        $this->assertSame($courrier->dossier_id, $classement->dossier_id);
        $this->assertSame($dispatch->id, $classement->dispatch_courrier_id);
        $this->assertNotSame($courrier->numero_enregistrement, $classement->cote);
        $this->assertSame($sec2->id, $classement->classe_par_id);
        $this->assertNotNull($classement->classe_at);
        $this->actingAs($sec2)->postJson("/api/v1/classements-documents/{$classement->id}/corriger", ['emplacement' => 'Salle 1 / Armoire B', 'motif' => 'Correction de localisation'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'classement_document.metadonnees_corrigees', 'auditable_id' => $classement->id]);
        $this->actingAs($sec2)->postJson("/api/v1/classements-documents/{$classement->id}/archiver")->assertOk();
        $classement->refresh();
        $this->assertSame('archive', $classement->statut->value);
        $this->assertSame($sec2->id, $classement->archive_par_id);
        $this->assertNotNull($classement->archive_at);
        $this->assertDatabaseHas('courriers', ['id' => $courrier->id]);
    }

    public function test_decision_archivage_est_refusee_tant_quun_document_nest_pas_archive(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$courrier->dossier_id}/decision-archivage")->assertUnprocessable();
        $this->actingAs($sec2)->postJson("/api/v1/dossiers/{$courrier->dossier_id}/archiver")->assertUnprocessable();
        $this->assertDatabaseHas('dossiers', ['id' => $courrier->dossier_id, 'statut_archivage' => 'actif']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'dossier.archivage_decide', 'auditable_id' => $courrier->dossier_id]);
    }

    public function test_liste_classements_accepte_la_delegation_sec2_active_et_est_paginee(): void
    {
        $admin = User::factory()->administrateur()->create();
        $delegataire = User::factory()->responsableDirection()->create();
        DelegationPoste::query()->create([
            'poste' => Poste::SECRETARIAT_2, 'delegataire_id' => $delegataire->id,
            'debut' => now()->subDay(), 'fin' => now()->addDay(), 'motif' => 'Intérim', 'cree_par_id' => $admin->id,
        ]);

        $this->actingAs($delegataire)->getJson('/api/v1/classements-documents')
            ->assertOk()->assertJsonStructure(['data', 'current_page', 'per_page']);

        $delegation = DelegationPoste::query()->firstOrFail();
        $delegation->update(['fin' => now()->subDay()]);
        $this->actingAs($delegataire)->getJson('/api/v1/classements-documents')->assertForbidden();
    }

    public function test_decision_archivage_est_immuable_et_double_clic_est_refuse(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->archiverDocument($courrier, $dg, $sec2, 'DOUBLE-CLIC');

        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$courrier->dossier_id}/decision-archivage")->assertOk();
        $date = $courrier->dossier->fresh()->archivage_decide_at;
        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$courrier->dossier_id}/decision-archivage")->assertUnprocessable();
        $this->assertTrue($date->equalTo($courrier->dossier->fresh()->archivage_decide_at));
    }

    public function test_decision_archivage_exige_tous_les_documents_archives_avant_execution_sec2(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $a = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $b = Courrier::factory()->create(['dossier_id' => $a->dossier_id, 'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $c = Courrier::factory()->create(['dossier_id' => $a->dossier_id, 'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);

        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$a->dossier_id}/decision-archivage")->assertUnprocessable();
        $this->assertDatabaseHas('dossiers', ['id' => $a->dossier_id, 'statut_archivage' => 'actif']);

        $this->archiverDocument($a, $dg, $sec2, 'A');
        $this->archiverDocument($b, $dg, $sec2, 'B');
        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$a->dossier_id}/decision-archivage")->assertUnprocessable();
        $this->assertDatabaseHas('dossiers', ['id' => $a->dossier_id, 'statut_archivage' => 'actif']);

        $this->archiverDocument($c, $dg, $sec2, 'C');
        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$a->dossier_id}/decision-archivage")
            ->assertOk()->assertJsonPath('data.statut_archivage', 'a_archiver');
        $this->actingAs($sec2)->postJson("/api/v1/dossiers/{$a->dossier_id}/archiver")
            ->assertOk()->assertJsonPath('data.statut_archivage', 'archive');
    }

    private function archiverDocument(Courrier $courrier, User $dg, User $sec2, string $suffixe): void
    {
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [[
            'type' => 'classement', 'instruction' => 'Classer.',
        ]]])->assertOk();
        $dispatch = $courrier->dispatchs()->latest('id')->firstOrFail();
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/classer", [
            'cote' => "COTE-{$suffixe}", 'emplacement' => 'Archives',
        ])->assertOk();
        $classement = ClassementDocument::query()->where('courrier_id', $courrier->id)->firstOrFail();
        $this->actingAs($sec2)->postJson("/api/v1/classements-documents/{$classement->id}/archiver")->assertOk();
    }
}
