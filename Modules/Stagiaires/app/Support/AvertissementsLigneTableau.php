<?php

namespace Modules\Stagiaires\Support;

use Modules\Stagiaires\Contracts\AffectationRules;
use Modules\Stagiaires\Models\TableauRepartitionLigne;

/**
 * Lot A : contrôles de cohérence calculés à la volée, jamais bloquants —
 * la DFP reste maîtresse de son arbitrage (voir
 * TableauRepartitionCircuitService::ajouterLigne(), qui trace le passage
 * en force plutôt que de le refuser).
 */
class AvertissementsLigneTableau
{
    public function __construct(private readonly AffectationRules $affectationRules) {}

    /**
     * @return list<array{code: string, message: string}>
     */
    public function pour(TableauRepartitionLigne $ligne): array
    {
        $avertissements = [];

        ['occupation' => $occupation, 'capacite' => $capacite] = $this->affectationRules->occupationEtCapacite(
            $ligne->direction_accueil_proposee_id
        );

        if ($capacite !== null) {
            if ($occupation >= $capacite) {
                $avertissements[] = [
                    'code' => 'quota_atteint',
                    'message' => "La direction d'accueil proposée a déjà atteint sa capacité maximale ({$occupation}/{$capacite}).",
                ];
            } elseif ($occupation >= $capacite - 1) {
                $avertissements[] = [
                    'code' => 'quota_proche',
                    'message' => "La direction d'accueil proposée approche de sa capacité maximale ({$occupation}/{$capacite}).",
                ];
            }
        }

        $tableau = $ligne->tableau;
        if ($tableau !== null && (
            $ligne->date_debut_proposee->lt($tableau->periode_debut)
            || $ligne->date_fin_proposee->gt($tableau->periode_fin)
        )) {
            $avertissements[] = [
                'code' => 'dates_hors_periode',
                'message' => 'Les dates proposées dépassent la période couverte par ce tableau.',
            ];
        }

        $stagiaire = $ligne->stagiaire;
        if ($stagiaire !== null && $stagiaire->doublon_suspecte) {
            $avertissements[] = [
                'code' => 'doublon_suspecte',
                'message' => 'Ce dossier est signalé comme doublon possible.',
            ];
        }

        return $avertissements;
    }
}
