<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Kernel\Enums\SourceDocumentNumerise;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Jobs\ExtraireTexteDocumentNumeriseJob;
use Modules\Kernel\Models\User;

/**
 * Recherche plein texte étendue au contenu des documents numérisés — voir
 * docs/numerisation-courrier.md (Lot 4). QUEUE_CONNECTION=sync en test
 * (voir phpunit.xml) : l'extraction se fait donc immédiatement, sans
 * Queue::fake().
 */
class RecherchePleinTexteContenuNumeriseTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_un_terme_absent_de_lobjet_mais_present_dans_le_scan_est_trouve(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => UserRole::ADMINISTRATEUR]);
        $courrier = Courrier::factory()->create(['objet' => 'Correspondance ordinaire']);

        $pdf = app(PdfGenerationService::class)->genererDepuisVue('kernel::test-texte-simple', [
            'phrase' => 'Ce document mentionne le projet ANTILOPE2026 en détail.',
        ]);
        $chemin = 'numerisations/scan-test.pdf';
        Storage::disk('local')->put($chemin, $pdf);

        // Création directe (pas via GestionnaireDocumentNumerise) : ce test
        // porte sur le branchement de la recherche, pas sur le contrôle
        // qualité (poids/page), déjà couvert par GestionnaireDocumentNumeriseTest.
        $document = $courrier->numerisations()->create([
            'version' => 1,
            'chemin' => $chemin,
            'poids_octets' => Storage::disk('local')->size($chemin),
            'source' => SourceDocumentNumerise::TELEPHONE,
            'sha256' => hash('sha256', Storage::disk('local')->get($chemin)),
        ]);
        ExtraireTexteDocumentNumeriseJob::dispatchSync($document->id);

        $reponse = $this->actingAs($admin)->getJson('/api/v1/courriers?recherche=ANTILOPE2026')->assertOk();

        $reponse->assertJsonCount(1, 'data');
        $reponse->assertJsonPath('data.0.id', $courrier->id);
        $reponse->assertJsonPath('data.0.trouve_dans_contenu_numerise', true);
    }

    public function test_un_match_sur_lobjet_seul_nest_pas_marque_comme_venant_du_scan(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMINISTRATEUR]);
        Courrier::factory()->create(['objet' => 'Demande de financement particulière']);

        $reponse = $this->actingAs($admin)->getJson('/api/v1/courriers?recherche=financement')->assertOk();

        $reponse->assertJsonPath('data.0.trouve_dans_contenu_numerise', false);
    }
}
