<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Enums\StagiaireTypeStage;
use Modules\Stagiaires\Enums\TypeLienPublic;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Models\StagiaireLienPublic;

class EngagementConfidentialiteTest extends StagiaireTestCase
{
    use RefreshDatabase;

    private function validerArrivee(Direction $direction): Stagiaire
    {
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::AFFECTE,
            'direction_id' => $direction->id,
            'type_stage' => StagiaireTypeStage::ACADEMIQUE,
        ]);

        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/valider-arrivee", [
                'date_debut_stage' => now()->toDateString(),
                'date_fin_stage' => now()->addMonths(2)->toDateString(),
            ])
            ->assertOk();

        return $stagiaire->fresh();
    }

    public function test_la_validation_de_larrivee_genere_lengagement_de_confidentialite(): void
    {
        $direction = Direction::factory()->create();
        $stagiaire = $this->validerArrivee($direction);

        $this->assertNotNull($stagiaire->engagement_confidentialite_chemin);
        $this->assertDatabaseHas('stagiaire_liens_publics', [
            'stagiaire_id' => $stagiaire->id,
            'type' => TypeLienPublic::ENGAGEMENT_CONFIDENTIALITE->value,
        ]);
    }

    public function test_le_stagiaire_peut_signer_lengagement_via_le_lien_public(): void
    {
        $direction = Direction::factory()->create();
        $stagiaire = $this->validerArrivee($direction);
        $lien = StagiaireLienPublic::query()
            ->where('stagiaire_id', $stagiaire->id)
            ->where('type', TypeLienPublic::ENGAGEMENT_CONFIDENTIALITE)
            ->firstOrFail();

        $this->postJson("/api/v1/public/liens/{$lien->token}/signer-engagement-confidentialite")->assertOk();

        $this->assertNotNull($stagiaire->fresh()->engagement_confidentialite_signe_at);
    }

    public function test_lengagement_ne_peut_pas_etre_signe_deux_fois(): void
    {
        $direction = Direction::factory()->create();
        $stagiaire = $this->validerArrivee($direction);
        $lien = StagiaireLienPublic::query()
            ->where('stagiaire_id', $stagiaire->id)
            ->where('type', TypeLienPublic::ENGAGEMENT_CONFIDENTIALITE)
            ->firstOrFail();

        $this->postJson("/api/v1/public/liens/{$lien->token}/signer-engagement-confidentialite")->assertOk();

        // Usage unique, 404 générique — même garde que la convention.
        $this->postJson("/api/v1/public/liens/{$lien->token}/signer-engagement-confidentialite")->assertStatus(404);
    }
}
