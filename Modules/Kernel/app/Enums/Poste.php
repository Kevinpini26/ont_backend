<?php

namespace Modules\Kernel\Enums;

/**
 * Postes occupés par un utilisateur de rôle "agent_circuit_courrier"
 * le long du circuit courrier central de l'ONT.
 */
enum Poste: string
{
    case RECEPTION = 'reception';
    /** Historique uniquement : aucune nouvelle affectation opérationnelle. */
    case PROTOCOLE = 'protocole';
    case DGA = 'dga';
    /** Historique uniquement : le service Protocole n'existe plus. */
    case ASSISTANT_PROTOCOLE = 'assistant_protocole';
    case ASSISTANT_1 = 'assistant_1';
    case ASSISTANT_2 = 'assistant_2';
    case ASSISTANT_DGA = 'assistant_dga';
    case DG = 'dg';
    case SECRETARIAT_1 = 'secretariat_1';
    case SECRETARIAT_2 = 'secretariat_2';

    public function label(): string
    {
        return match ($this) {
            self::RECEPTION => 'Réception',
            self::PROTOCOLE => 'Protocole (historique)',
            self::DGA => 'Directeur Général Adjoint',
            self::ASSISTANT_PROTOCOLE => 'Assistant du Protocole (historique)',
            self::ASSISTANT_1 => 'Assistant 1 (Ass1)',
            self::ASSISTANT_2 => 'Assistant 2 (Ass2)',
            self::ASSISTANT_DGA => 'Assistant du DGA (Ass.Dga)',
            self::DG => 'Directeur Général',
            self::SECRETARIAT_1 => 'Secrétariat 01',
            self::SECRETARIAT_2 => 'Secrétariat 02',
        };
    }

    public function estHistorique(): bool
    {
        return $this === self::PROTOCOLE || $this === self::ASSISTANT_PROTOCOLE;
    }

    /**
     * Un seul titulaire actif à la fois (voir UserController::passationDePoste()) :
     * contrairement aux postes d'assistant, où plusieurs comptes peuvent
     * coexister sans ambiguïté sur qui agit en leur nom.
     */
    public function estUnique(): bool
    {
        return in_array($this, [self::DG, self::DGA, self::SECRETARIAT_1, self::SECRETARIAT_2, self::RECEPTION], true);
    }
}
