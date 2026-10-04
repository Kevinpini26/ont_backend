<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\Dossier;
use Modules\Courrier\Services\ArchivageDossierService;
use Modules\Courrier\Services\CourrierCircuitService;
use Modules\Kernel\Models\User;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $operation, $courrierId, $dossierId, $acteurId, $barriere] = $argv;
$sortie = $barriere.'/'.$operation.'.json';

try {
    $acteur = User::query()->findOrFail((int) $acteurId);
    $backendPid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    file_put_contents($barriere.'/'.$operation.'-pid', (string) $backendPid);

    if ($operation === 'annotation') {
        $courrier = Courrier::withoutGlobalScopes()->findOrFail((int) $courrierId);
        $annotation = app(CourrierCircuitService::class)->ajouterAnnotation(
            $courrier,
            $acteur,
            'Annotation concurrente.',
        );

        file_put_contents($sortie, json_encode([
            'status' => 'committed',
            'annotation_id' => $annotation->id,
            'statut_archivage' => $courrier->dossier()->value('statut_archivage'),
        ], JSON_THROW_ON_ERROR));
        exit(0);
    }

    if ($operation === 'archivage') {
        $dossier = Dossier::query()->findOrFail((int) $dossierId);
        $dossier = app(ArchivageDossierService::class)->decider($dossier, $acteur);

        file_put_contents($sortie, json_encode([
            'status' => 'committed',
            'statut_archivage' => $dossier->fresh()->statut_archivage,
        ], JSON_THROW_ON_ERROR));
        exit(0);
    }

    throw new InvalidArgumentException('Opération de worker inconnue.');
} catch (ValidationException $exception) {
    file_put_contents($sortie, json_encode([
        'status' => 'refused',
        'errors' => $exception->errors(),
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($sortie, json_encode([
        'status' => 'error',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
