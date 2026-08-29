<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentationController extends Controller
{
    /**
     * Sert la spécification OpenAPI. Hors production, la route est ouverte
     * (voir routes/api.php) pour faciliter l'intégration en développement ;
     * en production, elle passe par auth:sanctum + role:administrateur.
     */
    public function openapi(): BinaryFileResponse
    {
        $chemin = base_path('docs/openapi.yaml');

        abort_unless(is_file($chemin), Response::HTTP_NOT_FOUND);

        return response()->file($chemin, [
            'Content-Type' => 'application/yaml',
        ]);
    }
}
