<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
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
            $this->supprimerBaseJetable(self::DB_SOURCE);
            $this->supprimerBaseJetable(self::DB_CIBLE);
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

    private function exigerBaseJetable(string $base): void
    {
        if (! in_array($base, [self::DB_SOURCE, self::DB_CIBLE], true)) {
            throw new \LogicException("« {$base} » n'est pas une base jetable de ce test : opération refusée.");
        }
    }

    private function recreerBaseJetable(string $base): void
    {
        $this->supprimerBaseJetable($base);
        $this->executerSurMaintenance('CREATE DATABASE '.$base);
    }

    private function supprimerBaseJetable(string $base): void
    {
        $this->exigerBaseJetable($base);
        $this->executerSurMaintenance('DROP DATABASE IF EXISTS '.$base);
    }

    private function pdo(string $base): PDO
    {
        $this->exigerBaseJetable($base);

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
        $this->exigerBaseJetable($base);
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

        $this->recreerBaseJetable(self::DB_SOURCE);

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
        $this->recreerBaseJetable(self::DB_CIBLE);
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

        $this->recreerBaseJetable(self::DB_SOURCE);
        $this->pdo(self::DB_SOURCE)->exec('CREATE TABLE marqueur (id serial primary key, valeur text not null)');
        $this->pdo(self::DB_SOURCE)->exec("INSERT INTO marqueur (valeur) VALUES ('la-plus-recente')");

        // Une ancienne sauvegarde, déjà présente, ne doit pas être choisie.
        Storage::disk('backups-local')->put('database/ont-ancienne.sql.gz', 'contenu-non-pertinent');
        touch(Storage::disk('backups-local')->path('database/ont-ancienne.sql.gz'), now()->subDays(10)->timestamp);

        $this->pointerVers(self::DB_SOURCE);
        $this->artisan('kernel:sauvegarder-base-de-donnees')->assertSuccessful();

        $this->recreerBaseJetable(self::DB_CIBLE);
        $this->pointerVers(self::DB_CIBLE);

        $this->artisan('kernel:restaurer-base-de-donnees', ['--force' => true])->assertSuccessful();

        $valeur = $this->pdo(self::DB_CIBLE)->query('SELECT valeur FROM marqueur')->fetchColumn();
        $this->assertSame('la-plus-recente', $valeur);
    }

    /**
     * Ce test crée, écrase et supprime des bases : aucune autre base que ses deux bases jetables
     * ne doit pouvoir être visée, même par erreur, quel que soit l'état du compte PostgreSQL.
     */
    public function test_aucune_base_hors_des_deux_bases_jetables_ne_peut_etre_visee(): void
    {
        foreach (['ont', 'ont_testing', 'ont_repetition_finale', 'ont_verif_restauration_hebdo', 'systeme_ont', 'fpt_ont', 'gestage_ont', 'postgres', ''] as $base) {
            foreach ([
                fn () => $this->pointerVers($base),
                fn () => $this->recreerBaseJetable($base),
                fn () => $this->supprimerBaseJetable($base),
                fn () => $this->pdo($base),
            ] as $action) {
                try {
                    $action();
                    $this->fail("La base « {$base} » n'aurait jamais dû pouvoir être visée.");
                } catch (\LogicException $e) {
                    $this->assertStringContainsString('base jetable', $e->getMessage());
                }
            }
        }

        $this->assertSame($this->configOriginal['database'], config('database.connections.pgsql.database'));
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

    /**
     * Sans ON_ERROR_STOP, psql continue après une erreur SQL et sort avec 0 ; dans un pipeline
     * « gunzip | psql » sans pipefail, une archive corrompue passe aussi inaperçue.
     */
    public function test_la_restauration_arrete_a_la_premiere_erreur_sql_et_controle_larchive(): void
    {
        Storage::fake('backups-local');
        config(['backup.disk' => 'backups-local']);
        Storage::disk('backups-local')->put('database/ont-2026-11-05_030000.sql.gz', gzencode('select 1;'));

        Process::fake(function ($process) {
            $commande = (array) $process->command;
            if (($commande[0] ?? null) === 'gzip' && in_array('-df', $commande, true)) {
                file_put_contents(substr(end($commande), 0, -3), 'select 1;');
            }

            return Process::result(exitCode: ($commande[0] ?? null) === 'psql' ? 3 : 0);
        });

        $this->artisan('kernel:restaurer-base-de-donnees', ['--force' => true])->assertFailed();

        Process::assertRan(fn ($p) => ($p->command[0] ?? null) === 'psql' && in_array('ON_ERROR_STOP=1', $p->command, true));
        Process::assertDidntRun(fn ($p) => ($p->command[0] ?? null) === 'sh');
    }
}
