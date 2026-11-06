<?php

namespace Tests\Feature;

use Modules\Courrier\Database\Seeders\CourrierDemoSeeder;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

/**
 * Les pièces jointes de démonstration sont de vrais PDF, lisibles par un lecteur.
 */
class DemoPdfLisibleTest extends TestCase
{
    public function test_la_piece_de_demonstration_est_un_pdf_valide_et_lisible(): void
    {
        $contenu = CourrierDemoSeeder::pdfDeDemonstration('Courrier de démonstration');

        $texte = (new Parser)->parseContent($contenu)->getText();

        $this->assertStringContainsString('Courrier de d', $texte);
    }
}
