<?php

namespace Modules\Courrier\Enums;

enum DocumentProduitStatut: string
{
    case BROUILLON = 'brouillon';
    case SOUMIS_DIRECTEUR = 'soumis_directeur';
    case A_CORRIGER = 'a_corriger';
    case VALIDE = 'valide';
    case TRANSMIS_RECEPTION = 'transmis_reception';
    case ENTRE_CIRCUIT = 'entre_circuit';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon', self::SOUMIS_DIRECTEUR => 'Soumis au Directeur',
            self::A_CORRIGER => 'À corriger', self::VALIDE => 'Validé par le Directeur',
            self::TRANSMIS_RECEPTION => 'Transmis à la Réception', self::ENTRE_CIRCUIT => 'Entré dans le circuit central',
        };
    }
}
