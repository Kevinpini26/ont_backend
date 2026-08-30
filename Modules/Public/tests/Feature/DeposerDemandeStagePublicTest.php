<?php

namespace Modules\Public\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Mail\AccuseReceptionCandidatMail;
use Modules\Courrier\Models\Courrier;
use Modules\Stagiaires\Models\DisponibiliteDemandesStage;
use Tests\TestCase;

class DeposerDemandeStagePublicTest extends TestCase
{
    use RefreshDatabase;

    private function payloadValide(array $overrides = []): array
    {
        return array_merge([
            'candidat_nom' => 'Jean Kabila',
            'candidat_email' => 'jean.kabila@example.com',
            'candidat_contact' => '+243 900 000 000',
            'candidat_etablissement' => 'Université de Kinshasa',
            'type_stage' => 'academique',
            'lettre_stage' => UploadedFile::fake()->create('lettre-stage.pdf', 100, 'application/pdf'),
        ], $overrides);
    }

    public function test_un_candidat_peut_deposer_une_demande_de_stage_sans_authentification(): void
    {
        Mail::fake();
        Storage::fake('local');

        $response = $this->post('/api/v1/public/demandes-stage', $this->payloadValide());

        $response->assertCreated();
        $numero = $response->json('numero_accuse_reception');
        $this->assertNotNull($numero);

        $this->assertDatabaseHas('courriers', [
            'numero_accuse_reception' => $numero,
            'type' => CourrierType::DEMANDE_STAGE->value,
            'candidat_nom' => 'Jean Kabila',
            'candidat_email' => 'jean.kabila@example.com',
            'created_by' => null,
            'mode_reception' => 'depot_en_ligne',
        ]);

        $courrier = Courrier::query()->where('numero_accuse_reception', $numero)->firstOrFail();
        $this->assertNotNull($courrier->lettre_stage_chemin);
        Storage::disk('local')->assertExists($courrier->lettre_stage_chemin);

        Mail::assertQueued(
            AccuseReceptionCandidatMail::class,
            fn ($mail) => $mail->hasTo('jean.kabila@example.com') && $mail->courrier->is($courrier),
        );
    }

    public function test_les_champs_obligatoires_sont_valides(): void
    {
        $this->post('/api/v1/public/demandes-stage', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['candidat_nom', 'candidat_email', 'candidat_etablissement', 'type_stage']);
    }

    public function test_lemail_doit_etre_une_adresse_valide(): void
    {
        Storage::fake('local');

        $this->post('/api/v1/public/demandes-stage', $this->payloadValide(['candidat_email' => 'pas-un-email']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['candidat_email']);
    }

    public function test_la_lettre_de_stage_doit_etre_un_pdf_ou_une_image(): void
    {
        Storage::fake('local');

        $this->post('/api/v1/public/demandes-stage', $this->payloadValide([
            'lettre_stage' => UploadedFile::fake()->create('lettre-stage.exe', 100, 'application/x-msdownload'),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lettre_stage']);
    }

    public function test_le_candidat_peut_ensuite_suivre_son_dossier_avec_le_numero_recu(): void
    {
        Mail::fake();
        Storage::fake('local');

        $numero = $this->post('/api/v1/public/demandes-stage', $this->payloadValide())
            ->assertCreated()
            ->json('numero_accuse_reception');

        $this->postJson('/api/v1/public/dossiers/verifier', ['numero' => $numero, 'nom' => 'Jean Kabila'])
            ->assertOk()
            ->assertJsonPath('data.statut_simplifie', "En cours d'examen");
    }

    public function test_un_depot_sur_un_type_de_stage_ferme_est_rejete(): void
    {
        Storage::fake('local');
        DisponibiliteDemandesStage::query()->create(['academique_ouvert' => false, 'professionnel_ouvert' => true]);

        $this->post('/api/v1/public/demandes-stage', $this->payloadValide())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type_stage']);
    }
}
