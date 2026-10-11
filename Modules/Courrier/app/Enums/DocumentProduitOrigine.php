<?php

namespace Modules\Courrier\Enums;

enum DocumentProduitOrigine: string
{
    case TRAITEMENT_DIRECTION = 'traitement_direction';
    case AUTONOME = 'autonome';
}
