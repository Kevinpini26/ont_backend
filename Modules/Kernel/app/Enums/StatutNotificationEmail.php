<?php

namespace Modules\Kernel\Enums;

enum StatutNotificationEmail: string
{
    case EN_FILE = 'en_file';
    case ENVOYE = 'envoye';
    case ECHEC = 'echec';

    public function label(): string
    {
        return match ($this) {
            self::EN_FILE => 'En file d’attente',
            self::ENVOYE => 'Envoyé',
            self::ECHEC => 'Échec',
        };
    }
}
