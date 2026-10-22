<?php

namespace Modules\Courrier\Services;

use Modules\Courrier\Models\DocumentProduitDirection;

/**
 * Primitive transverse partagée entre les services spécialisés de
 * DocumentProduitDirectionService (rédaction, PDF officiel, réception) —
 * chargement standard des relations après toute écriture, pour qu'une
 * réponse API expose toujours le même graphe de relations quel que soit le
 * service qui a traité l'action.
 */
class DocumentProduitDirectionHelpers
{
    public function charger(DocumentProduitDirection $document): DocumentProduitDirection
    {
        return $document->load(['courrier', 'documentSource', 'direction', 'traitement.directeur', 'directeur', 'creePar', 'validePar']);
    }
}
