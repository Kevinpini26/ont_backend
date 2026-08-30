<?php

namespace Modules\Courrier\Enums;

enum SensCourrier: string
{
    case ENTRANT = 'entrant';
    case SORTANT = 'sortant';

    public function label(): string
    {
        return match ($this) {
            self::ENTRANT => 'Entrant',
            self::SORTANT => 'Sortant',
        };
    }
}
