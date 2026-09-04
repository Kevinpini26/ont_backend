<?php

namespace Modules\Stagiaires\Contracts;

use Modules\Stagiaires\Models\TableauRepartition;

/**
 * Point d'extension : génère le PDF du tableau de répartition, à
 * l'approbation par la Direction Générale — en-tête ONT, une ligne par
 * stagiaire, cartouche de signature DG.
 */
interface TableauRepartitionPdfGenerator
{
    /**
     * @return string Chemin de stockage (disk "local") du PDF généré.
     */
    public function generer(TableauRepartition $tableau): string;
}
