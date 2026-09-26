<?php

namespace Modules\Stagiaires\Support;

use Modules\Stagiaires\Contracts\CalculateurNoteFinale;

class MoyenneCalculateurNoteFinale implements CalculateurNoteFinale
{
    public function calculer(float $noteDirection, float $noteDfp): float
    {
        // Les notes sont saisies à deux décimales. Travailler en centièmes
        // évite que la représentation binaire de 66,665 devienne
        // 66,664999… et soit arrondie à tort vers 66,66.
        $directionEnCentiemes = (int) round($noteDirection * 100);
        $dfpEnCentiemes = (int) round($noteDfp * 100);

        return round(($directionEnCentiemes + $dfpEnCentiemes) / 2) / 100;
    }
}
