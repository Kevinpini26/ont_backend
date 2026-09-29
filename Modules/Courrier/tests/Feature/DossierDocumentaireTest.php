<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\DocumentRelationType;
use Modules\Courrier\Enums\NiveauConfidentialite;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DocumentRelation;
use Modules\Courrier\Services\DocumentRelationService;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class DossierDocumentaireTest extends TestCase
{
    use RefreshDatabase;

    public function test_creer_un_document_cree_automatiquement_son_dossier(): void
    {
        $document = Courrier::factory()->create();

        $this->assertNotNull($document->fresh()->dossier_id);
        $this->assertDatabaseHas('dossiers', ['id' => $document->fresh()->dossier_id]);
    }

    public function test_plusieurs_documents_peuvent_appartenir_au_meme_dossier(): void
    {
        $initial = Courrier::factory()->create();
        $produit = Courrier::factory()->create(['dossier_id' => $initial->dossier_id]);

        $this->assertSame($initial->dossier_id, $produit->dossier_id);
        $this->assertCount(2, $initial->dossier->documents);
    }

    public function test_api_dossier_expose_la_date_de_validation_de_relecture_pour_le_resume_d(): void
    {
        $admin = User::factory()->administrateur()->create();
        $source = Courrier::factory()->create();
        $relectureValideeAt = Carbon::parse('2026-09-29T00:26:57Z');
        $reponse = Courrier::factory()->create([
            'dossier_id' => $source->dossier_id,
            'en_reponse_a_courrier_id' => $source->id,
            'statut' => 'projet_a_valider',
            'relecture_validee_at' => $relectureValideeAt,
        ]);

        $documents = $this->actingAs($admin)
            ->getJson("/api/v1/dossiers/{$source->dossier_id}")
            ->assertOk()
            ->json('data.documents');
        $documentReponse = collect($documents)->firstWhere('id', $reponse->id);

        $this->assertNotNull($documentReponse);
        $this->assertTrue(Carbon::parse($documentReponse['relecture_validee_at'])->equalTo($relectureValideeAt));
    }

    public function test_une_reponse_et_un_document_produit_peuvent_etre_relies(): void
    {
        $acteur = User::factory()->administrateur()->create();
        $initial = Courrier::factory()->create();
        $reponse = Courrier::factory()->create(['dossier_id' => $initial->dossier_id, 'en_reponse_a_courrier_id' => $initial->id]);
        $produit = Courrier::factory()->create(['dossier_id' => $initial->dossier_id]);
        $service = app(DocumentRelationService::class);

        $service->relier($reponse, $initial, DocumentRelationType::REPONSE_A, $acteur);
        $service->relier($produit, $initial, DocumentRelationType::PRODUIT_A_PARTIR_DE, $acteur);

        $this->assertDatabaseHas('document_relations', ['document_source_id' => $reponse->id, 'type_relation' => 'reponse_a']);
        $this->assertDatabaseHas('document_relations', ['document_source_id' => $produit->id, 'type_relation' => 'produit_a_partir_de']);
        $this->assertSame($initial->id, $reponse->fresh()->en_reponse_a_courrier_id);
    }

    public function test_une_relation_avec_soi_meme_est_interdite(): void
    {
        $document = Courrier::factory()->create();

        $this->expectException(ValidationException::class);
        app(DocumentRelationService::class)->relier($document, $document, DocumentRelationType::SUITE_DE, null);
    }

    public function test_une_relation_dupliquee_nest_pas_recreee(): void
    {
        $source = Courrier::factory()->create();
        $cible = Courrier::factory()->create(['dossier_id' => $source->dossier_id]);
        $service = app(DocumentRelationService::class);

        $premiere = $service->relier($source, $cible, DocumentRelationType::SUITE_DE, null);
        $seconde = $service->relier($source, $cible, DocumentRelationType::SUITE_DE, null);

        $this->assertTrue($premiere->is($seconde));
        $this->assertSame(1, DocumentRelation::query()->count());
    }

    public function test_un_cycle_documentaire_est_interdit(): void
    {
        $a = Courrier::factory()->create();
        $b = Courrier::factory()->create(['dossier_id' => $a->dossier_id]);
        $service = app(DocumentRelationService::class);
        $service->relier($a, $b, DocumentRelationType::SUITE_DE, null);

        $this->expectException(ValidationException::class);
        $service->relier($b, $a, DocumentRelationType::SUITE_DE, null);
    }

    public function test_lapi_dossier_ne_retourne_que_les_documents_de_la_direction_autorisee(): void
    {
        $directionA = Direction::factory()->create();
        $directionB = Direction::factory()->create();
        $directeurA = User::factory()->directeurDirection($directionA)->create();
        $visible = Courrier::factory()->create(['direction_origine_id' => $directionA->id, 'direction_destination_id' => $directionA->id]);
        Courrier::factory()->create(['dossier_id' => $visible->dossier_id, 'direction_origine_id' => $directionB->id, 'direction_destination_id' => $directionB->id]);

        $this->actingAs($directeurA)->getJson("/api/v1/dossiers/{$visible->dossier_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.documents')
            ->assertJsonPath('data.documents.0.id', $visible->id);
    }

    public function test_la_confidentialite_reste_evaluee_document_par_document(): void
    {
        $direction = Direction::factory()->create();
        $directeur = User::factory()->directeurDirection($direction)->create();
        $visible = Courrier::factory()->create(['direction_origine_id' => $direction->id, 'direction_destination_id' => $direction->id]);
        Courrier::factory()->create([
            'dossier_id' => $visible->dossier_id,
            'direction_origine_id' => $direction->id,
            'direction_destination_id' => $direction->id,
            'niveau_confidentialite' => NiveauConfidentialite::SECRET,
        ]);

        $this->actingAs($directeur)->getJson("/api/v1/dossiers/{$visible->dossier_id}")
            ->assertOk()->assertJsonCount(1, 'data.documents');
    }

    public function test_un_utilisateur_sans_document_visible_ne_peut_pas_consulter_le_dossier(): void
    {
        $directionA = Direction::factory()->create();
        $directionB = Direction::factory()->create();
        $document = Courrier::factory()->create(['direction_origine_id' => $directionA->id, 'direction_destination_id' => $directionA->id]);
        $directeurB = User::factory()->directeurDirection($directionB)->create();

        $this->actingAs($directeurB)->getJson("/api/v1/dossiers/{$document->dossier_id}")->assertNotFound();
    }

    public function test_lapi_serialise_le_dossier_et_les_relations_sans_modifier_le_workflow(): void
    {
        $admin = User::factory()->administrateur()->create();
        $source = Courrier::factory()->create();
        $cible = Courrier::factory()->create(['dossier_id' => $source->dossier_id]);
        app(DocumentRelationService::class)->relier($cible, $source, DocumentRelationType::DOCUMENT_RETOUR, $admin);

        $statutAvant = $source->statut;
        $this->actingAs($admin)->getJson("/api/v1/courriers/{$source->id}/relations")
            ->assertOk()
            ->assertJsonPath('data.0.type_relation', 'document_retour')
            ->assertJsonPath('data.0.cible.id', $source->id);

        $this->assertSame($statutAvant, $source->fresh()->statut);
    }

    public function test_un_dossier_archive_refuse_nouveau_document_et_nouvelle_relation(): void
    {
        $source = Courrier::factory()->create();
        $cible = Courrier::factory()->create(['dossier_id' => $source->dossier_id]);
        $source->dossier()->update(['statut_archivage' => 'archive', 'archive_at' => now()]);

        try {
            Courrier::factory()->create(['dossier_id' => $source->dossier_id]);
            $this->fail('La création dans un dossier archivé aurait dû être refusée.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('courriers', 2);
        }

        $this->expectException(ValidationException::class);
        app(DocumentRelationService::class)->relier($cible, $source, DocumentRelationType::SUITE_DE, null);
    }
}
