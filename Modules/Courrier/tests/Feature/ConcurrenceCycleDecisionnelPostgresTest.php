<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrenceCycleDecisionnelPostgresTest extends TestCase
{
    use DatabaseMigrations;

    public function test_deux_processus_reellement_concurrents_nouvrent_quun_cycle(): void
    {
        $direction = Direction::factory()->create();
        $dg = User::factory()->agentCircuitCourrier(Poste::DG, $direction)->create();
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $barriere = sys_get_temp_dir().'/ont-cycle-'.$courrier->id.'-'.bin2hex(random_bytes(6));
        mkdir($barriere, 0700, true);
        $worker = base_path('Modules/Courrier/tests/Support/ouvrir_cycle_worker.php');

        $processus = [
            new Process([PHP_BINARY, $worker, (string) $courrier->id, (string) $dg->id, $barriere, '1'], base_path(), ['APP_ENV' => 'testing']),
            new Process([PHP_BINARY, $worker, (string) $courrier->id, (string) $dg->id, $barriere, '2'], base_path(), ['APP_ENV' => 'testing']),
        ];
        foreach ($processus as $process) {
            $process->setTimeout(30)->start();
        }

        $limite = microtime(true) + 10;
        while ((! file_exists($barriere.'/ready-1') || ! file_exists($barriere.'/ready-2')) && microtime(true) < $limite) {
            usleep(10_000);
        }
        $this->assertFileExists($barriere.'/ready-1');
        $this->assertFileExists($barriere.'/ready-2');
        file_put_contents($barriere.'/start', 'go');

        foreach ($processus as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }
        $resultats = collect([1, 2])->map(fn (int $numero) => json_decode((string) file_get_contents($barriere.'/result-'.$numero.'.json'), true, flags: JSON_THROW_ON_ERROR));

        $this->assertSame(['ok', 'refused'], $resultats->pluck('status')->sort()->values()->all());
        $this->assertDatabaseCount('dispatchs_courrier', 1);
        $this->assertDatabaseHas('dispatchs_courrier', ['courrier_id' => $courrier->id, 'cycle' => 1, 'type_destination' => 'classement']);
        $this->assertSame(CourrierStatut::EN_DISPATCH, $courrier->fresh()->statut);

        foreach (glob($barriere.'/*') ?: [] as $fichier) {
            unlink($fichier);
        }
        rmdir($barriere);
    }
}
