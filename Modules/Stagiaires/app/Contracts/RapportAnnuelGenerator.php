<?php

namespace Modules\Stagiaires\Contracts;

/**
 * Point d'extension : produit le rapport annuel consolidé destiné à la
 * tutelle — retourne le contenu binaire du PDF, jamais un chemin, même
 * convention que Courrier\Contracts\RegistreCourrierPdfGenerator.
 */
interface RapportAnnuelGenerator
{
    public function generer(int $annee): string;
}
