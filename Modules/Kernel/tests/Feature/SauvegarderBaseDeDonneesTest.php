<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SauvegarderBaseDeDonneesTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * pg_dump n'est pas relancé réellement (Process::fake) : ce test valide
     * l'orchestration de la commande (upload + rétention), pas pg_dump
     * lui-même — déjà vérifié manuellement contre la base de développement
     * réelle (dump gzip valide produit et restauré avec succès).
     */
    public function test_la_sauvegarde_est_envoyee_sur_le_disque_configure_et_la_retention_est_appliquee(): void
    {
        Storage::fake('backups-local');
        config(['backup.disk' => 'backups-local', 'backup.retention_days' => 30]);

        Carbon::setTestNow('2026-06-15 03:00:00');
        $cheminTemporaire = storage_path('app/tmp-backup-ont-2026-06-15_030000.sql.gz');

        Process::fake(function ($process) use ($cheminTemporaire) {
            // Simule pg_dump (fichier SQL), gzip (archive) puis gzip -t, sans les exécuter.
            if (in_array('gzip', (array) $process->command, true) && ! in_array('-t', (array) $process->command, true)) {
                file_put_contents($cheminTemporaire, 'dump-simule');
            } elseif (($process->command[0] ?? null) === 'pg_dump') {
                file_put_contents(substr($cheminTemporaire, 0, -3), 'sql-simule');
            }

            return Process::result(output: '', errorOutput: '', exitCode: 0);
        });

        // Ancienne sauvegarde à purger (au-delà de la rétention).
        Storage::disk('backups-local')->put('database/ont-ancienne.sql.gz', 'ancien');
        touch(Storage::disk('backups-local')->path('database/ont-ancienne.sql.gz'), now()->subDays(45)->timestamp);

        $this->artisan('kernel:sauvegarder-base-de-donnees')->assertSuccessful();

        $fichiers = Storage::disk('backups-local')->files('database');

        $this->assertCount(1, $fichiers);
        $this->assertStringNotContainsString('ancienne', $fichiers[0]);
    }

    public function test_un_echec_de_pg_dump_est_detecte_et_ne_declenche_ni_envoi_ni_retention(): void
    {
        Storage::fake('backups-local');
        config(['backup.disk' => 'backups-local', 'backup.retention_days' => 30]);
        Carbon::setTestNow('2026-06-15 03:00:00');

        Process::fake(fn ($process) => ($process->command[0] ?? null) === 'pg_dump'
            ? Process::result(output: '', errorOutput: 'connexion refusée', exitCode: 1)
            : Process::result(output: '', errorOutput: '', exitCode: 0));

        Storage::disk('backups-local')->put('database/ont-ancienne.sql.gz', 'ancien');
        touch(Storage::disk('backups-local')->path('database/ont-ancienne.sql.gz'), now()->subDays(45)->timestamp);

        $this->artisan('kernel:sauvegarder-base-de-donnees')->assertFailed();

        $this->assertSame(['database/ont-ancienne.sql.gz'], Storage::disk('backups-local')->files('database'));
    }

    public function test_une_sauvegarde_vide_est_refusee(): void
    {
        Storage::fake('backups-local');
        config(['backup.disk' => 'backups-local']);
        Carbon::setTestNow('2026-06-15 03:00:00');

        Process::fake(fn () => Process::result(output: '', errorOutput: '', exitCode: 0));

        $this->artisan('kernel:sauvegarder-base-de-donnees')->assertFailed();

        $this->assertSame([], Storage::disk('backups-local')->files('database'));
    }
}
