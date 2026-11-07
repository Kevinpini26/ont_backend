<?php

namespace Modules\Kernel\Support;

/**
 * Compatibilité des sauvegardes : un pg_dump 17 ou plus récent écrit « SET transaction_timeout = 0; »,
 * réglage qu'un serveur PostgreSQL 16 ne connaît pas (psql s'arrêterait sur ON_ERROR_STOP). Il ne fait
 * que désactiver un délai : on le retire avant restauration, ligne par ligne, sans tout charger en mémoire.
 * Relevé à la première exécution réelle de la CI (pg_dump 18 face à un serveur 16).
 */
final class CompatibiliteSauvegardePostgres
{
    public static function nettoyer(string $cheminSql): void
    {
        $source = fopen($cheminSql, 'rb');
        $cheminFiltre = $cheminSql.'.filtre';
        $cible = fopen($cheminFiltre, 'wb');
        if ($source === false || $cible === false) {
            return;
        }
        while (($ligne = fgets($source)) !== false) {
            if (preg_match('/^SET transaction_timeout = 0;\s*$/', $ligne) === 1) {
                continue;
            }
            fwrite($cible, $ligne);
        }
        fclose($source);
        fclose($cible);
        rename($cheminFiltre, $cheminSql);
    }
}
