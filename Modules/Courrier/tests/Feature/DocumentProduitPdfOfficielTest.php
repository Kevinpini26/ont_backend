<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Courrier\Support\TipTapHtmlRenderer;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use RuntimeException;
use Smalot\PdfParser\Parser;

class DocumentProduitPdfOfficielTest extends CourrierTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_validation_genere_un_pdf_officiel_fidele_et_imprimable(): void
    {
        [$document, $secretariat, $directeur] = $this->preparerDocument();

        $reponse = $this->actingAs($directeur)
            ->postJson("/api/v1/documents-produits-direction/{$document->id}/valider")
            ->assertOk()
            ->assertJsonPath('data.statut', DocumentProduitStatut::VALIDE->value);

        $document->refresh();
        $courrier = $document->courrier()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame($courrier->reference_documentaire, $reponse->json('data.courrier.reference_documentaire'));
        $this->assertNotNull($courrier->reference_documentaire);
        $this->assertSame($document->version_contenu, $document->pdf_officiel_version_contenu);
        $this->assertNotNull($document->pdf_officiel_genere_at);
        Storage::disk('local')->assertExists($document->pdf_officiel_chemin);

        $contenuPdf = Storage::disk('local')->get($document->pdf_officiel_chemin);
        $this->assertSame(hash('sha256', $contenuPdf), $document->pdf_officiel_sha256);
        $textePdf = (new Parser)->parseContent($contenuPdf)->getText();

        $this->assertStringContainsString('OFFICE NATIONAL DU TOURISME', $textePdf);
        $this->assertStringContainsString($document->direction->nom, $textePdf);
        $this->assertStringContainsString($courrier->reference_documentaire, $textePdf);
        $this->assertStringContainsString('Texte officiel exact.', $textePdf);
        $this->assertMatchesRegularExpression('/DOCUMENT À SIGNER PHYSIQUEMENT\s+—\s+NON\s+SIGNÉ/u', $textePdf);
        $this->assertMatchesRegularExpression('/Zone réservée à la signature physique du Directeur\s+et au cachet officiel/u', $textePdf);
        $this->assertSame($secretariat->direction_id, $document->direction_id);
    }

    public function test_renderer_officiel_accepte_un_paragraphe_sans_cle_content(): void
    {
        $html = app(TipTapHtmlRenderer::class)->renderOfficial([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph'],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Texte']]],
            ],
        ]);

        $this->assertSame('<p></p><p>Texte</p>', $html);
    }

    public function test_renderer_officiel_accepte_un_paragraphe_avec_content_vide(): void
    {
        $html = app(TipTapHtmlRenderer::class)->renderOfficial([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => []],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Texte']]],
            ],
        ]);

        $this->assertSame('<p></p><p>Texte</p>', $html);
    }

    public function test_renderer_officiel_conserve_un_paragraphe_vide_entre_du_texte(): void
    {
        $html = app(TipTapHtmlRenderer::class)->renderOfficial([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Avant']]],
                ['type' => 'paragraph'],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Après']]],
            ],
        ]);

        $this->assertSame('<p>Avant</p><p></p><p>Après</p>', $html);
    }

    public function test_renderer_officiel_conserve_un_paragraphe_vide_final(): void
    {
        $html = app(TipTapHtmlRenderer::class)->renderOfficial([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Texte']]],
                ['type' => 'paragraph', 'content' => []],
            ],
        ]);

        $this->assertSame('<p>Texte</p><p></p>', $html);
    }

    public function test_renderer_officiel_refuse_un_document_sans_texte_significatif(): void
    {
        [$document, , $directeur] = $this->preparerDocument([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph'],
                ['type' => 'paragraph', 'content' => []],
            ],
        ]);

        $this->actingAs($directeur)
            ->postJson("/api/v1/documents-produits-direction/{$document->id}/valider")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contenu');

        $document->refresh();
        $this->assertSame(DocumentProduitStatut::SOUMIS_DIRECTEUR, $document->statut);
        $this->assertNull($document->courrier->reference_documentaire);
        $this->assertNull($document->pdf_officiel_chemin);
    }

    public function test_renderer_officiel_refuse_toujours_un_noeud_inconnu(): void
    {
        $this->expectException(ValidationException::class);

        app(TipTapHtmlRenderer::class)->renderOfficial([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Texte']]],
                ['type' => 'customNode'],
            ],
        ]);
    }

    public function test_renderer_officiel_refuse_toujours_une_marque_inconnue(): void
    {
        $this->expectException(ValidationException::class);

        app(TipTapHtmlRenderer::class)->renderOfficial([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'Texte',
                    'marks' => [['type' => 'customMark']],
                ]],
            ]],
        ]);
    }

    public function test_rendu_historique_conserve_le_traitement_des_paragraphes_vides(): void
    {
        $html = app(TipTapHtmlRenderer::class)->render([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph'],
                ['type' => 'paragraph', 'content' => []],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Texte']]],
            ],
        ]);

        $this->assertSame('<p></p><p></p><p>Texte</p>', $html);
    }

    public function test_validation_refuse_un_noeud_tiptap_non_pris_en_charge_sans_attribuer_la_reference(): void
    {
        $contenu = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'image', 'attrs' => ['src' => 'https://example.invalid/x.png']]],
            ]],
        ];
        [$document, , $directeur] = $this->preparerDocument($contenu);
        $pdf = Mockery::mock(PdfGenerationService::class);
        $pdf->shouldNotReceive('genererDepuisVue');
        $this->app->instance(PdfGenerationService::class, $pdf);

        $this->actingAs($directeur)
            ->postJson("/api/v1/documents-produits-direction/{$document->id}/valider")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contenu');

        $document->refresh();
        $this->assertSame(DocumentProduitStatut::SOUMIS_DIRECTEUR, $document->statut);
        $this->assertNull($document->courrier->reference_documentaire);
        $this->assertNull($document->pdf_officiel_chemin);
        $this->assertSame([], Storage::disk('local')->allFiles('documents-internes/officiels'));
    }

    public function test_validation_refuse_une_marque_tiptap_non_pris_en_charge(): void
    {
        $contenu = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => 'Texte barré.', 'marks' => [['type' => 'strike']]]],
            ]],
        ];
        [$document, , $directeur] = $this->preparerDocument($contenu);
        $pdf = Mockery::mock(PdfGenerationService::class);
        $pdf->shouldNotReceive('genererDepuisVue');
        $this->app->instance(PdfGenerationService::class, $pdf);

        $this->actingAs($directeur)
            ->postJson("/api/v1/documents-produits-direction/{$document->id}/valider")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contenu');

        $document->refresh();
        $this->assertSame(DocumentProduitStatut::SOUMIS_DIRECTEUR, $document->statut);
        $this->assertNull($document->courrier->reference_documentaire);
        $this->assertNull($document->pdf_officiel_chemin);
    }

    public function test_echec_du_moteur_pdf_annule_la_reference_et_ne_valide_pas_le_document(): void
    {
        [$document, , $directeur] = $this->preparerDocument();
        $pdf = Mockery::mock(PdfGenerationService::class);
        $pdf->shouldReceive('genererDepuisVue')->once()->andThrow(new RuntimeException('Échec Dompdf simulé.'));
        $this->app->instance(PdfGenerationService::class, $pdf);

        $this->actingAs($directeur)
            ->postJson("/api/v1/documents-produits-direction/{$document->id}/valider")
            ->assertInternalServerError();

        $document->refresh();
        $this->assertSame(DocumentProduitStatut::SOUMIS_DIRECTEUR, $document->statut);
        $this->assertNull($document->courrier->reference_documentaire);
        $this->assertNull($document->pdf_officiel_chemin);
        $this->assertSame([], Storage::disk('local')->allFiles('documents-internes/officiels'));
    }

    public function test_echec_de_transaction_compense_le_pdf_promu(): void
    {
        [$document, , $directeur] = $this->preparerDocument();
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('enregistrer')
            ->once()
            ->withArgs(fn (string $action): bool => $action === 'courrier.reference_documentaire_interne_attribuee');
        $audit->shouldReceive('enregistrer')
            ->once()
            ->withArgs(fn (string $action): bool => $action === 'document_direction.valide')
            ->andThrow(new RuntimeException('Échec transactionnel simulé.'));
        $this->app->instance(AuditLogger::class, $audit);

        $this->actingAs($directeur)
            ->postJson("/api/v1/documents-produits-direction/{$document->id}/valider")
            ->assertInternalServerError();

        $document->refresh();
        $this->assertSame(DocumentProduitStatut::SOUMIS_DIRECTEUR, $document->statut);
        $this->assertNull($document->courrier->reference_documentaire);
        $this->assertSame([], Storage::disk('local')->allFiles('documents-internes/officiels'));
    }

    public function test_echec_de_promotion_empeche_la_validation_et_compense_le_fichier_temporaire(): void
    {
        [$document, , $directeur] = $this->preparerDocument();
        $disque = Mockery::mock(Storage::disk('local'))->makePartial();
        $disque->shouldReceive('move')->once()->andReturnFalse();
        Storage::set('local', $disque);

        $this->actingAs($directeur)
            ->postJson("/api/v1/documents-produits-direction/{$document->id}/valider")
            ->assertInternalServerError();

        $document->refresh();
        $this->assertSame(DocumentProduitStatut::SOUMIS_DIRECTEUR, $document->statut);
        $this->assertNull($document->courrier->reference_documentaire);
        $this->assertNull($document->pdf_officiel_chemin);
        $this->assertSame([], Storage::disk('local')->allFiles('documents-internes/officiels'));
    }

    public function test_telechargement_est_prive_immuable_et_ne_regenere_pas_le_pdf(): void
    {
        [$document, $secretariat, $directeur] = $this->preparerDocument();
        $this->actingAs($directeur)
            ->postJson("/api/v1/documents-produits-direction/{$document->id}/valider")
            ->assertOk();
        $document->refresh();
        $chemin = $document->pdf_officiel_chemin;
        $contenuInitial = Storage::disk('local')->get($chemin);
        $reference = $document->courrier->reference_documentaire;
        $contenuValide = $document->courrier->contenu;
        $empreinte = $document->pdf_officiel_sha256;
        $versionPdf = $document->pdf_officiel_version_contenu;
        $genereAt = $document->pdf_officiel_genere_at;

        $pdf = Mockery::mock(PdfGenerationService::class);
        $pdf->shouldNotReceive('genererDepuisVue');
        $this->app->instance(PdfGenerationService::class, $pdf);

        $reponseSecretaire = $this->actingAs($secretariat)
            ->get("/api/v1/documents-produits-direction/{$document->id}/pdf-officiel")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->actingAs($directeur)
            ->get("/api/v1/documents-produits-direction/{$document->id}/pdf-officiel")
            ->assertOk();
        $this->actingAs($secretariat)
            ->postJson("/api/v1/documents-produits-direction/{$document->id}/soumettre", ['version_contenu' => 1])
            ->assertForbidden();
        $this->actingAs($directeur)
            ->postJson("/api/v1/documents-produits-direction/{$document->id}/valider")
            ->assertForbidden();
        $this->actingAs($secretariat)
            ->patchJson("/api/v1/documents-produits-direction/{$document->id}/brouillon", [
                'version_contenu' => 1,
                'objet' => 'Tentative de modification',
                'contenu' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Modifié']]]]],
                'pdf_officiel_chemin' => 'documents-internes/officiels/faux.pdf',
            ])
            ->assertForbidden();

        $this->assertSame($contenuInitial, Storage::disk('local')->get($chemin));
        $this->assertSame($contenuInitial, $reponseSecretaire->streamedContent());
        $this->assertSame(hash('sha256', $reponseSecretaire->streamedContent()), $empreinte);
        $this->assertSame($reference, $document->courrier->fresh()->reference_documentaire);
        $this->assertSame($contenuValide, $document->courrier->fresh()->contenu);
        $this->assertSame('Objet officiel', $document->courrier->fresh()->objet);
        $document->refresh();
        $this->assertSame($chemin, $document->pdf_officiel_chemin);
        $this->assertSame($empreinte, $document->pdf_officiel_sha256);
        $this->assertSame($versionPdf, $document->pdf_officiel_version_contenu);
        $this->assertSame($genereAt->toISOString(), $document->pdf_officiel_genere_at->toISOString());
    }

    public function test_une_seconde_validation_ne_cree_ni_pdf_ni_reference_supplementaire(): void
    {
        [$document, , $directeur] = $this->preparerDocument();
        $this->actingAs($directeur)
            ->postJson("/api/v1/documents-produits-direction/{$document->id}/valider")
            ->assertOk();

        $document->refresh();
        $reference = $document->courrier->reference_documentaire;
        $chemin = $document->pdf_officiel_chemin;
        $this->assertNotNull($reference);
        $this->assertSame(1, count(Storage::disk('local')->allFiles('documents-internes/officiels')));

        $this->actingAs($directeur)
            ->postJson("/api/v1/documents-produits-direction/{$document->id}/valider")
            ->assertForbidden();

        $document->refresh();
        $this->assertSame($reference, $document->courrier->reference_documentaire);
        $this->assertSame($chemin, $document->pdf_officiel_chemin);
        $this->assertSame(1, count(Storage::disk('local')->allFiles('documents-internes/officiels')));
    }

    public function test_validation_non_autonome_preserve_exactement_le_payload_historique_de_audit(): void
    {
        $centrale = Direction::factory()->create();
        $direction = Direction::factory()->create();
        $dg = User::query()->where('poste', Poste::DG)->orderBy('id')->first()
            ?? $this->agent(Poste::DG, $centrale);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $centrale);
        $secretariat = User::factory()->secretariatDirection($direction)->create(['initiales_reference' => 'SEC']);
        $directeur = User::factory()->directeurDirection($direction)->create(['initiales_reference' => 'ABC']);
        $source = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$source->id}/dispatchs", [
            'destinations' => [['type' => 'direction', 'direction_id' => $direction->id, 'instruction' => 'Traiter']],
        ])->assertOk();
        $dispatch = $source->dispatchs()->firstOrFail();
        $this->receptionnerDispatchSec2($dispatch, $sec2);
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertOk();
        $this->actingAs($secretariat)->postJson("/api/v1/dispatchs/{$dispatch->id}/accuser-reception")->assertOk();
        $traitement = TraitementDirection::query()->where('dispatch_courrier_id', $dispatch->id)->firstOrFail();
        $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/transmettre-directeur")->assertOk();
        $this->actingAs($directeur)->postJson("/api/v1/traitements-direction/{$traitement->id}/prendre-en-charge")->assertOk();
        $this->actingAs($directeur)->postJson("/api/v1/traitements-direction/{$traitement->id}/decision", [
            'decision' => 'retour_a_preparer',
        ])->assertOk();

        $id = $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/document-produit", [
            'objet' => 'Document produit historique',
            'contenu' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Contenu']]]]],
        ])->assertCreated()->json('data.id');
        $this->actingAs($secretariat)->postJson("/api/v1/documents-produits-direction/{$id}/soumettre")->assertOk();

        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('enregistrer')
            ->once()
            ->withArgs(fn (...$args): bool => ($args[0] ?? null) === 'courrier.reference_documentaire_interne_attribuee');
        $audit->shouldReceive('enregistrer')
            ->once()
            ->withArgs(fn (...$args): bool => ($args[0] ?? null) === 'document_direction.valide'
                && is_array($args[3] ?? null)
                && array_keys($args[3]) === ['reference_documentaire']
                && is_string($args[3]['reference_documentaire'] ?? null));
        $this->app->instance(AuditLogger::class, $audit);

        $this->actingAs($directeur)
            ->postJson("/api/v1/documents-produits-direction/{$id}/valider")
            ->assertOk()
            ->assertJsonPath('data.statut', DocumentProduitStatut::VALIDE->value);
    }

    public function test_telechargement_exige_une_authentification(): void
    {
        [$document] = $this->preparerDocument();
        Auth::forgetGuards();

        $this->get("/api/v1/documents-produits-direction/{$document->id}/pdf-officiel")
            ->assertUnauthorized();
    }

    public function test_telechargement_refuse_les_acteurs_hors_perimetre_et_les_documents_historiques_sans_pdf(): void
    {
        [$document, , $directeur] = $this->preparerDocument();
        $this->actingAs($directeur)
            ->postJson("/api/v1/documents-produits-direction/{$document->id}/valider")
            ->assertOk();

        $document->refresh();
        $document->pdf_officiel_chemin = null;
        $document->pdf_officiel_sha256 = null;
        $document->pdf_officiel_version_contenu = null;
        $document->pdf_officiel_genere_at = null;
        $document->save();

        $autreDirection = Direction::factory()->create();
        $autresActeurs = [
            User::factory()->secretariatDirection($autreDirection)->create(),
            User::factory()->directeurDirection($autreDirection)->create(),
            User::factory()->agentCircuitCourrier(Poste::RECEPTION)->create(),
            User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_1)->create(),
            User::factory()->agentCircuitCourrier(Poste::DG)->create(),
            User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_2)->create(),
            User::factory()->administrateur()->create(),
        ];

        foreach ($autresActeurs as $acteur) {
            $this->actingAs($acteur)
                ->get("/api/v1/documents-produits-direction/{$document->id}/pdf-officiel")
                ->assertNotFound();
        }

        $this->actingAs($directeur)
            ->get("/api/v1/documents-produits-direction/{$document->id}/pdf-officiel")
            ->assertNotFound();
        $this->assertSame(DocumentProduitStatut::VALIDE, $document->fresh()->statut);
    }

    public function test_absence_ou_alteration_du_pdf_ne_conduit_jamais_a_une_regeneration(): void
    {
        [$document, , $directeur] = $this->preparerDocument();
        $this->actingAs($directeur)
            ->postJson("/api/v1/documents-produits-direction/{$document->id}/valider")
            ->assertOk();
        $document->refresh();
        Storage::disk('local')->put($document->pdf_officiel_chemin, '%PDF-corrompu');

        $pdf = Mockery::mock(PdfGenerationService::class);
        $pdf->shouldNotReceive('genererDepuisVue');
        $this->app->instance(PdfGenerationService::class, $pdf);

        $this->actingAs($directeur)
            ->get("/api/v1/documents-produits-direction/{$document->id}/pdf-officiel")
            ->assertNotFound();
    }

    public function test_reconciliation_signale_et_conserve_un_fichier_potentiellement_orphelin(): void
    {
        $chemin = 'documents-internes/officiels/.staging/orphelin.pdf';
        $disque = Storage::disk('local');
        $disque->put($chemin, '%PDF-orphelin');

        $this->artisan('courrier:reconcilier-pdf-officiels')
            ->assertSuccessful()
            ->expectsOutput("Fichier potentiellement orphelin conservé : {$chemin}")
            ->expectsOutput('1 fichier(s) potentiellement orphelin(s) signalé(s), aucun fichier supprimé.');

        $disque->assertExists($chemin);
    }

    private function preparerDocument(?array $contenu = null): array
    {
        $direction = Direction::factory()->create();
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        $directeur = User::factory()->directeurDirection($direction)->create(['initiales_reference' => 'ABC']);
        $contenu ??= [
            'type' => 'doc',
            'content' => [
                ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Titre officiel']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Texte officiel exact.', 'marks' => [['type' => 'bold']]]]],
                ['type' => 'bulletList', 'content' => [[
                    'type' => 'listItem',
                    'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Point fidèle.']]]],
                ]]],
            ],
        ];

        $creation = $this->actingAs($secretariat)
            ->postJson('/api/v1/documents-produits-direction/autonomes', [
                'objet' => 'Objet officiel',
                'contenu' => $contenu,
            ])
            ->assertCreated();
        $document = DocumentProduitDirection::query()->findOrFail($creation->json('data.id'));

        $this->actingAs($secretariat)
            ->postJson("/api/v1/documents-produits-direction/{$document->id}/soumettre", ['version_contenu' => 1])
            ->assertOk();

        return [$document->fresh(), $secretariat, $directeur];
    }
}
