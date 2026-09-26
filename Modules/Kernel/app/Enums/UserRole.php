<?php

namespace Modules\Kernel\Enums;

enum UserRole: string
{
    case AGENT_CIRCUIT_COURRIER = 'agent_circuit_courrier';
    /** Valeur historique, conservée pour hydrater les comptes existants. */
    case RESPONSABLE_DIRECTION = 'responsable_direction';
    case DIRECTEUR_DIRECTION = 'directeur_direction';
    case SECRETARIAT_DIRECTION = 'secretariat_direction';
    case AGENT_DFP = 'agent_dfp';
    case ADMINISTRATEUR = 'administrateur';

    public function label(): string
    {
        return match ($this) {
            self::AGENT_CIRCUIT_COURRIER => 'Agent de circuit courrier',
            self::RESPONSABLE_DIRECTION => 'Directeur de direction (rôle historique)',
            self::DIRECTEUR_DIRECTION => 'Directeur de direction',
            self::SECRETARIAT_DIRECTION => 'Secrétariat de direction',
            self::AGENT_DFP => 'Agent DFP',
            self::ADMINISTRATEUR => 'Administrateur',
        };
    }

    /**
     * Roles for which a direction_id is required on the user.
     */
    public static function rolesRequiringDirection(): array
    {
        return [
            self::AGENT_CIRCUIT_COURRIER,
            self::RESPONSABLE_DIRECTION,
            self::DIRECTEUR_DIRECTION,
            self::SECRETARIAT_DIRECTION,
        ];
    }

    public function estDirecteurDirection(): bool
    {
        return $this === self::DIRECTEUR_DIRECTION || $this === self::RESPONSABLE_DIRECTION;
    }
}
