<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Le script de répétition refuse de démarrer hors de la base jetable et du stockage temporaire :
 * une répétition ne doit jamais écrire dans une vraie base ni dans storage/app.
 */
class ControleRepetitionTest extends TestCase
{
    private function controler(string $base, string $stockage): Process
    {
        $process = new Process(['bash', __DIR__.'/../../scripts/repetition.sh', '--verifier-seulement'], null, [
            'DB_DATABASE' => $base,
            'LARAVEL_STORAGE_PATH' => $stockage,
        ]);
        $process->run();

        return $process;
    }

    public function test_refuse_toute_autre_base_que_la_base_de_repetition(): void
    {
        $stockage = sys_get_temp_dir().'/ont-repetition-test-'.getmypid();

        foreach (['ont', 'systeme_ont', 'ont_testing', 'fpt_ont', ''] as $base) {
            $process = $this->controler($base, $stockage);
            $this->assertNotSame(0, $process->getExitCode(), "La base « {$base} » aurait dû être refusée.");
            $this->assertStringContainsString('ARRÊT', $process->getOutput().$process->getErrorOutput());
        }
    }

    public function test_refuse_le_stockage_reel_ou_un_stockage_absent(): void
    {
        $reel = realpath(__DIR__.'/../../storage');

        foreach ([$reel, $reel.'/app', $reel.'/app/private', $reel.'/../storage', ''] as $stockage) {
            $process = $this->controler('ont_repetition_finale', $stockage);
            $this->assertNotSame(0, $process->getExitCode(), "Le stockage « {$stockage} » aurait dû être refusé.");
            $this->assertStringContainsString('ARRÊT', $process->getOutput().$process->getErrorOutput());
        }
    }

    public function test_accepte_la_base_de_repetition_avec_un_stockage_temporaire(): void
    {
        $stockage = sys_get_temp_dir().'/ont-repetition-test-'.getmypid();

        $process = $this->controler('ont_repetition_finale', $stockage);

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('ont_repetition_finale', $process->getOutput());
    }
}
