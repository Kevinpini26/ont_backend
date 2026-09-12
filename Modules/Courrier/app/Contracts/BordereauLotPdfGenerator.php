<?php

namespace Modules\Courrier\Contracts;

use Modules\Courrier\Models\BordereauLot;

/**
 * Point d'extension : produit le bordereau de lot imprimable (Lot C, point
 * 2) — en-tête ONT, numéro, date, postes émetteur/destinataire, liste des
 * dossiers, deux cases de signature. Retourne le contenu binaire du PDF,
 * même convention que les autres générateurs du module.
 */
interface BordereauLotPdfGenerator
{
    public function generer(BordereauLot $bordereau): string;
}
