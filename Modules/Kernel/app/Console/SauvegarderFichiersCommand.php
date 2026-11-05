<?php

namespace Modules\Kernel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Archive les fichiers du disque « local » (scans, PDF officiels, pièces jointes, documents des
 * stagiaires) vers le disque de sauvegarde, à côté des exports de la base. Une base restaurée
 * sans ses fichiers est inutilisable : les deux sauvegardes vont ensemble.
 * L'archive est contrôlée (tar -tzf) avant l'envoi et avant toute rétention.
 */
class SauvegarderFichiersCommand extends Command
{
    protected $signature = 'kernel:sauvegarder-fichiers';

    protected $description = 'Archive les fichiers de l\'application (scans, PDF, pièces) vers le disque de sauvegarde';

    public function handle(): int
    {
        $source = Storage::disk('local')->path('');
        $nomFichier = 'ont-fichiers-'.now()->format('Y-m-d_His').'.tar.gz';
        $temporaire = storage_path("app/tmp-backup-{$nomFichier}");

        if (! is_dir($source)) {
            $this->error("Dossier des fichiers introuvable : {$source}");

            return self::FAILURE;
        }

        $archive = Process::timeout(3600)->run(['tar', '-czf', $temporaire, '-C', $source, '.']);

        if ($archive->failed() || ! is_file($temporaire) || filesize($temporaire) === 0
            || Process::timeout(3600)->run(['tar', '-tzf', $temporaire])->failed()) {
            $this->error('Échec de l\'archivage des fichiers : '.$archive->errorOutput());
            if (is_file($temporaire)) {
                unlink($temporaire);
            }

            return self::FAILURE;
        }

        $disque = config('backup.disk');
        $flux = fopen($temporaire, 'rb');
        Storage::disk($disque)->writeStream('fichiers/'.$nomFichier, $flux);
        if (is_resource($flux)) {
            fclose($flux);
        }
        unlink($temporaire);

        $this->info("Archive des fichiers envoyée sur le disque « {$disque} » : fichiers/{$nomFichier}");
        $this->appliquerRetention($disque);

        return self::SUCCESS;
    }

    private function appliquerRetention(string $disque): void
    {
        $seuil = now()->subDays((int) config('backup.retention_days'));

        foreach (Storage::disk($disque)->files('fichiers') as $fichier) {
            $modifie = Storage::disk($disque)->lastModified($fichier);
            if ($modifie !== false && now()->createFromTimestamp($modifie)->lt($seuil)) {
                Storage::disk($disque)->delete($fichier);
            }
        }
    }
}
