<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse de démarrer les tests avant toute migration si la connexion
     * résolue ne pointe pas explicitement vers la base de test isolée.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $environment = $app->environment();
        $database = (string) $app['config']->get('database.connections.pgsql.database');

        if ($environment !== 'testing' || $database !== 'ont_testing') {
            throw new RuntimeException(sprintf(
                'Tests interrompus avant migration : APP_ENV doit être "testing" et la base PostgreSQL doit être "ont_testing" (reçu APP_ENV=%s, DB_DATABASE=%s).',
                $environment,
                $database,
            ));
        }

        return $app;
    }
}
