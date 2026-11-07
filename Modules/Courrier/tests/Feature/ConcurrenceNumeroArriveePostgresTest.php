<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Lot 6 : le numéro d'arrivée reste atomique sous concurrence. Six processus réels tirent chacun 30 numéros
 * en même temps sur la même séquence : aucun doublon, aucun trou. La séquence de test est propre au test
 * (type dédié, année 2099) et supprimée à la fin : la séquence réelle d'enregistrement n'est pas touchée.
 */
class ConcurrenceNumeroArriveePostgresTest extends TestCase
{
    public function test_des_tirages_simultanes_ne_produisent_ni_doublon_ni_trou(): void
    {
        // Ce test n'utilise pas RefreshDatabase (les processus doivent voir des données validées) :
        // il s'assure seulement que le schéma de la base de test existe.
        if (! Schema::hasTable('courrier_numero_compteurs')) {
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        }
        $type = 'test_concurrence_arrivee_'.bin2hex(random_bytes(4));
        $barriere = sys_get_temp_dir().'/ont-numero-arrivee-'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($barriere, 0700));
        $processus = [];

        try {
            foreach (range(1, 6) as $worker) {
                $process = new Process([PHP_BINARY, base_path('Modules/Courrier/tests/Support/numero_arrivee_worker.php'),
                    $type, '2099', '30', $barriere, (string) $worker, "{$barriere}/result-{$worker}.json"], base_path(), [
                        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'ont_testing', 'DB_URL' => '',
                        'DB_HOST' => (string) config('database.connections.pgsql.host'),
                        'DB_PORT' => (string) config('database.connections.pgsql.port'),
                        'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
                        'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
                    ]);
                $process->setTimeout(60)->start();
                $processus[] = $process;
            }

            $limite = microtime(true) + 20;
            while (count(glob("{$barriere}/ready-*")) < 6 && microtime(true) < $limite) {
                usleep(10_000);
            }
            $this->assertCount(6, glob("{$barriere}/ready-*"), 'Les six processus doivent être prêts avant le départ.');
            file_put_contents("{$barriere}/start", 'go');

            foreach ($processus as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }

            $tous = collect(glob("{$barriere}/result-*.json"))->flatMap(fn ($f) => json_decode((string) file_get_contents($f), true))->sort()->values();
            $this->assertCount(180, $tous);
            $this->assertSame(range(1, 180), $tous->all(), 'La séquence doit être continue, sans doublon ni trou.');
        } finally {
            DB::table('courrier_numero_compteurs')->where('type', $type)->delete();
            foreach (glob("{$barriere}/*") ?: [] as $fichier) {
                unlink($fichier);
            }
            rmdir($barriere);
        }
    }
}
