<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\DocumentType;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Enums\TypeLienPublic;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Models\StagiaireLienPublic;
use Modules\Stagiaires\Notifications\RapportStageDemandeNotification;

class RapportFinDeStageTest extends StagiaireTestCase
{
    use RefreshDatabase;

    private function stagiaireAvecLienRapport(): array
    {
        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create([
            'direction_id' => $direction->id,
            'statut' => StagiaireStatut::STAGE_EN_COURS,
        ]);

        $this->actingAs($dfp)->postJson("/api/v1/stagiaires/{$stagiaire->id}/ouvrir-periode-evaluation")->assertOk();

        $lien = StagiaireLienPublic::query()
            ->where('stagiaire_id', $stagiaire->id)
            ->where('type', TypeLienPublic::RAPPORT_STAGE)
            ->firstOrFail();

        return [$stagiaire, $lien];
    }

    public function test_le_stagiaire_peut_deposer_son_rapport_de_fin_de_stage(): void
    {
        [$stagiaire, $lien] = $this->stagiaireAvecLienRapport();

        $this->postJson("/api/v1/public/liens/{$lien->token}/rapport-stage", [
            'fichier' => UploadedFile::fake()->create('rapport.pdf', 500, 'application/pdf'),
        ])->assertOk();

        $this->assertDatabaseHas('stagiaire_documents', [
            'stagiaire_id' => $stagiaire->id,
            'type' => DocumentType::RAPPORT_FIN_STAGE->value,
            'uploaded_by_id' => null,
        ]);
    }

    public function test_le_lien_de_depot_est_a_usage_unique(): void
    {
        [, $lien] = $this->stagiaireAvecLienRapport();

        $this->postJson("/api/v1/public/liens/{$lien->token}/rapport-stage", [
            'fichier' => UploadedFile::fake()->create('rapport.pdf', 500, 'application/pdf'),
        ])->assertOk();

        $this->postJson("/api/v1/public/liens/{$lien->token}/rapport-stage", [
            'fichier' => UploadedFile::fake()->create('rapport2.pdf', 500, 'application/pdf'),
        ])->assertStatus(404);
    }

    public function test_un_fichier_non_pdf_est_rejete(): void
    {
        [, $lien] = $this->stagiaireAvecLienRapport();

        $this->postJson("/api/v1/public/liens/{$lien->token}/rapport-stage", [
            'fichier' => UploadedFile::fake()->create('rapport.exe', 500, 'application/x-msdownload'),
        ])->assertStatus(422);
    }

    public function test_ouvrir_la_periode_devaluation_genere_un_lien_de_depot_du_rapport(): void
    {
        Notification::fake();

        $dfp = User::factory()->agentDfp()->create();
        $direction = Direction::factory()->create();
        $stagiaire = Stagiaire::factory()->create([
            'direction_id' => $direction->id,
            'statut' => StagiaireStatut::STAGE_EN_COURS,
            'contact' => 'stagiaire@example.com',
        ]);

        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/ouvrir-periode-evaluation")
            ->assertOk();

        $this->assertDatabaseHas('stagiaire_liens_publics', [
            'stagiaire_id' => $stagiaire->id,
            'type' => TypeLienPublic::RAPPORT_STAGE->value,
        ]);

        Notification::assertSentOnDemand(RapportStageDemandeNotification::class);
    }
}
