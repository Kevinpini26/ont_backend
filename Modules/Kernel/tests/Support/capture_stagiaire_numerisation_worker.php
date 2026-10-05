<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

[$script, $token, $barriere, $worker, $resultat] = $argv;
$contenuPdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n".str_repeat("% filler\n", 40_000);
$upload = UploadedFile::fake()->create("capture-{$worker}.pdf", 300, 'application/pdf');
$fichierPdf = $upload->getPathname();
file_put_contents($fichierPdf, $contenuPdf);
file_put_contents("{$barriere}/ready-{$worker}", 'ready');

$limite = microtime(true) + 20;
while (! file_exists("{$barriere}/start") && microtime(true) < $limite) {
    usleep(10_000);
}

try {
    $request = Request::create(
        "/api/v1/public/capture/{$token}",
        'POST',
        [],
        [],
        ['fichier' => $upload],
        ['HTTP_ACCEPT' => 'application/json'],
    );
    $kernel = $app->make(HttpKernel::class);
    $response = $kernel->handle($request);
    file_put_contents($resultat, json_encode([
        'http' => $response->getStatusCode(),
        'body' => json_decode($response->getContent(), true),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $kernel->terminate($request, $response);
} catch (Throwable $exception) {
    file_put_contents($resultat, json_encode([
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    throw $exception;
} finally {
    unlink($fichierPdf);
}