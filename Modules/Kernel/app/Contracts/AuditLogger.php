<?php

namespace Modules\Kernel\Contracts;

use Illuminate\Database\Eloquent\Model;
use Modules\Kernel\Models\User;

/**
 * Point d'extension : trace les actions sensibles de la plateforme
 * (connexion, signature de courrier, affectation de stagiaire, notation...)
 * à des fins de traçabilité en cas de litige. L'implémentation par défaut
 * écrit dans la table `audit_logs`, mais un autre canal (SIEM externe,
 * fichier append-only...) pourrait être branché sans toucher les appelants.
 */
interface AuditLogger
{
    /**
     * @param  array<string, mixed>  $contexte  Données libres associées à l'action
     *                                          (ex: ['note' => 15, 'ancienne_note' => 12]).
     *                                          La clé 'description' est traitée à part
     *                                          comme résumé lisible de l'action.
     */
    public function enregistrer(string $action, ?Model $sujet = null, ?User $acteur = null, array $contexte = []): void;
}
