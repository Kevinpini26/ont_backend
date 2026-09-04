<?php

namespace Modules\Kernel\Enums;

enum UserRole: string
{
    case AGENT_CIRCUIT_COURRIER = 'agent_circuit_courrier';
    case RESPONSABLE_DIRECTION = 'responsable_direction';
    case SECRETARIAT_DIRECTION = 'secretariat_direction';
    case AGENT_DFP = 'agent_dfp';
    case ADMINISTRATEUR = 'administrateur';

    public function label(): string
    {
        return match ($this) {
            self::AGENT_CIRCUIT_COURRIER => 'Agent de circuit courrier',
            self::RESPONSABLE_DIRECTION => 'Responsable de direction',
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
        return [self::AGENT_CIRCUIT_COURRIER, self::RESPONSABLE_DIRECTION, self::SECRETARIAT_DIRECTION];
    }
}
