<?php

namespace Modules\Kernel\Tests\Feature;

use Tests\TestCase;

class DocumentationOpenapiTest extends TestCase
{
    public function test_la_documentation_openapi_est_servie_avec_le_bon_type_de_contenu(): void
    {
        // Hors production (APP_ENV=testing ici), la route est ouverte — voir
        // Modules/Kernel/routes/api.php. Le branchement production (auth:sanctum
        // + role:administrateur) n'est pas exerçable par ce test puisque
        // APP_ENV est fixé pour tout le run de la suite ; il est garanti par
        // lecture du code plutôt que par un test d'intégration ici.
        $response = $this->get('/api/v1/docs/openapi.yaml');

        $response->assertOk();
        $this->assertSame('application/yaml', $response->headers->get('content-type'));

        // BinaryFileResponse (utilisée par response()->file()) ne restitue
        // pas son corps via getContent() en test — c'est une réponse en
        // flux. On compare directement le fichier servi au fichier source.
        $this->assertSame(
            file_get_contents(base_path('docs/openapi.yaml')),
            file_get_contents($response->getFile()->getPathname())
        );
    }

    public function test_la_documentation_openapi_contient_les_routes_des_quatre_modules(): void
    {
        $chemin = base_path('docs/openapi.yaml');

        $this->assertFileExists($chemin);

        $contenu = file_get_contents($chemin);

        $this->assertStringContainsString('openapi: 3.1.0', $contenu);
        $this->assertStringContainsString('/auth/login:', $contenu);
        $this->assertStringContainsString('/courriers:', $contenu);
        $this->assertStringContainsString('/stagiaires:', $contenu);
        $this->assertStringContainsString('/public/dossiers/verifier:', $contenu);
    }
}
