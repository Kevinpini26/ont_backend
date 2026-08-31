<?php

namespace Modules\Courrier\Contracts;

use Illuminate\Support\Collection;
use Modules\Courrier\Models\Courrier;

/**
 * Point d'extension : produit la feuille de couverture imprimable d'un
 * courrier (ou d'un lot de courriers, une feuille par page) — le
 * numéro d'accusé de réception en code-barres permet de rattacher un scan
 * fait au copieur/USB au bon courrier sans ressaisie (voir
 * docs/numerisation-courrier.md). Retourne le contenu binaire du PDF,
 * jamais un chemin, même convention que les autres générateurs du projet.
 */
interface FeuilleCouvertureGenerator
{
    public function generer(Courrier $courrier, ?int $nombrePagesAttendues = null): string;

    /**
     * @param  Collection<int, Courrier>  $courriers
     */
    public function genererLot(Collection $courriers): string;
}
