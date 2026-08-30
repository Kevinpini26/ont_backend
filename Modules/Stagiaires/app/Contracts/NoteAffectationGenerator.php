<?php

namespace Modules\Stagiaires\Contracts;

use Modules\Stagiaires\Models\Stagiaire;

/**
 * Point d'extension : génère le PDF de note d'affectation, à l'affectation
 * du stagiaire à une direction d'accueil.
 */
interface NoteAffectationGenerator
{
    /**
     * @return string Chemin de stockage (disk "local") du PDF généré.
     */
    public function generer(Stagiaire $stagiaire): string;
}
