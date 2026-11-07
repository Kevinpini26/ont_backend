<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SauvegarderFichiersEtRestaurationTest extends TestCase
{
    public function test_les_fichiers_sont_archives_controles_puis_envoyes(): void
    {
        Storage::fake('local');
        Storage::fake('backups-local');
        config(['backup.disk' => 'backups-local']);
        Storage::disk('local')->put('courriers/scan.pdf', '%PDF');

        Process::fake(function ($process) {
            if (($process->command[1] ?? null) === '-czf') {
                file_put_contents($process->command[2], 'archive-simulee');
            }

            return Process::result(exitCode: 0);
        });

        $this->artisan('kernel:sauvegarder-fichiers')->assertSuccessful();

        $this->assertCount(1, Storage::disk('backups-local')->files('fichiers'));
        Process::assertRan(fn ($process) => ($process->command[1] ?? null) === '-tzf');
    }

    public function test_une_archive_de_fichiers_illisible_nest_pas_envoyee(): void
    {
        Storage::fake('local');
        Storage::fake('backups-local');
        config(['backup.disk' => 'backups-local']);
        Storage::disk('local')->put('courriers/scan.pdf', '%PDF');

        Process::fake(function ($process) {
            if (($process->command[1] ?? null) === '-czf') {
                file_put_contents($process->command[2], 'archive-corrompue');
            }

            return Process::result(exitCode: ($process->command[1] ?? null) === '-tzf' ? 2 : 0);
        });

        $this->artisan('kernel:sauvegarder-fichiers')->assertFailed();

        $this->assertSame([], Storage::disk('backups-local')->files('fichiers'));
    }

    public function test_la_verification_refuse_toute_base_autre_quune_base_jetable(): void
    {
        Process::fake();

        $this->artisan('kernel:verifier-restauration', ['--base' => 'systeme_ont'])->assertFailed();
        $this->artisan('kernel:verifier-restauration', ['--base' => config('database.connections.pgsql.database')])->assertFailed();

        Process::assertNothingRan();
    }

    public function test_la_verification_restaure_controle_et_supprime_la_base_jetable(): void
    {
        Storage::fake('backups-local');
        config(['backup.disk' => 'backups-local']);
        Storage::disk('backups-local')->put('database/ont-2026-11-05_030000.sql.gz', gzencode('select 1;'));

        Process::fake(function ($process) {
            $commande = (array) $process->command;
            if (($commande[0] ?? null) === 'gzip' && ($commande[1] ?? null) === '-df') {
                $archive = end($commande);
                file_put_contents(substr($archive, 0, -3), 'select 1;');
            }
            if (in_array('select count(*) from migrations', $commande, true)) {
                return Process::result(output: "42\n");
            }

            return Process::result(exitCode: 0);
        });

        $this->artisan('kernel:verifier-restauration')->assertSuccessful();

        Process::assertRan(fn ($p) => ($p->command[0] ?? null) === 'createdb' && str_starts_with(end($p->command), 'ont_verif_restauration_'));
        Process::assertRan(fn ($p) => ($p->command[0] ?? null) === 'dropdb' && str_starts_with(end($p->command), 'ont_verif_restauration_'));
    }

    /** Relevé à la première CI réelle : voir CompatibiliteSauvegardePostgres. */
    public function test_la_verification_retire_le_reglage_quun_serveur_16_ne_connait_pas(): void
    {
        Storage::fake('backups-local');
        config(['backup.disk' => 'backups-local']);
        Storage::disk('backups-local')->put('database/ont-2026-11-07_030000.sql.gz', gzencode("SET transaction_timeout = 0;\nselect 1;\n"));
        $sqlRestaure = null;

        Process::fake(function ($process) use (&$sqlRestaure) {
            $commande = (array) $process->command;
            if (($commande[0] ?? null) === 'gzip' && ($commande[1] ?? null) === '-df') {
                $archive = end($commande);
                file_put_contents(substr($archive, 0, -3), gzdecode((string) file_get_contents($archive)));
            }
            if (($commande[0] ?? null) === 'psql' && in_array('ON_ERROR_STOP=1', $commande, true)) {
                $fichier = collect($commande)->first(fn ($a) => str_starts_with((string) $a, '--file='));
                $sqlRestaure = file_get_contents(substr((string) $fichier, 7));
            }
            if (in_array('select count(*) from migrations', $commande, true)) {
                return Process::result(output: "42\n");
            }

            return Process::result(exitCode: 0);
        });

        $this->artisan('kernel:verifier-restauration')->assertSuccessful();

        $this->assertStringNotContainsString('transaction_timeout', (string) $sqlRestaure);
        $this->assertStringContainsString('select 1;', (string) $sqlRestaure);
    }
}
