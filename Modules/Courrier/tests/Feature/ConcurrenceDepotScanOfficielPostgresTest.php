<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\DocumentProduitScanOfficiel;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrenceDepotScanOfficielPostgresTest extends TestCase
{
    public function test_deux_depots_concurrents_ne_creent_quun_scan_initial(): void
    {
        $this->verifierPreconditionsSansMigration();

        $direction = Direction::factory()->create();
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        $directeur = User::factory()->directeurDirection($direction)->create(['initiales_reference' => 'ABC']);
        $contenu = [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Texte officiel.']]]],
        ];

        $creation = $this->actingAs($secretariat)->postJson('/api/v1/documents-produits-direction/autonomes', [
            'objet' => 'Document concurrent',
            'contenu' => $contenu,
        ])->assertCreated();
        $document = DocumentProduitDirection::query()->findOrFail($creation->json('data.id'));
        $this->actingAs($secretariat)
            ->postJson("/api/v1/documents-produits-direction/{$document->id}/soumettre", ['version_contenu' => 1])
            ->assertOk();
        $this->actingAs($directeur)
            ->postJson("/api/v1/documents-produits-direction/{$document->id}/valider")
            ->assertOk();
        $document->refresh();
        $this->assertSame(DocumentProduitStatut::VALIDE, $document->statut);

        $fichierScan = null;
        $barriere = null;
        $cles = [(string) Str::uuid(), (string) Str::uuid()];
        $processus = [];

        try {
            $repertoireScans = "documents-internes/scans-officiels/{$document->id}";
            $this->assertSame([], Storage::disk('local')->allFiles($repertoireScans), 'Le répertoire de scans doit être vierge avant le test.');

            $fichierScan = tempnam(sys_get_temp_dir(), 'ont-scan-officiel-');
            $this->assertNotFalse($fichierScan);
            file_put_contents($fichierScan, Storage::disk('local')->get($document->pdf_officiel_chemin));

            $barriere = sys_get_temp_dir().'/ont-scan-officiel-'.bin2hex(random_bytes(6));
            $this->assertTrue(mkdir($barriere, 0700));

            foreach ($cles as $index => $cle) {
                $process = new Process([
                    PHP_BINARY,
                    base_path('Modules/Courrier/tests/Support/scan_officiel_worker.php'),
                    (string) $document->id,
                    (string) $secretariat->id,
                    $fichierScan,
                    $cle,
                    $barriere,
                    (string) ($index + 1),
                ], base_path(), [
                    'APP_ENV' => 'testing',
                    'DB_CONNECTION' => 'pgsql',
                    'DB_DATABASE' => 'ont_testing',
                    'DB_URL' => '',
                    'DB_HOST' => (string) config('database.connections.pgsql.host'),
                    'DB_PORT' => (string) config('database.connections.pgsql.port'),
                    'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
                    'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
                ]);
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
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            }

            $resultats = collect([1, 2])->map(
                fn (int $numero): array => json_decode(
                    (string) file_get_contents($barriere.'/result-'.$numero.'.json'),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                ),
            );
            $this->assertSame(['conflict', 'created'], $resultats->pluck('status')->sort()->values()->all());
            $this->assertSame(1, DocumentProduitScanOfficiel::query()
                ->where('document_produit_direction_id', $document->id)
                ->count());
            $this->assertSame(DocumentProduitStatut::SCAN_ENREGISTRE, $document->fresh()->statut);
            $this->assertSame(1, DocumentProduitScanOfficiel::query()
                ->where('document_produit_direction_id', $document->id)
                ->where('est_actif', true)
                ->count());
        } finally {
            foreach ($processus as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            if ($fichierScan !== null && is_file($fichierScan)) {
                unlink($fichierScan);
            }

            if ($barriere !== null && is_dir($barriere)) {
                foreach (['ready-1', 'ready-2', 'start', 'result-1.json', 'result-2.json'] as $nomFichier) {
                    $chemin = $barriere.'/'.$nomFichier;
                    if (is_file($chemin)) {
                        unlink($chemin);
                    }
                }

                if (scandir($barriere) === ['.', '..']) {
                    rmdir($barriere);
                }
            }
        }
    }

    private function verifierPreconditionsSansMigration(): void
    {
        $database = config('database.connections.pgsql');
        $valeursRequises = [
            app()->environment() === 'testing',
            env('APP_ENV') === 'testing',
            config('database.default') === 'pgsql',
            env('DB_CONNECTION') === 'pgsql',
            ($database['database'] ?? null) === 'ont_testing',
            env('DB_DATABASE') === 'ont_testing',
            in_array(env('DB_URL'), ['', null], true),
            in_array($database['url'] ?? null, ['', null], true),
        ];

        if (in_array(false, $valeursRequises, true)) {
            throw new RuntimeException('Le test de concurrence exige une configuration PostgreSQL testing résolue vers ont_testing avec DB_URL vide.');
        }

        foreach ([
            'documents_produits_direction',
            'courriers',
            'document_produit_scans_officiels',
            'documents_numerises',
            'users',
            'audit_logs',
            'directions',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Schéma prérequis absent : {$table}. Aucune migration automatique n’est exécutée.");
            }
        }
    }
}
