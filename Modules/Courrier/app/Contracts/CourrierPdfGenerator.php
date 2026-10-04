<?php

namespace Modules\Courrier\Contracts;

use Modules\Courrier\Models\Courrier;

/**
 * Point d'extension des documents PDF de courrier : PDF final des circuits
 * historiques et document intermédiaire préparé pour signature physique.
 */
interface CourrierPdfGenerator
{
    /**
     * @return string Chemin de stockage (disk "local") du PDF généré.
     */
    public function generer(Courrier $courrier): string;

    /** @return string Chemin privé du PDF préparé pour signature. */
    public function genererPourSignature(Courrier $courrier, string $sourceAutorite): string;

    /** Écrit le PDF pré-signature au chemin fourni sans choisir son emplacement. */
    public function genererPourSignatureDans(Courrier $courrier, string $sourceAutorite, string $chemin): void;
}
