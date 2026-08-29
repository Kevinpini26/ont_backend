<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\DocumentType;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Models\StagiaireDocument;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * StagiaireDocumentController::download() n'appelait auparavant que
 * authorize('view', $stagiaire) : quiconque voyait le dossier (notamment la
 * direction d'accueil) pouvait télécharger n'importe quelle pièce, y
 * compris la pièce d'identité et les diplômes — réservés en réalité à la
 * DFP (StagiairePolicy::telechargerDocument()).
 */
class StagiaireDocumentDownloadTest extends StagiaireTestCase
{
    use RefreshDatabase;

    private function creerDocument(Stagiaire $stagiaire, DocumentType $type): StagiaireDocument
    {
        Storage::disk('local')->put("stagiaires/{$stagiaire->id}/fichier.pdf", 'contenu-test');

        return StagiaireDocument::query()->create([
            'stagiaire_id' => $stagiaire->id,
            'type' => $type,
            'nom_original' => 'fichier.pdf',
            'chemin' => "stagiaires/{$stagiaire->id}/fichier.pdf",
        ]);
    }

    public static function piecesReserveesALaDfp(): array
    {
        return [
            [DocumentType::PIECE_IDENTITE],
            [DocumentType::DIPLOME_ETAT],
            [DocumentType::DERNIER_DIPLOME],
            [DocumentType::CV],
            [DocumentType::ATTESTATION_INSCRIPTION],
        ];
    }

    public static function piecesVisiblesParLaDirection(): array
    {
        return [
            [DocumentType::LETTRE_STAGE_UNIVERSITE],
            [DocumentType::LETTRE_DEMANDE_STAGE],
        ];
    }

    #[DataProvider('piecesReserveesALaDfp')]
    public function test_la_direction_daccueil_ne_peut_pas_telecharger_les_pieces_reservees_a_la_dfp(DocumentType $type): void
    {
        Storage::fake('local');

        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $stagiaire = Stagiaire::factory()->create(['direction_id' => $direction->id]);
        $document = $this->creerDocument($stagiaire, $type);

        $this->actingAs($responsable)
            ->getJson("/api/v1/stagiaires/{$stagiaire->id}/documents/{$document->id}/telecharger")
            ->assertForbidden();
    }

    #[DataProvider('piecesReserveesALaDfp')]
    public function test_la_dfp_peut_telecharger_les_pieces_qui_lui_sont_reservees(DocumentType $type): void
    {
        Storage::fake('local');

        $agentDfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create();
        $document = $this->creerDocument($stagiaire, $type);

        $this->actingAs($agentDfp)
            ->getJson("/api/v1/stagiaires/{$stagiaire->id}/documents/{$document->id}/telecharger")
            ->assertOk();
    }

    #[DataProvider('piecesVisiblesParLaDirection')]
    public function test_la_direction_daccueil_peut_telecharger_les_pieces_qui_la_concernent(DocumentType $type): void
    {
        Storage::fake('local');

        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $stagiaire = Stagiaire::factory()->create(['direction_id' => $direction->id]);
        $document = $this->creerDocument($stagiaire, $type);

        $this->actingAs($responsable)
            ->getJson("/api/v1/stagiaires/{$stagiaire->id}/documents/{$document->id}/telecharger")
            ->assertOk();
    }

    public function test_lattestation_reste_reservee_a_la_dfp_et_ladministrateur(): void
    {
        Storage::fake('local');

        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $stagiaire = Stagiaire::factory()->create([
            'direction_id' => $direction->id,
            'statut' => StagiaireStatut::CLOTURE,
        ]);
        $document = $this->creerDocument($stagiaire, DocumentType::ATTESTATION_STAGE);

        $this->actingAs($responsable)
            ->getJson("/api/v1/stagiaires/{$stagiaire->id}/documents/{$document->id}/telecharger")
            ->assertForbidden();

        $agentDfp = User::factory()->agentDfp()->create();
        $this->actingAs($agentDfp)
            ->getJson("/api/v1/stagiaires/{$stagiaire->id}/documents/{$document->id}/telecharger")
            ->assertOk();
    }

    public function test_ladministrateur_peut_tout_telecharger(): void
    {
        Storage::fake('local');

        $administrateur = User::factory()->administrateur()->create();
        $stagiaire = Stagiaire::factory()->create();
        $document = $this->creerDocument($stagiaire, DocumentType::PIECE_IDENTITE);

        $this->actingAs($administrateur)
            ->getJson("/api/v1/stagiaires/{$stagiaire->id}/documents/{$document->id}/telecharger")
            ->assertOk();
    }
}
