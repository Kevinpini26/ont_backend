<?php

namespace Modules\Kernel\Support;

/**
 * MAIL_MAILER=log (valeur par défaut, pratique en développement : voir
 * .env.example) écrit les emails dans les logs au lieu de les envoyer
 * réellement. En production, cela signifie qu'aucun accusé de réception,
 * aucune alerte DG ni aucune notification de stage ne part jamais, sans
 * aucune erreur visible. Refuser de démarrer est plus sûr qu'un simple
 * avertissement dans les logs, jamais lu à temps. Extrait de
 * KernelServiceProvider::boot() uniquement pour rester testable
 * indépendamment d'un boot complet de l'application.
 */
class MailProductionGuard
{
    public static function verifier(): void
    {
        if (app()->environment('production') && config('mail.default') === 'log') {
            throw new \RuntimeException(
                'MAIL_MAILER=log en production : aucun email ne serait réellement envoyé (accusés de réception, alertes DG, notifications de stage...). Configurez un mailer réel (smtp, ses, postmark, ...) avant le déploiement.'
            );
        }
    }
}
