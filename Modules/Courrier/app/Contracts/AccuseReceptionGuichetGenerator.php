<?php

namespace Modules\Courrier\Contracts;

use Modules\Courrier\Models\Courrier;

/**
 * Produit la fiche d'accusé de réception imprimable pour un courrier
 * enregistré au guichet (Réception) sans adresse email d'expéditeur ou de
 * candidat — seul moyen de confirmer la réception à quelqu'un qui n'a
 * donné aucun contact électronique. Contrairement à la feuille de
 * couverture (FeuilleCouvertureGenerator), aucun code-barres ni QR : ce
 * courrier n'a pas de numero_accuse_reception public (voir
 * DossierPublicController, qui ne recherche que par ce champ), donc rien
 * à faire suivre électroniquement. Retourne le contenu binaire du PDF,
 * jamais un chemin, même convention que les autres générateurs du projet.
 */
interface AccuseReceptionGuichetGenerator
{
    public function generer(Courrier $courrier): string;
}
