<?php

namespace Modules\Stagiaires\Contracts;

use Modules\Stagiaires\Models\Stagiaire;

/**
 * Point d'extension : génère le PDF de certificat de fin de stage, à la
 * clôture — distinct de AttestationGenerator, qui expose la note finale
 * (voir StagiairePolicy::voirEvaluationFinale()) : le certificat ne
 * mentionne jamais l'évaluation.
 */
interface CertificatGenerator
{
    /**
     * @return string Chemin de stockage (disk "local") du PDF généré.
     */
    public function generer(Stagiaire $stagiaire): string;
}
