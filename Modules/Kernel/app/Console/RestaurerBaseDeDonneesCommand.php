<?php

namespace Modules\Kernel\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Restaure la base PostgreSQL depuis une sauvegarde produite par
 * SauvegarderBaseDeDonneesCommand (pg_dump texte, compressée gzip) —
 * l'existence même de cette commande, et le fait qu'elle soit testée par un
 * cycle sauvegarde→restauration réel, est ce qui distingue une sauvegarde
 * d'un fichier qu'on espère utilisable un jour. Écrase entièrement la base
 * cible : jamais à exécuter sans confirmation explicite hors environnement
 * local.
 */
class RestaurerBaseDeDonneesCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'kernel:restaurer-base-de-donnees
        {fichier? : Chemin du fichier sur le disque de sauvegarde (ex. database/ont-2026-06-15_030000.sql.gz) — le plus récent par défaut}
        {--force : Exécute sans confirmation, y compris hors environnement local}';

    protected $description = 'Restaure la base de données depuis une sauvegarde du disque configuré (voir config/backup.php) — écrase la base cible';

    public function handle(): int
    {
        $connexion = config('database.default');
        $config = config("database.connections.{$connexion}");

        if (($config['driver'] ?? null) !== 'pgsql') {
            $this->error("Cette commande ne prend en charge que PostgreSQL (connexion configurée : {$config['driver']}).");

            return self::FAILURE;
        }

        // Confirmation exigée dans tous les environnements, pas seulement en
        // production (par défaut, confirmToProceed() ne bloque qu'en
        // production) : une écrasement de base reste destructeur sur un
        // poste de développement aussi.
        if (! $this->confirmToProceed(
            "Cette opération écrase entièrement la base « {$config['database']} » avec le contenu de la sauvegarde.",
            fn () => true,
        )) {
            return self::FAILURE;
        }

        $disque = config('backup.disk');
        $cheminDistant = $this->argument('fichier') ?? $this->trouverSauvegardeLaPlusRecente($disque);

        if ($cheminDistant === null) {
            $this->error("Aucune sauvegarde trouvée sur le disque « {$disque} ».");

            return self::FAILURE;
        }

        if (! Storage::disk($disque)->exists($cheminDistant)) {
            $this->error("Fichier introuvable sur le disque « {$disque} » : {$cheminDistant}");

            return self::FAILURE;
        }

        $cheminTemporaire = storage_path('app/tmp-restore-'.basename($cheminDistant));
        file_put_contents($cheminTemporaire, Storage::disk($disque)->get($cheminDistant));

        $this->info("Restauration de {$cheminDistant} vers « {$config['database']} »...");

        $resultat = Process::env(['PGPASSWORD' => $config['password']])
            ->run([
                'sh', '-c',
                sprintf(
                    'gunzip -c %s | psql --host=%s --port=%s --username=%s --no-password %s',
                    escapeshellarg($cheminTemporaire),
                    escapeshellarg($config['host']),
                    escapeshellarg((string) $config['port']),
                    escapeshellarg($config['username']),
                    escapeshellarg($config['database']),
                ),
            ]);

        unlink($cheminTemporaire);

        if ($resultat->failed()) {
            $this->error('Échec de la restauration : '.$resultat->errorOutput());

            return self::FAILURE;
        }

        $this->info('Base de données restaurée avec succès.');

        return self::SUCCESS;
    }

    private function trouverSauvegardeLaPlusRecente(string $disque): ?string
    {
        $fichiers = Storage::disk($disque)->files('database');

        if ($fichiers === []) {
            return null;
        }

        usort($fichiers, fn ($a, $b) => Storage::disk($disque)->lastModified($b) <=> Storage::disk($disque)->lastModified($a));

        return $fichiers[0];
    }
}
