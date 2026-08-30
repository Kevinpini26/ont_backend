<?php

namespace Modules\Stagiaires\Contracts;

use Modules\Stagiaires\Models\Stagiaire;

/**
 * Point d'extension : génère le PDF d'engagement de confidentialité,
 * document distinct de la convention de stage (voir ConventionGenerator).
 */
interface EngagementConfidentialiteGenerator
{
    /**
     * @return string Chemin de stockage (disk "local") du PDF généré.
     */
    public function generer(Stagiaire $stagiaire): string;
}
