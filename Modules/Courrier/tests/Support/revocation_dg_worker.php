<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Kernel\Models\DelegationPoste;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $delegationId, $acteurId, $barriere, $numero] = $argv;
file_put_contents($barriere.'/ready-'.$numero, 'ready');
$limite = microtime(true) + 15;
while (! file_exists($barriere.'/start') && microtime(true) < $limite) {
    usleep(10_000);
}
try {
    DB::transaction(function () use ($delegationId, $acteurId) {
        $delegation = DelegationPoste::query()->whereKey((int) $delegationId)->lockForUpdate()->firstOrFail();
        $delegation->update([
            'revoquee_at' => now(),
            'revoquee_par_id' => (int) $acteurId,
            'motif_revocation' => 'Révocation concurrente de test',
        ]);
    });
    $resultat = ['status' => 'ok'];
} catch (Throwable $exception) {
    $resultat = ['status' => 'error', 'class' => $exception::class];
}
file_put_contents($barriere.'/result-'.$numero.'.json', json_encode($resultat, JSON_THROW_ON_ERROR));
