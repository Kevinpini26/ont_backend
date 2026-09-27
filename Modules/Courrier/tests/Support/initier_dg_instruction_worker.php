<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Services\CourrierCircuitService;
use Modules\Kernel\Models\User;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $instructionId, $acteurId, $directionId, $relecteurId, $barriere, $worker] = $argv;
file_put_contents($barriere.'/ready-'.$worker, 'ready');

$limite = microtime(true) + 15;
while (! file_exists($barriere.'/start') && microtime(true) < $limite) {
    usleep(10_000);
}

try {
    $acteur = User::query()->findOrFail((int) $acteurId);
    $courrier = app(CourrierCircuitService::class)->initierParDg($acteur, [
        'instruction_courrier_dg_id' => (int) $instructionId,
        'direction_destination_id' => (int) $directionId,
        'objet' => 'Note concurrente DG',
        'projet_reponse_contenu' => ['type' => 'doc', 'content' => []],
        'relecteur_id' => (int) $relecteurId,
    ]);
    file_put_contents($barriere.'/result-'.$worker.'.json', json_encode(['status' => 'ok', 'courrier_id' => $courrier->id], JSON_THROW_ON_ERROR));
} catch (ValidationException $exception) {
    file_put_contents($barriere.'/result-'.$worker.'.json', json_encode(['status' => 'refused', 'errors' => $exception->errors()], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($barriere.'/result-'.$worker.'.json', json_encode(['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR));
}
