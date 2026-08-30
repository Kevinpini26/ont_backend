<?php

namespace Modules\Stagiaires\Enums;

enum TypeLienPublic: string
{
    case CONVENTION = 'convention';
    case RETOUR_EXPERIENCE = 'retour_experience';
    case RAPPORT_STAGE = 'rapport_stage';
}
