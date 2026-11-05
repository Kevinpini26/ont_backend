<?php

namespace Modules\Kernel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Exporte quotidiennement la base PostgreSQL (pg_dump) vers un stockage
 * distinct du serveur applicatif (voir config/backup.php), avec rétention
 * glissante. Planifiée dans KernelServiceProvider::configureSchedules.
 */
class SauvegarderBaseDeDonneesCommand extends Command
{
    protected $signature = 'kernel:sauvegarder-base-de-donnees';

    protected $description = 'Exporte la base de données vers le disque de sauvegarde configuré (voir config/backup.php)';

    public function handle(): int
    {
        $connexion = config('database.default');
        $config = config("database.connections.{$connexion}");

        if (($config['driver'] ?? null) !== 'pgsql') {
            $this->error("Cette commande ne prend en charge que PostgreSQL (connexion configurée : {$config['driver']}).");

            return self::FAILURE;
        }

        $nomFichier = 'ont-'.now()->format('Y-m-d_His').'.sql.gz';
        $cheminTemporaire = storage_path("app/tmp-backup-{$nomFichier}");

        // Pas de pipeline shell (pg_dump | gzip) : sans pipefail, l'échec de pg_dump serait masqué par
        // le succès de gzip. Deux étapes, chacune contrôlée, puis vérification de l'archive produite.
        $cheminSql = substr($cheminTemporaire, 0, -3);

        $dump = Process::env(['PGPASSWORD' => $config['password']])->run([
            'pg_dump', '--host='.$config['host'], '--port='.$config['port'],
            '--username='.$config['username'], '--no-password', '--file='.$cheminSql, $config['database'],
        ]);

        if ($dump->failed() || ! is_file($cheminSql) || filesize($cheminSql) === 0) {
            $this->error('Échec de pg_dump : '.$dump->errorOutput());
            $this->nettoyerTemporaires($cheminSql, $cheminTemporaire);

            return self::FAILURE;
        }

        $compression = Process::run(['gzip', '-f', $cheminSql]);

        if ($compression->failed() || ! is_file($cheminTemporaire) || filesize($cheminTemporaire) === 0
            || Process::run(['gzip', '-t', $cheminTemporaire])->failed()) {
            $this->error('Échec de la compression de la sauvegarde : '.$compression->errorOutput());
            $this->nettoyerTemporaires($cheminSql, $cheminTemporaire);

            return self::FAILURE;
        }

        $disque = config('backup.disk');
        $cheminDistant = 'database/'.$nomFichier;

        Storage::disk($disque)->put($cheminDistant, file_get_contents($cheminTemporaire));
        unlink($cheminTemporaire);

        $this->info("Sauvegarde envoyée sur le disque « {$disque} » : {$cheminDistant}");

        $this->nettoyerAnciennesSauvegardes($disque);

        return self::SUCCESS;
    }

    private function nettoyerTemporaires(string ...$chemins): void
    {
        foreach ($chemins as $chemin) {
            if (is_file($chemin)) {
                unlink($chemin);
            }
        }
    }

    private function nettoyerAnciennesSauvegardes(string $disque): void
    {
        $seuil = now()->subDays((int) config('backup.retention_days'));
        $supprimees = 0;

        foreach (Storage::disk($disque)->files('database') as $fichier) {
            $modifie = Storage::disk($disque)->lastModified($fichier);

            if ($modifie !== false && now()->createFromTimestamp($modifie)->lt($seuil)) {
                Storage::disk($disque)->delete($fichier);
                $supprimees++;
            }
        }

        if ($supprimees > 0) {
            $this->info("{$supprimees} ancienne(s) sauvegarde(s) supprimée(s) (rétention : ".config('backup.retention_days').' jours).');
        }
    }
}
