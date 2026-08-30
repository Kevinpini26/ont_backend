<?php

namespace Modules\Courrier\Contracts;

use Illuminate\Support\Carbon;

/**
 * Point d'extension : produit le registre imprimable d'une période — le
 * document que le Secrétariat Général signe et que la tutelle réclame en
 * cas de contrôle. Retourne le contenu binaire du PDF (jamais un chemin :
 * à l'appelant — commande ou route de téléchargement — de décider quoi en
 * faire), même convention que Kernel\Contracts\PdfGenerationService.
 */
interface RegistreCourrierPdfGenerator
{
    /**
     * @param  'arrivee'|'depart'  $type
     */
    public function generer(string $type, Carbon $debut, Carbon $fin): string;
}
