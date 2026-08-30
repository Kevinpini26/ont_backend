<?php

namespace Modules\Courrier\Enums;

enum DegreUrgence: string
{
    case NORMAL = 'normal';
    case URGENT = 'urgent';
    case TRES_URGENT = 'tres_urgent';

    public function label(): string
    {
        return match ($this) {
            self::NORMAL => 'Normal',
            self::URGENT => 'Urgent',
            self::TRES_URGENT => 'Très urgent',
        };
    }
}
