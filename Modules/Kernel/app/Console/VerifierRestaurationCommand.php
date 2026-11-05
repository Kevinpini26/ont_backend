<?php

namespace Modules\Kernel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Prouve qu'une sauvegarde est restaurable : la dernière sauvegarde de la base (ou celle passée
 * en option) est restaurée dans une base JETABLE créée pour l'occasion, contrôlée, puis supprimée.
 * La base de l'application n'est jamais touchée : la base cible porte obligatoirement le préfixe
 * ont_verif_restauration_. Sans droit CREATEDB, un administrateur crée une fois cette base vide
 * et la passe avec --base ; elle est alors vidée (schéma public) après chaque vérification.
 */
class VerifierRestaurationCommand extends Command
{
    public const PREFIXE_BASE = 'ont_verif_restauration_';

    protected $signature = 'kernel:verifier-restauration
        {--fichier= : chemin sur le disque de sauvegarde (défaut : la plus récente)}
        {--base= : base jetable déjà créée par un administrateur (préfixe ont_verif_restauration_, vide), si le compte n\'a pas le droit CREATEDB}';

    protected $description = 'Restaure la dernière sauvegarde dans une base jetable, la contrôle puis la supprime';

    public function handle(): int
    {
        $config = config('database.connections.'.config('database.default'));
        if (($config['driver'] ?? null) !== 'pgsql') {
            $this->error('Vérification prise en charge pour PostgreSQL uniquement.');

            return self::FAILURE;
        }

        $disque = config('backup.disk');
        $fichier = $this->option('fichier') ?: collect(Storage::disk($disque)->files('database'))
            ->filter(fn (string $f) => str_ends_with($f, '.sql.gz'))
            ->sortByDesc(fn (string $f) => Storage::disk($disque)->lastModified($f))
            ->first();

        if ($fichier === null || ! Storage::disk($disque)->exists($fichier)) {
            $this->error('Aucune sauvegarde de base trouvée sur le disque « '.$disque.' ».');

            return self::FAILURE;
        }

        $baseFournie = $this->option('base');
        if ($baseFournie !== null && (! str_starts_with($baseFournie, self::PREFIXE_BASE) || $baseFournie === ($config['database'] ?? null))) {
            $this->error('La base jetable doit porter le préfixe '.self::PREFIXE_BASE.' et ne jamais être la base de l\'application.');

            return self::FAILURE;
        }
        $base = $baseFournie ?? self::PREFIXE_BASE.now()->format('YmdHis');
        $archive = storage_path('app/tmp-verif-'.basename($fichier));
        $sql = substr($archive, 0, -3);
        file_put_contents($archive, Storage::disk($disque)->readStream($fichier));

        $connexion = ['--host='.$config['host'], '--port='.$config['port'], '--username='.$config['username'], '--no-password'];
        $env = ['PGPASSWORD' => $config['password']];
        $baseCreee = false;
        $baseAVider = false;

        try {
            if (Process::run(['gzip', '-t', $archive])->failed() || Process::run(['gzip', '-df', $archive])->failed()) {
                $this->error("Archive illisible : {$fichier}");

                return self::FAILURE;
            }

            if ($baseFournie === null) {
                $creation = Process::env($env)->run(['createdb', ...$connexion, $base]);
                if ($creation->failed()) {
                    $this->error('Impossible de créer la base jetable : '.$creation->errorOutput()
                        .' Faites créer une base vide '.self::PREFIXE_BASE.'… par un administrateur et passez-la avec --base.');

                    return self::FAILURE;
                }
                $baseCreee = true;
            } else {
                $tables = Process::env($env)->run(['psql', ...$connexion, '--dbname='.$base, '-tAc', "select count(*) from information_schema.tables where table_schema = 'public'"]);
                if ($tables->failed() || (int) trim($tables->output()) !== 0) {
                    $this->error("La base jetable {$base} est introuvable ou n'est pas vide : vérification annulée.");

                    return self::FAILURE;
                }
                $baseAVider = true;
            }

            $restauration = Process::env($env)->timeout(3600)->run(['psql', ...$connexion, '--quiet', '-v', 'ON_ERROR_STOP=1', '--dbname='.$base, '--file='.$sql]);
            if ($restauration->failed()) {
                $this->error('Échec de la restauration : '.$restauration->errorOutput());

                return self::FAILURE;
            }

            $controle = Process::env($env)->run(['psql', ...$connexion, '--dbname='.$base, '-tAc', 'select count(*) from migrations']);
            $migrations = (int) trim($controle->output());
            if ($controle->failed() || $migrations === 0) {
                $this->error('Base restaurée incomplète : table des migrations absente ou vide.');

                return self::FAILURE;
            }

            $this->info("Restauration vérifiée : {$fichier} → base jetable {$base} ({$migrations} migrations).");

            return self::SUCCESS;
        } finally {
            if ($baseCreee) {
                Process::env($env)->run(['dropdb', ...$connexion, '--if-exists', $base]);
            }
            if ($baseAVider) {
                Process::env($env)->run(['psql', ...$connexion, '--dbname='.$base, '-c', 'drop schema public cascade; create schema public;']);
            }
            foreach ([$archive, $sql] as $temporaire) {
                if (is_file($temporaire)) {
                    unlink($temporaire);
                }
            }
        }
    }
}
