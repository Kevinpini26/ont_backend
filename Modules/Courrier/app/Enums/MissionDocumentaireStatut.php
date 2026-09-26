<?php

namespace Modules\Courrier\Enums;

enum MissionDocumentaireStatut: string
{
    case ASSIGNEE = 'assignee';
    case EN_COURS = 'en_cours';
    case RETOURNEE = 'retournee';
    case ANNULEE = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::ASSIGNEE => 'Assignée',
            self::EN_COURS => 'En cours',
            self::RETOURNEE => 'Retournée',
            self::ANNULEE => 'Annulée',
        };
    }

    public function estActive(): bool
    {
        return $this === self::ASSIGNEE || $this === self::EN_COURS;
    }
}
