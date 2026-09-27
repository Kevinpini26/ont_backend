<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\ModeReception;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrenceIdentitesPostgresTest extends TestCase
{
    use DatabaseMigrations;

    public function test_vingt_references_documentaires_concurrentes_sont_uniques(): void
    {
        $direction = Direction::factory()->create(['code' => 'TST']);
        $directeur = User::factory()->directeurDirection($direction)->create();
        $courriers = Courrier::factory()->count(20)->create(['direction_origine_id' => $direction->id]);
        $resultats = $this->executerConcurremment('reference', $courriers->pluck('id')->all(), $directeur->id);

        $this->assertSame(20, $resultats->where('status', 'ok')->count());
        $this->assertSame(20, $resultats->pluck('value')->unique()->count());
        $this->assertSame(20, Courrier::withoutGlobalScopes()->whereNotNull('reference_documentaire')->distinct()->count('reference_documentaire'));
    }

    public function test_vingt_numeros_enregistrement_concurrents_sont_uniques(): void
    {
        $resultats = $this->executerConcurremment('numero', range(1, 20), 0);

        $this->assertSame(20, $resultats->where('status', 'ok')->count());
        $this->assertSame(20, $resultats->pluck('value')->unique()->count());
    }

    public function test_enregistrement_concurrent_des_depots_publics_reste_unique(): void
    {
        $direction = Direction::factory()->create();
        $receptions = User::factory()->count(20)->agentCircuitCourrier(Poste::RECEPTION, $direction)->create();
        $courriers = Courrier::factory()->count(20)->create([
            'statut' => CourrierStatut::RECU,
            'mode_reception' => ModeReception::DEPOT_EN_LIGNE,
            'numero_enregistrement' => null,
        ]);

        $resultats = $this->executerConcurremment('depot', $courriers->pluck('id')->all(), $receptions->pluck('id')->all());

        $this->assertSame(20, $resultats->where('status', 'ok')->count());
        $this->assertSame(20, $resultats->pluck('value')->unique()->count());
        $this->assertSame(20, Courrier::withoutGlobalScopes()->whereIn('id', $courriers->pluck('id'))->whereNotNull('numero_enregistrement')->distinct()->count('numero_enregistrement'));

        $memeCourrier = Courrier::factory()->create([
            'statut' => CourrierStatut::RECU,
            'mode_reception' => ModeReception::DEPOT_EN_LIGNE,
            'numero_enregistrement' => null,
        ]);
        $double = $this->executerConcurremment('depot', [$memeCourrier->id, $memeCourrier->id], $receptions->take(2)->pluck('id')->all());

        $this->assertSame(1, $double->where('status', 'ok')->count());
        $this->assertSame(1, $double->where('status', 'error')->count());
        $this->assertNotNull($memeCourrier->fresh()->numero_enregistrement);
        $this->assertSame(1, Courrier::withoutGlobalScopes()->whereKey($memeCourrier->id)->whereNotNull('numero_enregistrement')->count());
    }

    /**
     * @param  array<int, int>  $identifiants
     * @param  int|array<int, int>  $acteurIds
     */
    private function executerConcurremment(string $mode, array $identifiants, int|array $acteurIds)
    {
        $barriere = sys_get_temp_dir().'/ont-identites-'.bin2hex(random_bytes(6));
        mkdir($barriere, 0700, true);
        $worker = base_path('Modules/Courrier/tests/Support/identite_worker.php');
        $processus = collect($identifiants)->values()->map(fn (int $identifiant, int $index) => new Process([
            PHP_BINARY, $worker, $mode, (string) $identifiant, (string) (is_array($acteurIds) ? $acteurIds[$index] : $acteurIds), $barriere, (string) $index,
        ], base_path(), ['APP_ENV' => 'testing']));
        $processus->each(function (Process $process): void {
            $process->setTimeout(45)->start();
        });

        $limite = microtime(true) + 20;
        while (count(glob($barriere.'/ready-*') ?: []) < count($identifiants) && microtime(true) < $limite) {
            usleep(10_000);
        }
        $this->assertCount(count($identifiants), glob($barriere.'/ready-*') ?: []);
        file_put_contents($barriere.'/start', 'go');
        $processus->each(function (Process $process): void {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        });
        $resultats = collect(array_keys($identifiants))->map(fn (int $index) => json_decode((string) file_get_contents($barriere.'/result-'.$index.'.json'), true, flags: JSON_THROW_ON_ERROR));
        foreach (glob($barriere.'/*') ?: [] as $fichier) {
            unlink($fichier);
        }
        rmdir($barriere);

        return $resultats;
    }
}
