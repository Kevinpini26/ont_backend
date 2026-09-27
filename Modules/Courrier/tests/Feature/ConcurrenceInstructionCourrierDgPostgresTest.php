<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\InstructionCourrierDg;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrenceInstructionCourrierDgPostgresTest extends TestCase
{
    use DatabaseMigrations;

    public function test_deux_processus_consommant_la_meme_instruction_ne_creent_quun_courrier(): void
    {
        $direction = Direction::factory()->create();
        $dg = User::factory()->agentCircuitCourrier(Poste::DG, $direction)->create();
        $sec1 = User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_1, $direction)->create();
        $relecteur = User::factory()->agentCircuitCourrier(Poste::ASSISTANT_1, $direction)->create();
        $instruction = InstructionCourrierDg::query()->create([
            'donneur_id' => $dg->id,
            'instruction' => 'Préparer une note institutionnelle concurrente.',
        ]);
        $barriere = sys_get_temp_dir().'/ont-instruction-dg-'.$instruction->id.'-'.bin2hex(random_bytes(6));
        mkdir($barriere, 0700, true);
        $worker = base_path('Modules/Courrier/tests/Support/initier_dg_instruction_worker.php');

        try {
            $processus = [
                new Process([PHP_BINARY, $worker, (string) $instruction->id, (string) $sec1->id, (string) $direction->id, (string) $relecteur->id, $barriere, '1'], base_path(), ['APP_ENV' => 'testing']),
                new Process([PHP_BINARY, $worker, (string) $instruction->id, (string) $sec1->id, (string) $direction->id, (string) $relecteur->id, $barriere, '2'], base_path(), ['APP_ENV' => 'testing']),
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
            $this->assertSame(1, Courrier::withoutGlobalScopes()->where('initie_par_dg', true)->count());
            $this->assertSame(1, InstructionCourrierDg::query()->whereNotNull('consomme_at')->count());
            $this->assertSame($resultats->firstWhere('status', 'ok')['courrier_id'], $instruction->fresh()->courrier_id);
        } finally {
            foreach (glob($barriere.'/*') ?: [] as $fichier) {
                unlink($fichier);
            }
            rmdir($barriere);
        }
    }
}
