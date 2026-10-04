<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\ClassementDocument;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Courrier\Services\ArchivageDossierService;
use Modules\Courrier\Services\ClassementDocumentService;
use Modules\Courrier\Services\CourrierCircuitService;
use Modules\Courrier\Services\DispatchCourrierService;
use Modules\Courrier\Services\MissionDocumentaireService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Symfony\Component\Process\Process;

class ConcurrenceArchivageEligibleActivityPostgresTest extends CourrierTestCase
{
    use DatabaseMigrations;

    public function test_creation_de_mission_est_deja_inatteignable_pour_un_dossier_eligible(): void
    {
        $this->assertSame('pgsql', config('database.default'));

        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $assistant = $this->agent(Poste::ASSISTANT_1, $direction);

        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
        ]);

        app(DispatchCourrierService::class)->decider($courrier, $dg, [[
            'type' => 'classement',
            'instruction' => 'Classement du document avant archivage du dossier.',
        ]]);
        $dispatch = $courrier->dispatchs()->sole();
        $this->receptionnerDispatchSec2($dispatch, $sec2);

        $classement = app(ClassementDocumentService::class)->classer(
            $dispatch,
            $sec2,
            ['emplacement' => 'Archives'],
        );
        app(ClassementDocumentService::class)->archiver($classement, $sec2, null);

        $dossier = $courrier->dossier()->firstOrFail();
        $this->assertSame('actif', $dossier->statut_archivage);
        $this->assertSame('archive', $classement->fresh()->statut->value);

        try {
            app(MissionDocumentaireService::class)->creer(
                $courrier,
                $dg,
                $assistant,
                'Mission concurrente de diagnostic.',
            );
            $this->fail('Une mission sur un courrier archivé aurait dû être refusée avant le guard dossier.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('courrier', $exception->errors());
        }

        $this->assertSame(0, MissionDocumentaire::query()->where('dossier_id', $dossier->id)->count());

        $decide = app(ArchivageDossierService::class)->decider($dossier, $dg);

        $this->assertSame('a_archiver', $decide->fresh()->statut_archivage);
        $this->assertSame(0, MissionDocumentaire::query()->where('dossier_id', $dossier->id)->count());
    }

    public function test_annotation_est_autorisee_seulement_tant_que_le_dossier_est_actif(): void
    {
        $this->assertSame('pgsql', config('database.default'));

        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $circuit = app(CourrierCircuitService::class);
        $archivage = app(ArchivageDossierService::class);

        $avant = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $classementAvant = $this->classerEtArchiver($avant, $dg, $sec2, 'ANNOTATION-AVANT');
        $this->assertTrue(Gate::forUser($dg)->allows('annoter', $avant));

        $annotationAvant = $circuit->ajouterAnnotation($avant, $dg, 'Annotation avant décision.');

        $this->assertDatabaseHas('courrier_annotations', ['id' => $annotationAvant->id, 'auteur_id' => $dg->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'courrier.annotation_creee', 'auditable_id' => $avant->id]);
        $this->assertSame('archive', $classementAvant->fresh()->statut->value);
        $this->assertSame('actif', $avant->dossier()->firstOrFail()->fresh()->statut_archivage);
        $this->assertSame('a_archiver', $archivage->decider($avant->dossier()->firstOrFail(), $dg)->fresh()->statut_archivage);

        $apres = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $classementApres = $this->classerEtArchiver($apres, $dg, $sec2, 'ANNOTATION-APRES');
        $dossierApres = $apres->dossier()->firstOrFail();
        $this->assertSame('a_archiver', $archivage->decider($dossierApres, $dg)->fresh()->statut_archivage);
        $this->assertTrue(Gate::forUser($dg)->allows('annoter', $apres));

        try {
            $circuit->ajouterAnnotation($apres, $dg, 'Annotation après décision.');
            $this->fail('Un dossier A_ARCHIVER ne doit plus accepter une annotation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dossier', $exception->errors());
        }

        $this->assertSame(0, $apres->annotations()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'courrier.annotation_creee', 'auditable_id' => $apres->id]);
        $this->assertSame('a_archiver', $dossierApres->fresh()->statut_archivage);
        $this->assertSame('archive', $classementApres->fresh()->statut->value);

        $archivage->archiver($dossierApres, $sec2);
        $this->assertSame('archive', $dossierApres->fresh()->statut_archivage);

        try {
            $circuit->ajouterAnnotation($apres, $dg, 'Annotation après archivage final.');
            $this->fail('Un dossier ARCHIVE ne doit plus accepter une annotation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dossier', $exception->errors());
        }

        $this->assertSame(0, $apres->annotations()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'courrier.annotation_creee', 'auditable_id' => $apres->id]);
        $this->assertSame('archive', $classementApres->fresh()->statut->value);
    }

    public function test_annotation_et_decision_archivage_sont_serialisees(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('ont_testing', config('database.connections.pgsql.database'));

        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $classement = $this->classerEtArchiver($courrier, $dg, $sec2, 'ANNOTATION-CONCURRENCE');
        $dossier = $courrier->dossier()->firstOrFail();

        $this->assertTrue(Gate::forUser($dg)->allows('annoter', $courrier));
        $this->assertSame('actif', $dossier->statut_archivage);
        $this->assertSame('archive', $classement->fresh()->statut->value);
        $this->assertSame(0, $courrier->annotations()->count());
        $this->assertSame(0, $courrier->missionsDocumentaires()->whereIn('statut', ['assignee', 'en_cours'])->count());
        $this->assertSame(0, $courrier->dispatchs()->where('statut', 'en_attente')->count());
        $this->assertSame(0, $courrier->traitementsDirection()->where('statut', '!=', 'termine_directeur')->count());
        $this->assertSame(0, $courrier->documentProduitDirection()->where('statut', '!=', 'entre_circuit')->count());

        $barriere = sys_get_temp_dir().'/ont-annotation-archivage-'.bin2hex(random_bytes(6));
        mkdir($barriere, 0700, true);
        $triggerInstalle = false;
        $verrouDetenu = false;
        $processusA = null;
        $processusB = null;

        try {
            DB::unprepared('DROP TRIGGER IF EXISTS ont_test_annotation_barrier ON courrier_annotations');
            DB::unprepared('DROP FUNCTION IF EXISTS ont_test_annotation_barrier()');
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION ont_test_annotation_barrier() RETURNS trigger AS $$
                BEGIN
                    PERFORM pg_advisory_xact_lock(20261004, 429);
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER ont_test_annotation_barrier
                BEFORE INSERT ON courrier_annotations
                FOR EACH ROW EXECUTE FUNCTION ont_test_annotation_barrier()
                SQL);
            $triggerInstalle = true;
            DB::select('SELECT pg_advisory_lock(20261004, 429)');
            $verrouDetenu = true;

            $worker = base_path('Modules/Courrier/tests/Support/archivage_annotation_worker.php');
            $env = $this->environnementWorkerPostgres();
            $processusA = new Process([
                PHP_BINARY, $worker, 'annotation', (string) $courrier->id,
                (string) $dossier->id, (string) $dg->id, $barriere,
            ], base_path(), $env);
            $processusA->setTimeout(30)->start();

            $this->attendreAttenteAdvisory($barriere, 'annotation', 'courrier_annotations');

            $processusB = new Process([
                PHP_BINARY, $worker, 'archivage', (string) $courrier->id,
                (string) $dossier->id, (string) $dg->id, $barriere,
            ], base_path(), $env);
            $processusB->setTimeout(30)->start();
            $this->attendreVerrouTable($barriere, 'archivage', 'users');

            DB::select('SELECT pg_advisory_unlock(20261004, 429)');
            $verrouDetenu = false;
            foreach ([[$processusA, 'annotation'], [$processusB, 'archivage']] as [$process, $operation]) {
                $process->wait();
                $fichierResultat = $barriere.'/'.$operation.'.json';
                $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput()
                    .' operation='.$operation.' result='.(@file_get_contents($fichierResultat) ?: 'missing'));
            }

            $resultatB = json_decode((string) file_get_contents($barriere.'/archivage.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('committed', $resultatB['status']);
            $this->assertSame('a_archiver', $resultatB['statut_archivage']);
            $processusA->wait();

            $resultatA = json_decode((string) file_get_contents($barriere.'/annotation.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('committed', $resultatA['status']);
            $this->assertDatabaseHas('courrier_annotations', [
                'id' => $resultatA['annotation_id'],
                'courrier_id' => $courrier->id,
                'auteur_id' => $dg->id,
                'contenu' => 'Annotation concurrente.',
            ]);
            $this->assertDatabaseHas('audit_logs', ['action' => 'dossier.archivage_decide', 'auditable_id' => $dossier->id]);
            $this->assertDatabaseHas('audit_logs', ['action' => 'courrier.annotation_creee', 'auditable_id' => $courrier->id]);
            $auditDecision = AuditLog::query()->where('action', 'dossier.archivage_decide')->where('auditable_id', $dossier->id)->sole();
            $auditAnnotation = AuditLog::query()->where('action', 'courrier.annotation_creee')->where('auditable_id', $courrier->id)->sole();
            $this->assertNotNull($auditDecision->created_at);
            $this->assertNotNull($auditAnnotation->created_at);
            $this->assertGreaterThanOrEqual($auditAnnotation->created_at->getTimestamp(), $auditDecision->created_at->getTimestamp());
            $this->assertSame('a_archiver', $dossier->fresh()->statut_archivage);
            $this->assertSame('archive', $classement->fresh()->statut->value);
            $this->assertSame(1, $courrier->annotations()->count());
            $this->assertSame(0, $courrier->missionsDocumentaires()->whereIn('statut', ['assignee', 'en_cours'])->count());
            $this->assertSame(0, $courrier->dispatchs()->where('statut', 'en_attente')->count());
            $this->assertSame(0, $courrier->traitementsDirection()->where('statut', '!=', 'termine_directeur')->count());
            $this->assertSame(0, $courrier->documentProduitDirection()->where('statut', '!=', 'entre_circuit')->count());
        } finally {
            if ($verrouDetenu) {
                DB::select('SELECT pg_advisory_unlock(20261004, 429)');
            }
            foreach ([$processusA, $processusB] as $process) {
                if ($process instanceof Process && $process->isRunning()) {
                    $process->stop();
                }
            }
            if ($triggerInstalle) {
                DB::unprepared('DROP TRIGGER IF EXISTS ont_test_annotation_barrier ON courrier_annotations');
                DB::unprepared('DROP FUNCTION IF EXISTS ont_test_annotation_barrier()');
            }
            foreach (glob($barriere.'/*') ?: [] as $fichier) {
                unlink($fichier);
            }
            rmdir($barriere);
        }

        $this->verifierOrdreArchivagePremier($dg, $sec2);
    }

    private function verifierOrdreArchivagePremier(User $dg, User $sec2): void
    {
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $classement = $this->classerEtArchiver($courrier, $dg, $sec2, 'ANNOTATION-ARCHIVAGE-FIRST');
        $dossier = $courrier->dossier()->firstOrFail();
        $this->assertTrue(Gate::forUser($dg)->allows('annoter', $courrier));
        $this->assertSame('actif', $dossier->statut_archivage);
        $this->assertSame('archive', $classement->fresh()->statut->value);

        $barriere = sys_get_temp_dir().'/ont-annotation-archivage-first-'.bin2hex(random_bytes(6));
        mkdir($barriere, 0700, true);
        $verrouDetenu = false;
        $triggerInstalle = false;
        $processusA = null;
        $processusB = null;

        try {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION ont_test_archive_barrier_func() RETURNS trigger AS $$
                BEGIN
                    IF OLD.statut_archivage = 'actif' AND NEW.statut_archivage = 'a_archiver' THEN
                        PERFORM pg_advisory_xact_lock(20261004, 430);
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER ont_test_archive_barrier
                BEFORE UPDATE ON dossiers
                FOR EACH ROW EXECUTE FUNCTION ont_test_archive_barrier_func()
                SQL);
            $triggerInstalle = true;
            DB::select('SELECT pg_advisory_lock(20261004, 430)');
            $verrouDetenu = true;

            $worker = base_path('Modules/Courrier/tests/Support/archivage_annotation_worker.php');
            $env = $this->environnementWorkerPostgres();
            $processusB = new Process([
                PHP_BINARY, $worker, 'archivage', (string) $courrier->id,
                (string) $dossier->id, (string) $dg->id, $barriere,
            ], base_path(), $env);
            $processusB->setTimeout(30)->start();
            $this->attendreAttenteAdvisory($barriere, 'archivage', 'dossiers');

            $processusA = new Process([
                PHP_BINARY, $worker, 'annotation', (string) $courrier->id,
                (string) $dossier->id, (string) $dg->id, $barriere,
            ], base_path(), $env);
            $processusA->setTimeout(30)->start();
            $this->attendreVerrouTable($barriere, 'annotation', 'users');

            DB::select('SELECT pg_advisory_unlock(20261004, 430)');
            $verrouDetenu = false;
            foreach ([[$processusB, 'archivage'], [$processusA, 'annotation']] as [$process, $operation]) {
                $process->wait();
                $fichierResultat = $barriere.'/'.$operation.'.json';
                $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput()
                    .' operation='.$operation.' result='.(@file_get_contents($fichierResultat) ?: 'missing'));
            }

            $resultatB = json_decode((string) file_get_contents($barriere.'/archivage.json'), true, flags: JSON_THROW_ON_ERROR);
            $resultatA = json_decode((string) file_get_contents($barriere.'/annotation.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('committed', $resultatB['status']);
            $this->assertSame('a_archiver', $resultatB['statut_archivage']);
            $this->assertSame('refused', $resultatA['status']);
            $this->assertArrayHasKey('dossier', $resultatA['errors']);
            $this->assertSame('a_archiver', $dossier->fresh()->statut_archivage);
            $this->assertSame('archive', $classement->fresh()->statut->value);
            $this->assertSame(0, $courrier->annotations()->count());
            $this->assertDatabaseMissing('audit_logs', ['action' => 'courrier.annotation_creee', 'auditable_id' => $courrier->id]);
            $this->assertDatabaseHas('audit_logs', ['action' => 'dossier.archivage_decide', 'auditable_id' => $dossier->id]);
        } finally {
            if ($verrouDetenu) {
                DB::select('SELECT pg_advisory_unlock(20261004, 430)');
            }
            foreach ([$processusA, $processusB] as $process) {
                if ($process instanceof Process && $process->isRunning()) {
                    $process->stop();
                }
            }
            if ($triggerInstalle) {
                DB::unprepared('DROP TRIGGER IF EXISTS ont_test_archive_barrier ON dossiers');
                DB::unprepared('DROP FUNCTION IF EXISTS ont_test_archive_barrier_func()');
            }
            foreach (glob($barriere.'/*') ?: [] as $fichier) {
                unlink($fichier);
            }
            rmdir($barriere);
        }
    }

    private function attendreAttenteAdvisory(string $barriere, string $operation, string $table): void
    {
        $fichierPid = $barriere.'/'.$operation.'-pid';
        $limite = microtime(true) + 15;
        while (! file_exists($fichierPid) && microtime(true) < $limite) {
            usleep(10_000);
        }
        $this->assertFileExists($fichierPid);
        $pid = (int) file_get_contents($fichierPid);

        while (microtime(true) < $limite) {
            if (DB::table('pg_stat_activity')->where('pid', $pid)->where('wait_event_type', 'Lock')
                ->where('wait_event', 'advisory')->where('query', 'ilike', '%'.$table.'%')->exists()) {
                return;
            }
            usleep(10_000);
        }

        $this->fail("Le processus {$operation} n’attend pas la barrière advisory sur {$table}.");
    }

    private function attendreVerrouTable(string $barriere, string $operation, string $table): void
    {
        $fichierPid = $barriere.'/'.$operation.'-pid';
        $limite = microtime(true) + 15;
        while (! file_exists($fichierPid) && microtime(true) < $limite) {
            usleep(10_000);
        }
        $this->assertFileExists($fichierPid);
        $pid = (int) file_get_contents($fichierPid);

        while (microtime(true) < $limite) {
            if (DB::table('pg_stat_activity')->where('pid', $pid)->where('wait_event_type', 'Lock')
                ->where('query', 'ilike', '%'.$table.'%')->exists()) {
                return;
            }
            usleep(10_000);
        }

        $this->fail("Le processus {$operation} n’attend pas un verrou de la table {$table}.");
    }

    /** @return array<string, string> */
    private function environnementWorkerPostgres(): array
    {
        $connexion = config('database.connections.pgsql');

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $connexion['host'],
            'DB_PORT' => (string) $connexion['port'],
            'DB_DATABASE' => 'ont_testing',
            'DB_USERNAME' => (string) $connexion['username'],
            'DB_PASSWORD' => (string) $connexion['password'],
            'DB_URL' => '',
        ];
    }

    private function classerEtArchiver(Courrier $courrier, User $dg, User $sec2, string $suffixe): ClassementDocument
    {
        app(DispatchCourrierService::class)->decider($courrier, $dg, [[
            'type' => 'classement',
            'instruction' => 'Classement avant décision d’archivage.',
        ]]);
        $dispatch = $courrier->dispatchs()->sole();
        $this->receptionnerDispatchSec2($dispatch, $sec2);
        $classement = app(ClassementDocumentService::class)->classer($dispatch, $sec2, [
            'cote' => $suffixe,
            'emplacement' => 'Archives',
        ]);
        app(ClassementDocumentService::class)->archiver($classement, $sec2, null);

        return $classement;
    }
}
