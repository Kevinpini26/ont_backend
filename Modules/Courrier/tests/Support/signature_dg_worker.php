<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Exceptions\TransitionNonAutoriseeException;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Services\CourrierCircuitService;
use Modules\Courrier\Services\DispatchCourrierService;
use Modules\Kernel\Models\User;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment() !== 'testing' || $app['config']->get('database.connections.pgsql.database') !== 'ont_testing') {
    fwrite(STDERR, 'Concurrent test worker refuses any non-ont_testing database.');
    exit(2);
}

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
    } elseif ($action === 'finaliser-scan') {
        $scan = new UploadedFile($argv[6], 'scan.pdf', 'application/pdf', UPLOAD_ERR_OK, true);
        $service->finaliserSignaturePhysique($courrier, $acteur, $scan);
    } elseif ($action === 'envoyer-par-courriel') {
        $service->envoyerParCourriel($courrier, $acteur);
    } elseif ($action === 'confirmer-remise') {
        $service->confirmerRemisePhysique($courrier, $acteur, $argv[6] ?? 'Récupérant test', null, null);
    } elseif ($action === 'enregistrer-remise-legacy') {
        $service->enregistrerRemise($courrier, $acteur, [
            'remis_a' => $argv[6] ?? 'Récupérant legacy',
            'mode_remise' => $argv[7] ?? 'poste',
        ]);
    } elseif ($action === 'decider-classement') {
        app(DispatchCourrierService::class)->decider($courrier, $acteur, [[
            'type' => 'classement',
            'instruction' => 'Décision concurrente.',
        ]]);
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
