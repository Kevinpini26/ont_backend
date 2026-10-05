<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\DocumentNumerise;
use Modules\Kernel\Models\JetonCaptureNumerisation;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Models\Stagiaire;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrenceGestionnaireDocumentNumerisePostgresTest extends TestCase
{
    use DatabaseMigrations;

    public function test_deux_captures_publiques_concurrentes_dun_stagiaire_creent_deux_versions_uniques(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('ont_testing', config('database.connections.pgsql.database'));

        $idsDirectionsAvant = DB::table('directions')->pluck('id')->all();
        $idsSitesAvant = DB::table('sites')->pluck('id')->all();
        $idsUtilisateursAvant = DB::table('users')->pluck('id')->all();
        $fichiersAvant = Storage::disk('local')->allFiles('numerisations');
        $barriere = sys_get_temp_dir().'/ont-numerisation-version-'.bin2hex(random_bytes(6));
        mkdir($barriere, 0700, true);
        $triggerInstalle = false;
        $processus = [];
        $stagiaire = null;
        $courrierId = null;
        $dossierId = null;
        $fichiersCrees = [];

        try {
            $dfp = User::factory()->agentDfp()->create();
            $stagiaire = Stagiaire::factory()->create();
            $courrier = $stagiaire->courrier()->firstOrFail();
            $courrierId = $courrier->id;
            $dossierId = $courrier->dossier_id;
            $jeton = JetonCaptureNumerisation::genererPour($stagiaire, $dfp);

            DB::unprepared('DROP TRIGGER IF EXISTS ont_concurrence_numerisation_version ON documents_numerises');
            DB::unprepared('DROP FUNCTION IF EXISTS ont_concurrence_numerisation_version()');
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION ont_concurrence_numerisation_version() RETURNS trigger AS $$
                BEGIN
                    PERFORM pg_sleep(4);
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER ont_concurrence_numerisation_version
                    BEFORE INSERT ON documents_numerises
                    FOR EACH ROW EXECUTE FUNCTION ont_concurrence_numerisation_version();
            SQL);
            $triggerInstalle = true;

            $cheminsResultats = ["{$barriere}/result-1.json", "{$barriere}/result-2.json"];
            $worker = base_path('Modules/Kernel/tests/Support/capture_stagiaire_numerisation_worker.php');
            $configurationDb = config('database.connections.pgsql');
            $environnement = [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => $configurationDb['host'],
                'DB_PORT' => (string) $configurationDb['port'],
                'DB_DATABASE' => 'ont_testing',
                'DB_USERNAME' => $configurationDb['username'],
                'DB_PASSWORD' => $configurationDb['password'],
                'DB_URL' => '',
                'QUEUE_CONNECTION' => 'sync',
            ];

            $processus = [
                new Process([PHP_BINARY, $worker, $jeton->token, $barriere, '1', $cheminsResultats[0]], base_path(), $environnement),
                new Process([PHP_BINARY, $worker, $jeton->token, $barriere, '2', $cheminsResultats[1]], base_path(), $environnement),
            ];
            foreach ($processus as $process) {
                $process->setTimeout(45)->start();
            }

            $limite = microtime(true) + 20;
            while ((! file_exists("{$barriere}/ready-1") || ! file_exists("{$barriere}/ready-2")) && microtime(true) < $limite) {
                usleep(10_000);
            }
            $this->assertFileExists("{$barriere}/ready-1");
            $this->assertFileExists("{$barriere}/ready-2");
            file_put_contents("{$barriere}/start", 'go');

            foreach ($processus as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            }

            $resultats = array_map(
                fn (string $chemin): array => json_decode((string) file_get_contents($chemin), true, flags: JSON_THROW_ON_ERROR),
                $cheminsResultats,
            );
            foreach ($resultats as $resultat) {
                $this->assertArrayHasKey('body', $resultat, json_encode($resultat, JSON_UNESCAPED_UNICODE));
                $this->assertSame(201, $resultat['http'], json_encode($resultat, JSON_UNESCAPED_UNICODE));
            }
            $documents = DocumentNumerise::query()
                ->where('numerisable_type', $stagiaire->getMorphClass())
                ->where('numerisable_id', $stagiaire->id)
                ->orderBy('version')
                ->get();
            $versionsReponse = array_map(fn (array $resultat): int => $resultat['body']['document']['version'], $resultats);
            sort($versionsReponse);
            $versionsBase = $documents->pluck('version')->all();
            $fichiersCrees = array_values(array_diff(Storage::disk('local')->allFiles('numerisations'), $fichiersAvant));

            $this->assertSame([1, 2], $versionsReponse);
            $this->assertSame([1, 2], $versionsBase);
            $this->assertCount(2, $documents);
            $this->assertCount(2, $fichiersCrees);
            $this->assertSame($documents->pluck('chemin')->sort()->values()->all(), collect($fichiersCrees)->sort()->values()->all());
            foreach ($documents as $document) {
                Storage::disk('local')->assertExists($document->chemin);
                $this->assertGreaterThan(0, Storage::disk('local')->size($document->chemin));
            }
            $this->assertNotNull($jeton->fresh()->consomme_at);
        } finally {
            foreach ($processus as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            if ($triggerInstalle) {
                DB::unprepared('DROP TRIGGER IF EXISTS ont_concurrence_numerisation_version ON documents_numerises');
                DB::unprepared('DROP FUNCTION IF EXISTS ont_concurrence_numerisation_version()');
            }

            $fichiersCrees = array_values(array_unique(array_merge(
                $fichiersCrees,
                array_diff(Storage::disk('local')->allFiles('numerisations'), $fichiersAvant),
            )));
            Storage::disk('local')->delete($fichiersCrees);

            if ($stagiaire !== null) {
                DocumentNumerise::query()
                    ->where('numerisable_type', $stagiaire->getMorphClass())
                    ->where('numerisable_id', $stagiaire->id)
                    ->delete();
                JetonCaptureNumerisation::query()
                    ->where('capturable_type', $stagiaire->getMorphClass())
                    ->where('capturable_id', $stagiaire->id)
                    ->delete();
                DB::table('stagiaires')->where('id', $stagiaire->id)->delete();
            }
            if ($courrierId !== null) {
                DB::table('courrier_transitions')->where('courrier_id', $courrierId)->delete();
                DB::table('courriers')->where('id', $courrierId)->delete();
            }
            if ($dossierId !== null) {
                DB::table('dossiers')->where('id', $dossierId)->delete();
            }
            DB::table('users')->whereNotIn('id', $idsUtilisateursAvant)->delete();
            DB::table('directions')->whereNotIn('id', $idsDirectionsAvant)->delete();
            DB::table('sites')->whereNotIn('id', $idsSitesAvant)->delete();

            foreach (glob($barriere.'/*') ?: [] as $fichier) {
                unlink($fichier);
            }
            rmdir($barriere);
        }
    }
}