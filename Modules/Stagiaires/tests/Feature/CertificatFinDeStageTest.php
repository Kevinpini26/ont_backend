<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\DocumentType;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;

class CertificatFinDeStageTest extends StagiaireTestCase
{
    private function grille(float $facteur): array
    {
        return [
            'aptitudes_professionnelles' => [
                'connaissance_metier' => 10 * $facteur, 'esprit_initiative' => 10 * $facteur, 'sens_responsabilite' => 10 * $facteur,
                'soin_proprete' => 10 * $facteur, 'rendement' => 10 * $facteur, 'justification' => 'RAS',
            ],
            'relations_humaines' => [
                'esprit_equipe' => 10 * $facteur, 'communication' => 10 * $facteur, 'relations_sociales' => 10 * $facteur,
                'justification' => 'RAS',
            ],
            'presentation' => [
                'discipline' => 5 * $facteur, 'ponctualite' => 5 * $facteur, 'regularite' => 5 * $facteur, 'tenue' => 5 * $facteur,
                'justification' => 'RAS',
            ],
        ];
    }

    use RefreshDatabase;

    public function test_la_cloture_genere_un_certificat_distinct_de_lattestation(): void
    {
        $direction = Direction::factory()->create();
        $stagiaire = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::EVALUATION_EN_COURS,
            'direction_id' => $direction->id,
            'evaluation_direction_grille' => $this->grille(0.8),
            'evaluation_direction_total' => 80,
            'evaluation_direction_at' => now(),
        ]);
        $dfp = User::factory()->agentDfp()->create();

        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-dfp", ['grille' => $this->grille(0.7)])
            ->assertOk();

        $this->assertDatabaseHas('stagiaire_documents', [
            'stagiaire_id' => $stagiaire->id,
            'type' => DocumentType::CERTIFICAT_FIN_STAGE->value,
        ]);
        $this->assertDatabaseHas('stagiaire_documents', [
            'stagiaire_id' => $stagiaire->id,
            'type' => DocumentType::ATTESTATION_STAGE->value,
        ]);
    }

    public function test_la_direction_daccueil_peut_telecharger_le_certificat_mais_pas_lattestation(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $stagiaire = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::EVALUATION_EN_COURS,
            'direction_id' => $direction->id,
            'evaluation_direction_grille' => $this->grille(0.8),
            'evaluation_direction_total' => 80,
            'evaluation_direction_at' => now(),
        ]);
        $dfp = User::factory()->agentDfp()->create();

        $this->actingAs($dfp)->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-dfp", ['grille' => $this->grille(0.7)])->assertOk();

        $certificat = $stagiaire->fresh()->documents()->where('type', DocumentType::CERTIFICAT_FIN_STAGE->value)->firstOrFail();
        $attestation = $stagiaire->fresh()->documents()->where('type', DocumentType::ATTESTATION_STAGE->value)->firstOrFail();

        $this->actingAs($responsable)
            ->getJson("/api/v1/stagiaires/{$stagiaire->id}/documents/{$certificat->id}/telecharger")
            ->assertOk();

        $this->actingAs($responsable)
            ->getJson("/api/v1/stagiaires/{$stagiaire->id}/documents/{$attestation->id}/telecharger")
            ->assertStatus(403);
    }
}
