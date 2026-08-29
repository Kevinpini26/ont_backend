<?php

namespace Modules\Stagiaires\Enums;

enum StagiaireOrigine: string
{
    case SYSTEME = 'systeme';
    case IMPORT_HISTORIQUE = 'import_historique';

    public function label(): string
    {
        return match ($this) {
            self::SYSTEME => 'Créé par le système',
            self::IMPORT_HISTORIQUE => 'Importé (historique antérieur)',
        };
    }
}
