<?php

namespace Modules\Kernel\Support;

use Illuminate\Support\Facades\Gate;
use Modules\Kernel\Models\User;

/**
 * Permissions calculées par le serveur et renvoyées avec le compte connecté (/auth/me, connexion) :
 * l'interface affiche menus et boutons d'après elles au lieu de deviner les droits depuis le poste.
 * Elles reflètent exactement les politiques appliquées par l'API (intérim et délégations compris) ;
 * masquer un bouton ne protège rien, le serveur reste la vraie protection.
 */
class PermissionsUtilisateur
{
    /** Classe du courrier, résolue par son nom : le Kernel ne dépend pas du module Courrier. */
    private const COURRIER = 'Modules\\Courrier\\Models\\Courrier';

    /** @return array<string, bool> */
    public static function pour(User $user): array
    {
        $courrier = class_exists(self::COURRIER) ? self::COURRIER : null;
        $peut = fn (string $capacite) => $courrier !== null && Gate::forUser($user)->allows($capacite, $courrier);

        return [
            'voir_statistiques_courrier' => $peut('voirStatistiques'),
            'voir_registre' => $peut('voirRegistre'),
            'voir_tableau_de_bord_dg' => $peut('voirTableauDeBordDg'),
            'voir_justesse_tri' => $peut('voirJustesseTri'),
            'voir_justesse_tri_par_agent' => $peut('voirJustesseTriParAgent'),
        ];
    }
}
