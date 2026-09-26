<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireOrigine;
use Modules\Stagiaires\Models\Stagiaire;

class ImportHistoriqueTest extends StagiaireTestCase
{
    use RefreshDatabase;

    private function fichierCsv(string $contenu): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('historique.csv', $contenu);
    }

    private const ENTETE = "nom,contact,etablissement_origine,direction_code,statut,date_debut_stage,date_fin_stage,note_finale,reference_courrier\n";

    public function test_ladministrateur_peut_importer_un_csv_valide(): void
    {
        $admin = User::factory()->administrateur()->create();
        $direction = Direction::query()->where('code', 'DFP')->firstOrFail();

        $csv = self::ENTETE
            ."Jean Kabila,jean@example.com,Université de Kinshasa,DFP,cloture,2024-01-10,2024-03-10,15.5,REF-001\n"
            ."Marie Tshisekedi,,ISC,,,,,,\n";

        $reponse = $this->actingAs($admin)
            ->postJson('/api/v1/admin/stagiaires/import-historique', ['fichier' => $this->fichierCsv($csv)])
            ->assertOk();

        $reponse->assertJson(['importes' => 2, 'total_lignes' => 2, 'rejetes' => []]);

        $this->assertDatabaseCount('stagiaires', 2);

        $importe = Stagiaire::query()->where('nom', 'Jean Kabila')->firstOrFail();
        $this->assertSame(StagiaireOrigine::IMPORT_HISTORIQUE, $importe->origine);
        $this->assertSame($admin->id, $importe->importe_par_id);
        $this->assertNotNull($importe->importe_at);
        $this->assertNull($importe->courrier_id);
        $this->assertSame($direction->id, $importe->direction_id);
        $this->assertSame(15.5, $importe->note_finale);
    }

    public function test_un_fichier_aux_entetes_invalides_est_entierement_rejete(): void
    {
        $admin = User::factory()->administrateur()->create();

        $csv = "colonne_a,colonne_b\nx,y\n";

        $reponse = $this->actingAs($admin)
            ->postJson('/api/v1/admin/stagiaires/import-historique', ['fichier' => $this->fichierCsv($csv)])
            ->assertOk();

        $reponse->assertJson(['importes' => 0, 'total_lignes' => 0]);
        $this->assertCount(1, $reponse->json('rejetes'));
        $this->assertDatabaseCount('stagiaires', 0);
    }

    public function test_les_lignes_invalides_sont_rejetees_individuellement_sans_bloquer_les_autres(): void
    {
        $admin = User::factory()->administrateur()->create();

        $csv = self::ENTETE
            ."Jean Kabila,,Université de Kinshasa,,,,,,\n" // ligne valide
            .",,Établissement sans nom,,,,,,\n" // nom manquant
            ."Paul Mobutu,,ISC,CODE_INEXISTANT,,,,,\n" // direction inconnue
            ."Alice Lumumba,,ISC,,statut_invalide,,,,\n" // statut invalide
            ."Bob Kasavubu,,ISC,,,2024-13-45,,,\n"; // date invalide

        $reponse = $this->actingAs($admin)
            ->postJson('/api/v1/admin/stagiaires/import-historique', ['fichier' => $this->fichierCsv($csv)])
            ->assertOk();

        $reponse->assertJson(['importes' => 1, 'total_lignes' => 5]);
        $this->assertCount(4, $reponse->json('rejetes'));
        $this->assertDatabaseCount('stagiaires', 1);
    }

    public function test_un_utilisateur_non_administrateur_ne_peut_pas_importer(): void
    {
        $dfp = User::factory()->agentDfp()->create();

        $csv = self::ENTETE."Jean Kabila,,Université de Kinshasa,,,,,,\n";

        $this->actingAs($dfp)
            ->postJson('/api/v1/admin/stagiaires/import-historique', ['fichier' => $this->fichierCsv($csv)])
            ->assertForbidden();

        $this->assertDatabaseCount('stagiaires', 0);
    }
}
