<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Modules\Courrier\Contracts\NumeroGenerator;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Services\ReferenceDocumentaireService;
use Modules\Kernel\Models\User;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $mode, $identifiant, $acteurId, $barriere, $worker] = $argv;
file_put_contents($barriere.'/ready-'.$worker, 'ready');
$limite = microtime(true) + 20;
while (! file_exists($barriere.'/start') && microtime(true) < $limite) {
    usleep(10_000);
}

try {
    $valeur = $mode === 'numero'
        ? app(NumeroGenerator::class)->genererNumeroEnregistrement()
        : app(ReferenceDocumentaireService::class)->attribuer(
            Courrier::withoutGlobalScopes()->findOrFail((int) $identifiant),
            User::query()->findOrFail((int) $acteurId),
            2026,
        )->reference_documentaire;
    file_put_contents($barriere.'/result-'.$worker.'.json', json_encode(['status' => 'ok', 'value' => $valeur], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($barriere.'/result-'.$worker.'.json', json_encode(['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR));
}
