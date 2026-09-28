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
file_put_contents($barriere.'/ready-'.$worker, 'ready');
$limite = microtime(true) + 15;
while (! file_exists($barriere.'/start') && microtime(true) < $limite) {
    usleep(10_000);
}
try {
    $courrier = Courrier::withoutGlobalScopes()->findOrFail((int) $courrierId);
    $acteur = User::query()->findOrFail((int) $acteurId);
    app(DispatchCourrierService::class)->executerDepuisImputations($courrier, $acteur);
    $resultat = ['status' => 'ok'];
} catch (ValidationException $exception) {
    $resultat = ['status' => 'refused', 'errors' => $exception->errors()];
} catch (Throwable $exception) {
    $resultat = ['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
}
file_put_contents($barriere.'/result-'.$worker.'.json', json_encode($resultat, JSON_THROW_ON_ERROR));
