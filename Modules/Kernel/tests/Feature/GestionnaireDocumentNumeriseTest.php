<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Kernel\Enums\QualiteDocumentNumerise;
use Modules\Kernel\Enums\SourceDocumentNumerise;
use Modules\Kernel\Exceptions\DocumentNumeriseRejeteException;
use Modules\Kernel\Support\GestionnaireDocumentNumerise;
use Modules\Stagiaires\Models\Stagiaire;
use Tests\TestCase;

/**
 * Contrôles de qualité côté serveur, sans extension image (voir
 * CompteurPagesPdf/docs/numerisation-courrier.md).
 */
class GestionnaireDocumentNumeriseTest extends TestCase
{
    use RefreshDatabase;

    private function contenuPdfFactice(int $nombrePages): string
    {
        return str_repeat("/Type /Page\n", $nombrePages).'%PDF fin de fichier factice.';
    }

    public function test_un_document_de_poids_suffisant_est_accepte_en_bonne_qualite(): void
    {
        Storage::fake('local');
        $stagiaire = Stagiaire::factory()->create();
        // 2 pages, largement au-dessus du seuil de 20 ko/page.
        $contenu = $this->contenuPdfFactice(2).str_repeat('x', 60 * 1024);
        Storage::disk('local')->put('numerisations/test.pdf', $contenu);

        $document = app(GestionnaireDocumentNumerise::class)->enregistrerVersion(
            $stagiaire,
            'numerisations/test.pdf',
            SourceDocumentNumerise::TELEPHONE,
        );

        $this->assertSame(2, $document->nombre_pages);
        $this->assertSame(QualiteDocumentNumerise::BONNE, $document->qualite);
        $this->assertSame(1, $document->version);
    }

    public function test_un_document_trop_leger_par_page_est_rejete(): void
    {
        Storage::fake('local');
        $stagiaire = Stagiaire::factory()->create();
        // 5 pages "annoncées" par la structure, mais un fichier minuscule :
        // largement sous le seuil de 20 ko/page.
        Storage::disk('local')->put('numerisations/illisible.pdf', $this->contenuPdfFactice(5));

        $this->expectException(DocumentNumeriseRejeteException::class);

        app(GestionnaireDocumentNumerise::class)->enregistrerVersion(
            $stagiaire,
            'numerisations/illisible.pdf',
            SourceDocumentNumerise::TELEPHONE,
        );
    }

    public function test_un_ecart_entre_pages_annoncees_et_detectees_marque_la_qualite_faible(): void
    {
        Storage::fake('local');
        $stagiaire = Stagiaire::factory()->create();
        $contenu = $this->contenuPdfFactice(3).str_repeat('x', 90 * 1024);
        Storage::disk('local')->put('numerisations/ecart.pdf', $contenu);

        $document = app(GestionnaireDocumentNumerise::class)->enregistrerVersion(
            $stagiaire,
            'numerisations/ecart.pdf',
            SourceDocumentNumerise::TELEPHONE,
            null,
            5, // l'agent avait annoncé 5 pages, seules 3 sont détectées
        );

        $this->assertSame(QualiteDocumentNumerise::FAIBLE, $document->qualite);
    }

    public function test_les_versions_sont_sequentielles_par_document(): void
    {
        Storage::fake('local');
        $stagiaire = Stagiaire::factory()->create();
        $contenu = $this->contenuPdfFactice(1).str_repeat('x', 30 * 1024);
        Storage::disk('local')->put('numerisations/v1.pdf', $contenu);
        Storage::disk('local')->put('numerisations/v2.pdf', $contenu);

        $gestionnaire = app(GestionnaireDocumentNumerise::class);
        $v1 = $gestionnaire->enregistrerVersion($stagiaire, 'numerisations/v1.pdf', SourceDocumentNumerise::TELEPHONE);
        $v2 = $gestionnaire->enregistrerVersion($stagiaire, 'numerisations/v2.pdf', SourceDocumentNumerise::TELEPHONE);

        $this->assertSame(1, $v1->version);
        $this->assertSame(2, $v2->version);
    }
}
