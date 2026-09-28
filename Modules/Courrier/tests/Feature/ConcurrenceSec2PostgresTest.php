<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\SensCourrier;
use Modules\Courrier\Models\ClassementDocument;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Services\ArchivageDossierService;
use Modules\Courrier\Services\ClassementDocumentService;
use Modules\Courrier\Services\DispatchCourrierService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Symfony\Component\Process\Process;

class ConcurrenceSec2PostgresTest extends CourrierTestCase
{
    use DatabaseMigrations;

    private function acteurs(): array
    {
        $this->assertSame('pgsql', config('database.default'));
        $direction = Direction::factory()->create();

        return [
            $this->agent(Poste::DG, $direction),
            $this->agent(Poste::SECRETARIAT_2, $direction),
            $this->agent(Poste::SECRETARIAT_2, $direction),
            $direction,
        ];
    }

    private function concurrence(string $action, int $id, User $premier, User $second): void
    {
        $barriere = sys_get_temp_dir().'/ont-sec2-'.bin2hex(random_bytes(6));
        mkdir($barriere, 0700, true);
        $processus = [];
        try {
            foreach ([$premier, $second] as $index => $acteur) {
                $process = new Process([PHP_BINARY, base_path('Modules/Courrier/tests/Support/sec2_execution_worker.php'), $action, (string) $id, (string) $acteur->id, $barriere, (string) ($index + 1)], base_path(), ['APP_ENV' => 'testing']);
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

    private function dispatch(User $dg, User $sec2, Direction $direction, string $type): DispatchCourrier
    {
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        app(DispatchCourrierService::class)->decider($courrier, $dg, [['type' => $type, 'direction_id' => $type === 'direction' ? $direction->id : null, 'instruction' => 'Décision concurrente']]);
        $dispatch = $courrier->dispatchs()->sole();
        $this->receptionnerDispatchSec2($dispatch, $sec2);

        return $dispatch;
    }

    public function test_deux_sec2_sur_le_meme_dispatch(): void
    {
        [$dg, $premier, $second, $direction] = $this->acteurs();
        $dispatch = $this->dispatch($dg, $premier, $direction, 'direction');
        $this->concurrence('dispatch', $dispatch->id, $premier, $second);
        $this->assertSame('execute', $dispatch->fresh()->statut->value);
        $this->assertSame(1, $dispatch->courrier()->withoutGlobalScopes()->firstOrFail()->transitions()->where('statut', 'dispatch_execute')->count());
        $this->assertDatabaseCount('dispatchs_courrier', 1);
    }

    public function test_deux_sec2_sur_le_meme_classement(): void
    {
        [$dg, $premier, $second, $direction] = $this->acteurs();
        $dispatch = $this->dispatch($dg, $premier, $direction, 'classement');
        $this->concurrence('classement', $dispatch->id, $premier, $second);
        $this->assertSame('execute', $dispatch->fresh()->statut->value);
        $this->assertSame(1, ClassementDocument::query()->where('dispatch_courrier_id', $dispatch->id)->count());
    }

    public function test_deux_sec2_sur_le_meme_archivage_dossier(): void
    {
        [$dg, $premier, $second, $direction] = $this->acteurs();
        $dispatch = $this->dispatch($dg, $premier, $direction, 'classement');
        $classement = app(ClassementDocumentService::class)->classer($dispatch, $premier, ['emplacement' => 'Archives']);
        app(ClassementDocumentService::class)->archiver($classement, $premier, null);
        $dossier = $dispatch->dossier;
        app(ArchivageDossierService::class)->decider($dossier, $dg);
        $this->concurrence('archive', $dossier->id, $premier, $second);
        $this->assertSame('archive', $dossier->fresh()->statut_archivage);
        $this->assertDatabaseCount('classements_documents', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'dossier.archive', 'auditable_id' => $dossier->id]);
    }

    public function test_deux_sec2_sur_le_meme_envoi_officiel(): void
    {
        [$dg, $premier, $second] = $this->acteurs();
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::SIGNE, 'sens' => SensCourrier::SORTANT,
            'created_by' => $premier->id, 'signe_at' => now(), 'signataire_id' => $dg->id,
            'relecture_validee_at' => now(), 'numero_depart' => 'SEC2-CONCURRENCE',
            'destinataire_externe_nom' => 'Partenaire signé',
        ]);
        $chemin = 'courriers/'.$courrier->id.'/sec2-concurrence.pdf';
        Storage::disk('local')->put($chemin, '%PDF document officiel');
        $courrier->update(['pdf_chemin' => $chemin, 'pdf_sha256' => hash('sha256', '%PDF document officiel')]);
        $this->marquerDecharge($courrier, Poste::SECRETARIAT_2);
        try {
            $this->concurrence('envoi', $courrier->id, $premier, $second);
            $this->assertSame(CourrierStatut::ENVOYE, $courrier->fresh()->statut);
            $this->assertSame(1, $courrier->transitions()->where('statut', 'envoye')->count());
            $this->assertDatabaseHas('audit_logs', ['action' => 'courrier.envoye', 'auditable_id' => $courrier->id]);
        } finally {
            Storage::disk('local')->delete($chemin);
        }
    }
}
