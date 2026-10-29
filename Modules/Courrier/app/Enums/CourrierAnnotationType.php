<?php

namespace Modules\Courrier\Enums;

/** Type d'une note de circuit : chaque type a un nom et une couleur (jamais la couleur seule à l'écran). */
enum CourrierAnnotationType: string
{
    case INSTRUCTION = 'instruction';
    case OBSERVATION = 'observation';
    case RAPPEL = 'rappel';
    case URGENT = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::INSTRUCTION => 'Instruction',
            self::OBSERVATION => 'Observation',
            self::RAPPEL => 'Rappel personnel',
            self::URGENT => 'Urgent',
        };
    }

    public function couleur(): string
    {
        return match ($this) {
            self::INSTRUCTION => 'bleu',
            self::OBSERVATION => 'jaune',
            self::RAPPEL => 'vert',
            self::URGENT => 'rouge',
        };
    }
}
