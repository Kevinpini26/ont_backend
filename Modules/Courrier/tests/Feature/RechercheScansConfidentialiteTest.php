<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\SourceDocumentNumerise;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * Lot 6, point 8 : le texte lu dans les scans (OCR) rend un courrier trouvable, mais jamais au-delà des droits :
 * un mot présent seulement dans le scan d'un courrier confidentiel ne le révèle ni à l'administrateur, ni à
 * une direction qui ne le voit pas.
 */
class RechercheScansConfidentialiteTest extends CourrierTestCase
{
    use RefreshDatabase;

    private function courrierAvecScan(string $niveau, string $texteScan, Direction $destination): Courrier
    {
        $courrier = Courrier::factory()->create([
            'objet' => 'Correspondance', 'niveau_confidentialite' => $niveau,
            'direction_destination_id' => $destination->id, 'statut' => CourrierStatut::DISPATCH_EXECUTE,
        ]);
        // Texte posé comme le ferait l'extraction OCR (contenu_texte alimente l'index plein texte).
        $courrier->numerisations()->create([
            'version' => 1, 'chemin' => "numerisations/scan-{$courrier->id}.pdf", 'poids_octets' => 1000,
            'source' => SourceDocumentNumerise::TELEPHONE, 'sha256' => hash('sha256', (string) $courrier->id),
            'contenu_texte' => $texteScan,
        ]);

        return $courrier;
    }

    public function test_un_mot_du_scan_dun_courrier_confidentiel_ne_revele_rien_hors_des_droits(): void
    {
        $dmc = Direction::factory()->create();
        $autre = Direction::factory()->create();
        $confidentiel = $this->courrierAvecScan('confidentiel', 'Projet secret PALMERAIE2026 de la direction.', $dmc);
        $this->courrierAvecScan('ordinaire', 'Note ordinaire BAOBAB2026.', $dmc);

        $admin = User::factory()->administrateur()->create();
        $this->actingAs($admin)->getJson('/api/v1/courriers?recherche=PALMERAIE2026')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($admin)->getJson('/api/v1/courriers?recherche=BAOBAB2026')->assertOk()->assertJsonCount(1, 'data');

        $directeurAutre = User::factory()->directeurDirection($autre)->create();
        $this->actingAs($directeurAutre)->getJson('/api/v1/courriers?recherche=PALMERAIE2026')->assertOk()->assertJsonCount(0, 'data');

        $dg = $this->agent(Poste::DG, $dmc);
        $this->actingAs($dg)->getJson('/api/v1/courriers?recherche=PALMERAIE2026')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $confidentiel->id)->assertJsonPath('data.0.trouve_dans_contenu_numerise', true);
    }
}
