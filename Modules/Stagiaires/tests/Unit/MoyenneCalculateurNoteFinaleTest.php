<?php

namespace Modules\Stagiaires\Tests\Unit;

use Modules\Stagiaires\Support\MoyenneCalculateurNoteFinale;
use PHPUnit\Framework\TestCase;

class MoyenneCalculateurNoteFinaleTest extends TestCase
{
    private MoyenneCalculateurNoteFinale $calculateur;

    protected function setUp(): void
    {
        $this->calculateur = new MoyenneCalculateurNoteFinale;
    }

    public function test_deux_notes_egales_donnent_la_meme_note_finale(): void
    {
        $this->assertSame(75.0, $this->calculateur->calculer(75.0, 75.0));
    }

    public function test_deux_notes_nulles_donnent_une_note_finale_nulle(): void
    {
        $this->assertSame(0.0, $this->calculateur->calculer(0.0, 0.0));
    }

    public function test_la_moyenne_est_arrondie_a_deux_decimales(): void
    {
        $this->assertSame(66.67, $this->calculateur->calculer(70.0, 63.33));
    }

    public function test_une_note_nulle_et_une_note_maximale_donnent_la_moitie(): void
    {
        $this->assertSame(50.0, $this->calculateur->calculer(0.0, 100.0));
    }
}
