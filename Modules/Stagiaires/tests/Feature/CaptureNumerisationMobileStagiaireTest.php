<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Kernel\Models\JetonCaptureNumerisation;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Models\Stagiaire;

class CaptureNumerisationMobileStagiaireTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_la_dfp_genere_un_jeton_de_capture_pour_un_dossier_stagiaire(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create(['nom' => 'Jean Mukendi']);

        $reponse = $this->actingAs($dfp)->postJson("/api/v1/stagiaires/{$stagiaire->id}/jeton-capture")->assertOk();

        $jeton = JetonCaptureNumerisation::query()->where('token', $reponse->json('token'))->firstOrFail();
        $this->assertSame(Stagiaire::class, $jeton->capturable_type);
        $this->assertSame($stagiaire->id, $jeton->capturable_id);
    }

    public function test_afficher_les_informations_du_jeton_pour_un_stagiaire(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create(['nom' => 'Alice Kanku']);
        $jeton = JetonCaptureNumerisation::genererPour($stagiaire, $dfp);

        $this->getJson("/api/v1/public/capture/{$jeton->token}")
            ->assertOk()
            ->assertJsonPath('cible_type', 'stagiaire')
            ->assertJsonPath('cible_libelle', 'Alice Kanku')
            ->assertJsonPath('plafond_ko', config('kernel.numerisation.plafond_ko_stagiaire'));
    }

    public function test_soumettre_un_scan_pour_un_stagiaire_cree_la_version_1(): void
    {
        Storage::fake('local');
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create();
        $jeton = JetonCaptureNumerisation::genererPour($stagiaire, $dfp);

        $this->postJson("/api/v1/public/capture/{$jeton->token}", [
            'fichier' => UploadedFile::fake()->create('piece.pdf', 300, 'application/pdf'),
        ])->assertCreated();

        $this->assertCount(1, $stagiaire->fresh()->numerisations);
    }
}
