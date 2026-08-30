<?php

namespace Modules\Stagiaires\Contracts;

use Modules\Stagiaires\Models\Stagiaire;

/**
 * Point d'extension : produit le badge imprimable du stagiaire, à la
 * demande — jamais stocké (réimprimable à tout moment), même convention
 * que Courrier\Contracts\RegistreCourrierPdfGenerator : retourne le
 * contenu binaire du PDF, jamais un chemin.
 */
interface BadgeStagiairePdfGenerator
{
    public function generer(Stagiaire $stagiaire): string;
}
