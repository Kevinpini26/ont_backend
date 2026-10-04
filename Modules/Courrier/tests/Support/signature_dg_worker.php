<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Exceptions\TransitionNonAutoriseeException;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Services\CourrierCircuitService;
use Modules\Kernel\Models\User;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $courrierId, $acteurId, $barriere, $numero] = $argv;
$action = $argv[5] ?? 'signer';
file_put_contents($barriere.'/ready-'.$numero, 'ready');
$limite = microtime(true) + 15;
while (! file_exists($barriere.'/start') && microtime(true) < $limite) {
    usleep(10_000);
}
try {
    $service = app(CourrierCircuitService::class);
    $courrier = Courrier::withoutGlobalScopes()->findOrFail((int) $courrierId);
    $acteur = User::query()->findOrFail((int) $acteurId);
    if ($action === 'valider-pour-signature') {
        $service->validerPourSignature($courrier, $acteur);
    } else {
        $service->signer($courrier, $acteur);
    }
    $resultat = ['status' => 'ok'];
} catch (ValidationException|TransitionNonAutoriseeException $exception) {
    $resultat = ['status' => 'refused'];
} catch (Throwable $exception) {
    $resultat = ['status' => 'error', 'class' => $exception::class];
}
file_put_contents($barriere.'/result-'.$numero.'.json', json_encode($resultat, JSON_THROW_ON_ERROR));
