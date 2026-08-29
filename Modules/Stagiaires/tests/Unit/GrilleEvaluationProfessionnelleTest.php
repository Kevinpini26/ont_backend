<?php

namespace Modules\Stagiaires\Tests\Unit;

use Modules\Stagiaires\Support\GrilleEvaluationProfessionnelle;
use PHPUnit\Framework\TestCase;

class GrilleEvaluationProfessionnelleTest extends TestCase
{
    public function test_une_grille_vide_totalise_zero(): void
    {
        $this->assertSame(0.0, GrilleEvaluationProfessionnelle::total([]));
    }

    public function test_une_grille_partielle_ne_compte_que_les_criteres_renseignes(): void
    {
        $total = GrilleEvaluationProfessionnelle::total([
            'aspects_humains' => ['ponctualite_regularite' => 6],
        ]);

        $this->assertSame(6.0, $total);
    }

    public function test_une_grille_completee_au_maximum_totalise_cent(): void
    {
        $grille = [
            'aspects_intellectuels' => ['connaissance_metier' => 10, 'esprit_initiative_responsabilite' => 10, 'capacite_ecoute_communication' => 10],
            'aspects_humains' => ['assiduite_discipline' => 10, 'relation_interpersonnelle' => 10, 'ponctualite_regularite' => 10, 'presentation_contacts' => 10],
            'aspects_professionnels' => ['efficacite_rendement' => 10, 'capacite_innovation' => 10, 'maitrise_langue' => 10],
        ];

        $this->assertSame(100.0, GrilleEvaluationProfessionnelle::total($grille));
    }

    public function test_les_dix_rubriques_sont_notees_sur_dix_dans_les_regles_de_validation(): void
    {
        $regles = GrilleEvaluationProfessionnelle::rules();

        $this->assertCount(10, $regles);
        $this->assertSame(['required', 'numeric', 'min:0', 'max:10'], $regles['grille.aspects_professionnels.maitrise_langue']);
    }
}
