<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrenceDispatchLegacyPostgresTest extends TestCase
{
    use DatabaseMigrations;

    public function test_deux_sec2_ne_materialisent_quun_dispatch_avec_lauteur_historique(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $direction = Direction::factory()->create();
        $dg = User::factory()->agentCircuitCourrier(Poste::DG, $direction)->create();
        $sec2 = collect([1, 2])->map(fn () => User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_2, $direction)->create());
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_DISPATCH]);
        $imputation = $courrier->imputations()->create([
            'direction_id' => $direction->id, 'mention' => 'pour_attribution',
            'est_principale' => true, 'imputee_par_id' => $dg->id,
        ]);
        $imputation->refresh();
        $avant = $imputation->getRawOriginal();
        $barriere = sys_get_temp_dir().'/ont-dispatch-legacy-'.bin2hex(random_bytes(6));
        mkdir($barriere, 0700, true);
        $processus = [];
        try {
            foreach ($sec2 as $index => $acteur) {
                $process = new Process([PHP_BINARY, base_path('Modules/Courrier/tests/Support/dispatch_legacy_worker.php'), (string) $courrier->id, (string) $acteur->id, $barriere, (string) ($index + 1)], base_path(), ['APP_ENV' => 'testing']);
                $process->setTimeout(30)->start();
                $processus[] = $process;
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
            $resultats = collect([1, 2])->map(fn ($numero) => json_decode((string) file_get_contents($barriere.'/result-'.$numero.'.json'), true, flags: JSON_THROW_ON_ERROR));
            $this->assertSame(['ok', 'refused'], $resultats->pluck('status')->sort()->values()->all(), $resultats->toJson());
            $this->assertSame(1, DispatchCourrier::query()->count());
            $dispatch = DispatchCourrier::query()->sole();
            $this->assertSame($dg->id, $dispatch->decisionnaire_id);
            $this->assertSame(DispatchStatut::EXECUTE, $dispatch->statut);
            $this->assertSame(CourrierStatut::DISPATCH_EXECUTE, $courrier->fresh()->statut);
            $this->assertSame($avant, $imputation->fresh()->getRawOriginal());
        } finally {
            foreach ($processus as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (glob($barriere.'/*') ?: [] as $fichier) {
                unlink($fichier);
            }
            rmdir($barriere);
        }
    }
}
