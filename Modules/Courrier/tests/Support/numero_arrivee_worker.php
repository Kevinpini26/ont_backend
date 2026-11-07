<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Modules\Courrier\Contracts\SequenceGenerator;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

[$script, $type, $annee, $nombre, $barriere, $worker, $resultat] = $argv;
file_put_contents("{$barriere}/ready-{$worker}", 'ready');

$limite = microtime(true) + 20;
while (! file_exists("{$barriere}/start") && microtime(true) < $limite) {
    usleep(5_000);
}

$numeros = [];
for ($i = 0; $i < (int) $nombre; $i++) {
    $numeros[] = $app->make(SequenceGenerator::class)->suivant($type, (int) $annee);
}
file_put_contents($resultat, json_encode($numeros));
