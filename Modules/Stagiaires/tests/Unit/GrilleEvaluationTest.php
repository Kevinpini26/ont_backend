<?php

namespace Modules\Stagiaires\Tests\Unit;

use Modules\Stagiaires\Support\GrilleEvaluation;
use PHPUnit\Framework\TestCase;

class GrilleEvaluationTest extends TestCase
{
    public function test_une_grille_vide_totalise_zero(): void
    {
        $this->assertSame(0.0, GrilleEvaluation::total([]));
    }

    public function test_une_grille_partielle_ne_compte_que_les_criteres_renseignes(): void
    {
        $total = GrilleEvaluation::total([
            'aptitudes_professionnelles' => ['connaissance_metier' => 8],
        ]);

        $this->assertSame(8.0, $total);
    }

    public function test_une_grille_completee_au_maximum_totalise_cent(): void
    {
        $grille = [
            'aptitudes_professionnelles' => [
                'connaissance_metier' => 10, 'esprit_initiative' => 10, 'sens_responsabilite' => 10,
                'soin_proprete' => 10, 'rendement' => 10,
            ],
            'relations_humaines' => [
                'esprit_equipe' => 10, 'communication' => 10, 'relations_sociales' => 10,
            ],
            'presentation' => [
                'discipline' => 5, 'ponctualite' => 5, 'regularite' => 5, 'tenue' => 5,
            ],
        ];

        $this->assertSame(100.0, GrilleEvaluation::total($grille));
    }

    public function test_les_valeurs_non_numeriques_sont_traitees_comme_zero(): void
    {
        $total = GrilleEvaluation::total([
            'aptitudes_professionnelles' => ['connaissance_metier' => ''],
        ]);

        $this->assertSame(0.0, $total);
    }

    public function test_le_total_est_arrondi_a_deux_decimales(): void
    {
        $total = GrilleEvaluation::total([
            'aptitudes_professionnelles' => ['connaissance_metier' => 7.333],
        ]);

        $this->assertSame(7.33, $total);
    }

    public function test_les_regles_de_validation_couvrent_les_trois_sections_avec_le_bon_bareme_par_critere(): void
    {
        $regles = GrilleEvaluation::rules();

        $this->assertSame(['required', 'numeric', 'min:0', 'max:10'], $regles['grille.aptitudes_professionnelles.connaissance_metier']);
        $this->assertSame(['required', 'numeric', 'min:0', 'max:5'], $regles['grille.presentation.ponctualite']);
        $this->assertArrayHasKey('grille.aptitudes_professionnelles.justification', $regles);
    }

    public function test_le_prefixe_des_regles_est_personnalisable(): void
    {
        $regles = GrilleEvaluation::rules('evaluation_direction');

        $this->assertArrayHasKey('evaluation_direction.presentation.tenue', $regles);
    }
}
