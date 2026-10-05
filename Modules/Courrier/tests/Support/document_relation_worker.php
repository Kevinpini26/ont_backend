<?php

declare(strict_types=1);

define('LARAVEL_START', microtime(true));

$script = $argv[0] ?? null;
if ($script === null || ! isset($argv[1], $argv[2], $argv[3], $argv[4], $argv[5])) {
    fwrite(STDERR, "Arguments manquants\n");
    exit(1);
}

[$_, $direction, $sourceId, $cibleId, $barriere, $numero] = $argv;

if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'ont_testing') {
    fwrite(STDERR, 'Le worker exige APP_ENV=testing et DB_DATABASE=ont_testing.');
    exit(2);
}

require dirname(__DIR__, 4).'/vendor/autoload.php';

$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if ($app->environment() !== 'testing'
    || $app['config']->get('database.default') !== 'pgsql'
    || $app['config']->get('database.connections.pgsql.database') !== 'ont_testing') {
    fwrite(STDERR, 'Configuration Laravel du worker hors environnement de test autorisé.');
    exit(2);
}

file_put_contents($barriere.'/ready-'.$numero, 'ready');

$limite = microtime(true) + 15;
while (! file_exists($barriere.'/start') && microtime(true) < $limite) {
    usleep(10_000);
}

if (! file_exists($barriere.'/start')) {
    file_put_contents($barriere.'/result-'.$numero.'.json', json_encode([
        'status' => 'timeout',
        'worker' => $numero,
        'reason' => 'Le signal start n’a pas été reçu avant expiration de la barrière.',
        'app_env' => $app->environment(),
        'database' => $app['config']->get('database.connections.pgsql.database'),
    ], JSON_THROW_ON_ERROR));
    exit(0);
}

try {
    $source = Modules\Courrier\Models\Courrier::withoutGlobalScopes()->findOrFail((int) $sourceId);
    $cible = Modules\Courrier\Models\Courrier::withoutGlobalScopes()->findOrFail((int) $cibleId);
    if ($source->dossier_id === null || $source->dossier_id !== $cible->dossier_id) {
        throw new RuntimeException('Les fixtures source et cible ne partagent pas le même dossier.');
    }
    $relation = app(Modules\Courrier\Services\DocumentRelationService::class)->relier($source, $cible, Modules\Courrier\Enums\DocumentRelationType::SUITE_DE, null);
    $resultat = [
        'status' => 'ok',
        'worker' => $numero,
        'source_id' => $source->id,
        'cible_id' => $cible->id,
        'dossier_id' => $source->dossier_id,
        'relation_id' => $relation->id,
        'app_env' => $app->environment(),
        'database' => $app['config']->get('database.connections.pgsql.database'),
    ];
} catch (Illuminate\Validation\ValidationException $e) {
    $resultat = [
        'status' => 'refused',
        'worker' => $numero,
        'source_id' => (int) $sourceId,
        'cible_id' => (int) $cibleId,
        'errors' => $e->errors(),
        'app_env' => $app->environment(),
        'database' => $app['config']->get('database.connections.pgsql.database'),
    ];
} catch (Throwable $e) {
    $resultat = [
        'status' => 'error',
        'worker' => $numero,
        'class' => $e::class,
        'message' => $e->getMessage(),
        'app_env' => $app->environment(),
        'database' => $app['config']->get('database.connections.pgsql.database'),
    ];
}

file_put_contents($barriere.'/result-'.$numero.'.json', json_encode($resultat, JSON_THROW_ON_ERROR));
