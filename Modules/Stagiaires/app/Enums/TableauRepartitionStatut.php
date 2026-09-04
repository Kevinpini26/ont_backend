<?php

namespace Modules\Stagiaires\Enums;

/**
 * Statut du tableau lui-même, tenu à jour en parallèle du statut du
 * courrier synthétique qui porte son trajet Réception -> DG (voir
 * TableauRepartition::courrier(), TableauRepartitionCircuitService) —
 * dupliqué plutôt que délégué : BROUILLON existe avant même qu'un
 * courrier ne soit créé (voir migration, `courrier_id` nullable).
 */
enum TableauRepartitionStatut: string
{
    case BROUILLON = 'brouillon';
    case CHEZ_RECEPTION = 'chez_reception';
    case EN_ATTENTE_AVIS_DG = 'en_attente_avis_dg';
    case APPROUVE = 'approuve';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::CHEZ_RECEPTION => 'Chez la Réception',
            self::EN_ATTENTE_AVIS_DG => "En attente d'avis DG",
            self::APPROUVE => 'Approuvé',
        };
    }
}
