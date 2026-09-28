<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;
use Modules\Kernel\Models\User;
use Modules\Kernel\Services\DgInterimService;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $operation, $acteurId, $dgaId, $barriere, $numero] = $argv;
file_put_contents($barriere.'/ready-'.$numero, 'ready');
$limite = microtime(true) + 15;
while (! file_exists($barriere.'/start') && microtime(true) < $limite) {
    usleep(10_000);
}

try {
    $acteur = User::query()->findOrFail((int) $acteurId);
    $service = app(DgInterimService::class);
    if ($operation === 'ouvrir') {
        $service->ouvrir($acteur, User::query()->findOrFail((int) $dgaId), 'Ouverture concurrente de test');
    } else {
        $service->terminer($acteur);
    }
    $resultat = ['status' => 'ok'];
} catch (ValidationException $exception) {
    $resultat = ['status' => 'refused'];
} catch (Throwable $exception) {
    $resultat = ['status' => 'error', 'class' => $exception::class];
}
file_put_contents($barriere.'/result-'.$numero.'.json', json_encode($resultat, JSON_THROW_ON_ERROR));
