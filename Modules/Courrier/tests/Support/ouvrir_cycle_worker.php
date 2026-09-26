<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Services\DispatchCourrierService;
use Modules\Kernel\Models\User;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $courrierId, $acteurId, $barriere, $worker] = $argv;
$pret = $barriere.'/ready-'.$worker;
$depart = $barriere.'/start';
$resultat = $barriere.'/result-'.$worker.'.json';
file_put_contents($pret, 'ready');

$limite = microtime(true) + 15;
while (! file_exists($depart) && microtime(true) < $limite) {
    usleep(10_000);
}

try {
    $courrier = Courrier::withoutGlobalScopes()->findOrFail((int) $courrierId);
    $acteur = User::query()->findOrFail((int) $acteurId);
    app(DispatchCourrierService::class)->decider($courrier, $acteur, [[
        'type' => 'classement',
        'instruction' => 'Classement concurrent.',
    ]]);
    file_put_contents($resultat, json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR));
} catch (ValidationException $exception) {
    file_put_contents($resultat, json_encode(['status' => 'refused', 'errors' => $exception->errors()], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($resultat, json_encode(['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR));
}
