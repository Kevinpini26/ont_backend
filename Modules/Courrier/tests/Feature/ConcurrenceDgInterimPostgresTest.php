<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\DgInterim;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Services\DgInterimService;
use Symfony\Component\Process\Process;

class ConcurrenceDgInterimPostgresTest extends CourrierTestCase
{
    use DatabaseMigrations;

    public function test_deux_ouvertures_concurrentes_ne_creent_quun_interim(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $dga = $this->agent(Poste::DGA, $direction);
        $barriere = $this->nouvelleBarriere();
        $processus = [1, 2];
        try {
            $resultats = $this->executerEnsemble($barriere, array_map(fn (int $numero) => new Process([
                PHP_BINARY, base_path('Modules/Courrier/tests/Support/dg_interim_worker.php'),
                'ouvrir', (string) $dg->id, (string) $dga->id, $barriere, (string) $numero,
            ], base_path(), ['APP_ENV' => 'testing']), $processus));
            $this->assertSame(['ok', 'refused'], collect($resultats)->pluck('status')->sort()->values()->all());
            $this->assertSame(1, DgInterim::query()->whereNull('ended_at')->count());
            $this->assertSame(1, DgInterim::query()->count());
        } finally {
            $this->nettoyerBarriere($barriere);
        }
    }

    public function test_fermeture_et_signature_concurrentes_respectent_le_verrou_institutionnel(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $dga = $this->agent(Poste::DGA, $direction);
        app(DgInterimService::class)->ouvrir($dg, $dga, 'Absence officielle');
        $d = Courrier::factory()->create([
            'sens' => 'sortant', 'statut' => CourrierStatut::PROJET_A_VALIDER,
            'destinataire_externe_nom' => 'Destinataire officiel',
            'relecture_validee_at' => now(),
            'projet_reponse_contenu' => ['type' => 'doc', 'content' => []],
        ]);
        $this->marquerDecharge($d);
        $barriere = $this->nouvelleBarriere();
        try {
            $resultats = $this->executerEnsemble($barriere, [
                new Process([PHP_BINARY, base_path('Modules/Courrier/tests/Support/signature_dg_worker.php'),
                    (string) $d->id, (string) $dga->id, $barriere, '1'], base_path(), ['APP_ENV' => 'testing']),
                new Process([PHP_BINARY, base_path('Modules/Courrier/tests/Support/dg_interim_worker.php'),
                    'terminer', (string) $dg->id, '0', $barriere, '2'], base_path(), ['APP_ENV' => 'testing']),
            ]);
            $this->assertSame('ok', $resultats[1]['status']);
            $this->assertContains($resultats[0]['status'], ['ok', 'refused']);
            $this->assertSame(0, DgInterim::query()->whereNull('ended_at')->count());
            $d->refresh();
            if ($resultats[0]['status'] === 'ok') {
                $this->assertSame($dga->id, $d->signataire_id);
                $this->assertNotNull($d->numero_depart);
                $this->assertNotNull($d->pdf_chemin);
                $this->assertSame(hash('sha256', Storage::disk('local')->get($d->pdf_chemin)), $d->pdf_sha256);
                $this->assertSame(1, $d->transitions()->where('statut', CourrierStatut::SIGNE)->count());
            } else {
                $this->assertNull($d->signataire_id);
                $this->assertNull($d->numero_depart);
                $this->assertNull($d->pdf_chemin);
                $this->assertNull($d->pdf_sha256);
            }
        } finally {
            $this->nettoyerBarriere($barriere);
            if ($d->fresh()?->pdf_chemin) {
                Storage::disk('local')->delete($d->fresh()->pdf_chemin);
            }
        }
    }

    private function nouvelleBarriere(): string
    {
        $chemin = sys_get_temp_dir().'/ont-interim-dg-'.bin2hex(random_bytes(6));
        mkdir($chemin, 0700, true);

        return $chemin;
    }

    /** @param  Process[]  $processus */
    private function executerEnsemble(string $barriere, array $processus): array
    {
        try {
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

            return collect([1, 2])->map(fn ($numero) => json_decode((string) file_get_contents($barriere.'/result-'.$numero.'.json'), true, flags: JSON_THROW_ON_ERROR))->all();
        } finally {
            foreach ($processus as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    private function nettoyerBarriere(string $barriere): void
    {
        foreach (glob($barriere.'/*') ?: [] as $fichier) {
            unlink($fichier);
        }
        rmdir($barriere);
    }
}
