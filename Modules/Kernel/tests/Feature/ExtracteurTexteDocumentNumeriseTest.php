<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Kernel\Support\ExtracteurTexteDocumentNumerise;
use Tests\TestCase;

/**
 * Extraction du texte d'un document numérisé — voir
 * docs/numerisation-courrier.md (Lot 4).
 */
class ExtracteurTexteDocumentNumeriseTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_texte_deja_embarque_dans_un_pdf_est_extrait_directement(): void
    {
        // Un PDF réel, généré par le même moteur (Dompdf) que le reste du
        // système — contient un vrai texte embarqué, pas une image.
        $pdf = app(PdfGenerationService::class)->genererDepuisVue('kernel::test-texte-simple', [
            'phrase' => 'Rapport annuel de la Direction de la Formation MOTCLEUNIQUE9284',
        ]);

        $texte = app(ExtracteurTexteDocumentNumerise::class)->extraire($pdf);

        $this->assertNotNull($texte);
        $this->assertStringContainsString('MOTCLEUNIQUE9284', $texte);
    }

    public function test_locr_reste_desactive_par_defaut_et_ne_plante_jamais(): void
    {
        config(['kernel.ocr.active' => false]);

        // Contenu binaire quelconque, ni PDF valide ni texte : simule un
        // fichier illisible par smalot/pdfparser. L'extracteur ne doit
        // jamais lever d'exception, seulement renvoyer null.
        $texte = app(ExtracteurTexteDocumentNumerise::class)->extraire('contenu binaire invalide');

        $this->assertNull($texte);
    }
}
