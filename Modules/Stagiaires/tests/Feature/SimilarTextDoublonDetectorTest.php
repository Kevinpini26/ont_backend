<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Support\SimilarTextDoublonDetector;
use Tests\TestCase;

/**
 * S'appuie sur similar_text() en PHP plutôt que sur une requête SQL floue
 * (voir la classe elle-même) : nécessite donc de vraies lignes en base,
 * pas un test unitaire pur malgré l'absence de logique métier autre que
 * le calcul de similarité.
 */
class SimilarTextDoublonDetectorTest extends TestCase
{
    use RefreshDatabase;

    private function detecteur(): SimilarTextDoublonDetector
    {
        return new SimilarTextDoublonDetector;
    }

    public function test_une_egalite_parfaite_de_nom_et_detablissement_est_detectee(): void
    {
        $existant = Stagiaire::factory()->create([
            'nom' => 'Kabasele Jean Pierre',
            'etablissement_origine' => 'Université de Kinshasa',
        ]);
        $candidat = Stagiaire::factory()->create([
            'nom' => 'Kabasele Jean Pierre',
            'etablissement_origine' => 'Université de Kinshasa',
        ]);

        $doublon = $this->detecteur()->trouverDoublon($candidat);

        $this->assertNotNull($doublon);
        $this->assertSame($existant->id, $doublon->id);
    }

    public function test_la_comparaison_ignore_la_casse_et_les_espaces_superflus(): void
    {
        Stagiaire::factory()->create([
            'nom' => 'Kabasele Jean Pierre',
            'etablissement_origine' => 'Université de Kinshasa',
        ]);
        $candidat = Stagiaire::factory()->create([
            'nom' => '  KABASELE JEAN PIERRE  ',
            'etablissement_origine' => '  université de kinshasa  ',
        ]);

        $this->assertNotNull($this->detecteur()->trouverDoublon($candidat));
    }

    public function test_un_nom_totalement_different_nest_pas_signale(): void
    {
        Stagiaire::factory()->create([
            'nom' => 'Kabasele Jean Pierre',
            'etablissement_origine' => 'Université de Kinshasa',
        ]);
        $candidat = Stagiaire::factory()->create([
            'nom' => 'Mukendi Marie Claire',
            'etablissement_origine' => 'Institut Supérieur de Commerce',
        ]);

        $this->assertNull($this->detecteur()->trouverDoublon($candidat));
    }

    /**
     * Même nom, établissement différent : ni l'un ni l'autre critère seul
     * ne suffit, les deux doivent franchir le seuil.
     */
    public function test_un_nom_identique_mais_un_etablissement_different_nest_pas_un_doublon(): void
    {
        Stagiaire::factory()->create([
            'nom' => 'Kabasele Jean Pierre',
            'etablissement_origine' => 'Université de Kinshasa',
        ]);
        $candidat = Stagiaire::factory()->create([
            'nom' => 'Kabasele Jean Pierre',
            'etablissement_origine' => 'Institut Supérieur de Commerce',
        ]);

        $this->assertNull($this->detecteur()->trouverDoublon($candidat));
    }

    public function test_le_candidat_lui_meme_nest_jamais_compare_a_lui_meme(): void
    {
        $candidat = Stagiaire::factory()->create([
            'nom' => 'Kabasele Jean Pierre',
            'etablissement_origine' => 'Université de Kinshasa',
        ]);

        $this->assertNull($this->detecteur()->trouverDoublon($candidat));
    }
}
