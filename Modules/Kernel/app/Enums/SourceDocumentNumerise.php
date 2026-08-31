<?php

namespace Modules\Kernel\Enums;

/**
 * Origine d'un document numérisé — voir Modules\Kernel\Models\DocumentNumerise.
 * Ne distingue pas "capture mobile" de "import par lot" au niveau du canal
 * physique : COPIEUR couvre aussi bien un dépôt par clé USB qu'une future
 * surveillance de dossier réseau (voir SourceNumerisation, lot 3).
 */
enum SourceDocumentNumerise: string
{
    case TELEPHONE = 'telephone';
    case COPIEUR = 'copieur';
    case TELEVERSEMENT = 'televersement';

    public function label(): string
    {
        return match ($this) {
            self::TELEPHONE => 'Capture mobile (téléphone)',
            self::COPIEUR => 'Copieur multifonction',
            self::TELEVERSEMENT => 'Téléversement direct',
        };
    }
}
