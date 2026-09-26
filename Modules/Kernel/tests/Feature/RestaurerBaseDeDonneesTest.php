<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use Tests\TestCase;

/**
 * Contrairement à SauvegarderBaseDeDonneesTest (qui fake Process pour ne
 * tester que l'orchestration upload/rétention), ce test exécute réellement
 * pg_dump, gzip, gunzip et psql, sur deux bases PostgreSQL jetables créées
 * pour l'occasion — jamais la base de test de l'application elle-même. Une
 * sauvegarde qui n'a jamais été effectivement restaurée n'est qu'un espoir,
 * pas une garantie ; c'est précisément ce que ce test vérifie.
 */
class RestaurerBaseDeDonneesTest extends TestCase
{
    private const DB_SOURCE = 'ont_cycle_sauvegarde_test_source';

    private const DB_CIBLE = 'ont_cycle_sauvegarde_test_cible';

    private array $configOriginal;

    private bool $peutCreerBase = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configOriginal = config('database.connections.pgsql');
    }

    protected function tearDown(): void
    {
        if ($this->peutCreerBase) {
            $this->executerSurMaintenance('DROP DATABASE IF EXISTS '.self::DB_SOURCE);
            $this->executerSurMaintenance('DROP DATABASE IF EXISTS '.self::DB_CIBLE);
        }

        // La connexion "pgsql" par défaut a pu être redirigée vers une base
        // jetable pendant le test (voir pointerVers()) : la restaurer et
        // forcer une reconnexion, sinon les tests suivants du même
        // processus continueraient de cibler une base qui vient d'être
        // supprimée.
        config(['database.connections.pgsql.database' => $this->configOriginal['database']]);
        DB::purge('pgsql');

        parent::tearDown();
    }

    private function pdoMaintenance(): PDO
    {
        return new PDO(
            "pgsql:host={$this->configOriginal['host']};port={$this->configOriginal['port']};dbname=postgres",
            $this->configOriginal['username'],
            $this->configOriginal['password'],
        );
    }

    private function executerSurMaintenance(string $sql): void
    {
        $this->pdoMaintenance()->exec($sql);
    }

    private function pdo(string $base): PDO
    {
        return new PDO(
            "pgsql:host={$this->configOriginal['host']};port={$this->configOriginal['port']};dbname={$base}",
            $this->configOriginal['username'],
            $this->configOriginal['password'],
        );
    }

    /**
     * Les commandes de sauvegarde/restauration lisent la configuration de
     * connexion à l'instant de leur exécution (jamais une connexion déjà
     * ouverte) : rediriger "pgsql" vers la base jetable suffit à les cibler,
     * sans toucher au reste de l'application.
     */
    private function pointerVers(string $base): void
    {
        config(['database.connections.pgsql.database' => $base]);
    }

    private function exigerPrivilegeCreationBase(): void
    {
        $peutCreer = $this->pdoMaintenance()
            ->query('SELECT CASE WHEN rolcreatedb OR rolsuper THEN 1 ELSE 0 END FROM pg_roles WHERE rolname = current_user')
            ->fetchColumn();

        $this->peutCreerBase = (int) $peutCreer === 1;

        if (! $this->peutCreerBase) {
            $this->markTestSkipped(
                'Le cycle réel sauvegarde/restauration exige un rôle PostgreSQL avec CREATE DATABASE.',
            );
        }
    }

    public function test_un_cycle_sauvegarde_puis_restauration_reproduit_fidelement_les_donnees(): void
    {
        $this->exigerPrivilegeCreationBase();

        Storage::fake('backups-local');
        config(['backup.disk' => 'backups-local']);

        $this->executerSurMaintenance('DROP DATABASE IF EXISTS '.self::DB_SOURCE);
        $this->executerSurMaintenance('CREATE DATABASE '.self::DB_SOURCE);

        $this->pdo(self::DB_SOURCE)->exec(
            'CREATE TABLE marqueur (id serial primary key, valeur text not null)'
        );
        $this->pdo(self::DB_SOURCE)->exec(
            "INSERT INTO marqueur (valeur) VALUES ('cycle-de-sauvegarde-verifie')"
        );

        $this->pointerVers(self::DB_SOURCE);
        $this->artisan('kernel:sauvegarder-base-de-donnees')->assertSuccessful();

        $fichiers = Storage::disk('backups-local')->files('database');
        $this->assertCount(1, $fichiers);

        // Restauration vers une base cible différente et vide : le
        // scénario réel (nouveau serveur, ou base perdue après incident),
        // pas une simple relecture de la base source encore intacte.
        $this->executerSurMaintenance('DROP DATABASE IF EXISTS '.self::DB_CIBLE);
        $this->executerSurMaintenance('CREATE DATABASE '.self::DB_CIBLE);
        $this->pointerVers(self::DB_CIBLE);

        $this->artisan('kernel:restaurer-base-de-donnees', [
            'fichier' => $fichiers[0],
            '--force' => true,
        ])->assertSuccessful();

        $valeur = $this->pdo(self::DB_CIBLE)
            ->query('SELECT valeur FROM marqueur')
            ->fetchColumn();

        $this->assertSame('cycle-de-sauvegarde-verifie', $valeur);
    }

    public function test_la_restauration_sans_fichier_precise_prend_la_sauvegarde_la_plus_recente(): void
    {
        $this->exigerPrivilegeCreationBase();

        Storage::fake('backups-local');
        config(['backup.disk' => 'backups-local']);

        $this->executerSurMaintenance('DROP DATABASE IF EXISTS '.self::DB_SOURCE);
        $this->executerSurMaintenance('CREATE DATABASE '.self::DB_SOURCE);
        $this->pdo(self::DB_SOURCE)->exec('CREATE TABLE marqueur (id serial primary key, valeur text not null)');
        $this->pdo(self::DB_SOURCE)->exec("INSERT INTO marqueur (valeur) VALUES ('la-plus-recente')");

        // Une ancienne sauvegarde, déjà présente, ne doit pas être choisie.
        Storage::disk('backups-local')->put('database/ont-ancienne.sql.gz', 'contenu-non-pertinent');
        touch(Storage::disk('backups-local')->path('database/ont-ancienne.sql.gz'), now()->subDays(10)->timestamp);

        $this->pointerVers(self::DB_SOURCE);
        $this->artisan('kernel:sauvegarder-base-de-donnees')->assertSuccessful();

        $this->executerSurMaintenance('DROP DATABASE IF EXISTS '.self::DB_CIBLE);
        $this->executerSurMaintenance('CREATE DATABASE '.self::DB_CIBLE);
        $this->pointerVers(self::DB_CIBLE);

        $this->artisan('kernel:restaurer-base-de-donnees', ['--force' => true])->assertSuccessful();

        $valeur = $this->pdo(self::DB_CIBLE)->query('SELECT valeur FROM marqueur')->fetchColumn();
        $this->assertSame('la-plus-recente', $valeur);
    }

    public function test_la_restauration_est_refusee_sans_confirmation_ni_option_force(): void
    {
        Storage::fake('backups-local');
        config(['backup.disk' => 'backups-local']);
        Storage::disk('backups-local')->put('database/ont-quelconque.sql.gz', 'contenu');

        $this->artisan('kernel:restaurer-base-de-donnees', ['fichier' => 'database/ont-quelconque.sql.gz'])
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->assertFailed();
    }
}
