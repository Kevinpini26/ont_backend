<?php

namespace Modules\Courrier\Enums;

enum ClassementDocumentStatut: string
{
    case CLASSE = 'classe';
    case ARCHIVE = 'archive';

    public function label(): string
    {
        return $this === self::CLASSE ? 'Classé' : 'Archivé';
    }
}
