<?php

namespace Modules\Courrier\Support;

use Illuminate\Support\Collection;

/**
 * Isolé de DompdfRegistreCourrierPdfGenerator pour rester testable sans
 * passer par la génération PDF elle-même : la continuité de la
 * numérotation doit être vérifiable indépendamment du rendu.
 */
class DetecteurRupturesSequence
{
    /**
     * @param  Collection<int, object{id: int}>  $elements  Déjà triés dans l'ordre de la séquence.
     * @return array<int, int> Identifiants des éléments précédés d'une rupture.
     */
    public function detecter(Collection $elements, string $colonne): array
    {
        $ruptures = [];
        $precedente = null;

        foreach ($elements as $element) {
            $sequence = $this->sequenceDe($element->{$colonne});

            if ($sequence !== null && $precedente !== null && $sequence > $precedente + 1) {
                $ruptures[] = $element->id;
            }

            if ($sequence !== null) {
                $precedente = $sequence;
            }
        }

        return $ruptures;
    }

    private function sequenceDe(?string $numero): ?int
    {
        if ($numero === null) {
            return null;
        }

        preg_match('/(\d+)$/', $numero, $matches);

        return isset($matches[1]) ? (int) $matches[1] : null;
    }
}
