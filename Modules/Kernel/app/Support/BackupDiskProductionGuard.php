<?php

namespace Modules\Kernel\Support;

/**
 * "backups-local" écrit sur le disque du serveur applicatif lui-même : une
 * panne ou une compromission de ce serveur emporte la sauvegarde avec
 * l'original. Ce réglage reste pratique par défaut en développement (voir
 * config/backup.php), mais ne doit jamais atteindre la production —
 * refuser de démarrer est plus sûr qu'un simple avertissement dans les
 * logs, jamais lu à temps. Extrait de KernelServiceProvider::boot()
 * uniquement pour rester testable indépendamment d'un boot complet de
 * l'application.
 */
class BackupDiskProductionGuard
{
    public static function verifier(): void
    {
        if (app()->environment('production') && config('backup.disk') === 'backups-local') {
            throw new \RuntimeException(
                "BACKUP_DISK=backups-local en production : une sauvegarde stockée sur le même serveur que l'application ne protège de rien. Configurez BACKUP_DISK=backups-s3 (voir config/backup.php) avant le déploiement."
            );
        }
    }
}
