<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\DocumentType;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Enums\StagiaireTypeStage;
use Modules\Stagiaires\Models\Stagiaire;

/**
 * Empreinte SHA-256 des documents PDF (voir docs/conformite-donnees.md et
 * Modules\Kernel\Support\EmpreinteFichier) : preuve technique
 * complémentaire d'intégrité, en l'absence de signature électronique
 * qualifiée au sens des articles 104-110 de l'ordonnance-loi n°23/010.
 */
class EmpreinteIntegriteDocumentsTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_la_note_daffectation_recoit_une_empreinte_correcte(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $direction = Direction::factory()->create(['actif' => true]);
        $candidat = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);

        $this->actingAs($dfp)->postJson("/api/v1/stagiaires/{$candidat->id}/affecter", ['direction_id' => $direction->id])->assertOk();

        $document = $candidat->fresh()->documents()->where('type', DocumentType::NOTE_AFFECTATION->value)->firstOrFail();

        $this->assertNotNull($document->sha256);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($document->chemin)), $document->sha256);
    }

    public function test_la_convention_et_lengagement_recoivent_une_empreinte_correcte(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $direction = Direction::factory()->create();
        $stagiaire = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::AFFECTE,
            'direction_id' => $direction->id,
            'type_stage' => StagiaireTypeStage::ACADEMIQUE,
        ]);

        $this->actingAs($dfp)->postJson("/api/v1/stagiaires/{$stagiaire->id}/valider-arrivee", [
            'date_debut_stage' => now()->toDateString(),
            'date_fin_stage' => now()->addMonths(2)->toDateString(),
        ])->assertOk();

        $stagiaire->refresh();

        $this->assertSame(hash('sha256', Storage::disk('local')->get($stagiaire->convention_chemin)), $stagiaire->convention_sha256);
        $this->assertSame(
            hash('sha256', Storage::disk('local')->get($stagiaire->engagement_confidentialite_chemin)),
            $stagiaire->engagement_confidentialite_sha256,
        );
    }

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

    public function test_lattestation_et_le_certificat_recoivent_une_empreinte_correcte(): void
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

        $this->actingAs($dfp)->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-dfp", ['grille' => $this->grille(0.7)])->assertOk();

        $attestation = $stagiaire->fresh()->documents()->where('type', DocumentType::ATTESTATION_STAGE->value)->firstOrFail();
        $certificat = $stagiaire->fresh()->documents()->where('type', DocumentType::CERTIFICAT_FIN_STAGE->value)->firstOrFail();

        $this->assertSame(hash('sha256', Storage::disk('local')->get($attestation->chemin)), $attestation->sha256);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($certificat->chemin)), $certificat->sha256);
    }
}
