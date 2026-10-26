<?php

namespace Modules\Courrier\Support;

/** Format officiel NNN/ONT/<code direction>/AAAA ; l'ancien format NNN/INITIALES/CODE/ONT/AAAA reste accepté pour les références déjà émises. */
final class ReferenceDocumentaire
{
    private const FORMAT_ACTUEL = '/\A\d{3,}\/ONT\/[A-Z0-9]{1,20}\/\d{4}\z/';

    private const FORMAT_HISTORIQUE = '/\A\d{3,}\/[A-Z]{1,12}\/[A-Z0-9]{1,20}\/ONT\/\d{4}\z/';

    public static function estValide(?string $reference): bool
    {
        if ($reference === null) {
            return false;
        }

        return preg_match(self::FORMAT_ACTUEL, $reference) === 1
            || preg_match(self::FORMAT_HISTORIQUE, $reference) === 1;
    }
}
