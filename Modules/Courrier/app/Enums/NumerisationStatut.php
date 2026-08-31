<?php

namespace Modules\Courrier\Enums;

enum NumerisationStatut: string
{
    case NUMERISE = 'numerise';
    case A_NUMERISER = 'a_numeriser';
    case NON_APPLICABLE = 'non_applicable';

    public function label(): string
    {
        return match ($this) {
            self::NUMERISE => 'Numérisé',
            self::A_NUMERISER => 'À numériser',
            self::NON_APPLICABLE => 'Non applicable',
        };
    }
}
