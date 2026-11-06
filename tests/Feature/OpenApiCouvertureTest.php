<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OpenApiCouvertureTest extends TestCase
{
    public function test_chaque_route_de_lapi_est_documentee(): void
    {
        $texte = file_get_contents(base_path('docs/openapi.yaml'));
        $paths = substr($texte, strpos($texte, "\npaths:\n"));
        $methodesParChemin = [];
        $courant = null;
        foreach (explode("\n", $paths) as $ligne) {
            if (preg_match('#^  (/\S*):\s*$#', $ligne, $m)) {
                $courant = $m[1];
            } elseif ($courant !== null && preg_match('/^    (get|post|put|patch|delete):/', $ligne, $m)) {
                $methodesParChemin[$courant][] = $m[1];
            }
        }
        $manquantes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $chemin = '/'.substr($route->uri(), strlen('api/v1/'));
            foreach (array_diff($route->methods(), ['HEAD']) as $methode) {
                if (! in_array(strtolower($methode), $methodesParChemin[$chemin] ?? [], true)) {
                    $manquantes[] = "{$methode} {$chemin}";
                }
            }
        }

        $this->assertSame([], $manquantes);
    }
}
