<?php

namespace Modules\Public\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Models\Courrier;
use Tests\TestCase;

/**
 * Le type de stage choisi au dépôt (académique/professionnel) détermine
 * les pièces exigées — jamais les deux jeux à la fois. Voir
 * DeposerDemandeStageRequest et DemandeStagePublicController.
 */
class TypeStageDemandePubliqueTest extends TestCase
{
    use RefreshDatabase;

    private function payloadBase(array $overrides = []): array
    {
        return array_merge([
            'candidat_nom' => 'Jean Kabila',
            'candidat_email' => 'jean.kabila@example.com',
            'candidat_contact' => '+243 900 000 000',
            'candidat_etablissement' => 'Université de Kinshasa',
        ], $overrides);
    }

    public function test_le_depot_academique_nexige_que_la_lettre_de_stage(): void
    {
        Mail::fake();
        Storage::fake('local');

        $response = $this->post('/api/v1/public/demandes-stage', $this->payloadBase([
            'type_stage' => 'academique',
            'lettre_stage' => UploadedFile::fake()->create('lettre-stage.pdf', 100, 'application/pdf'),
        ]));

        $response->assertCreated();

        $courrier = Courrier::query()->where('numero_enregistrement', $response->json('numero_accuse_reception'))->firstOrFail();
        $this->assertSame('academique', $courrier->type_stage);
        $this->assertNotNull($courrier->lettre_stage_chemin);
        $this->assertNull($courrier->cv_chemin);
    }

    public function test_le_depot_academique_sans_lettre_de_stage_est_rejete(): void
    {
        $this->post('/api/v1/public/demandes-stage', $this->payloadBase(['type_stage' => 'academique']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lettre_stage']);
    }

    private function piecesProfessionnelles(array $overrides = []): array
    {
        return array_merge([
            'lettre_demande' => UploadedFile::fake()->create('lettre-demande.pdf', 100, 'application/pdf'),
            'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
            'diplome_etat' => UploadedFile::fake()->create('diplome-etat.pdf', 100, 'application/pdf'),
            'dernier_diplome' => UploadedFile::fake()->create('dernier-diplome.pdf', 100, 'application/pdf'),
        ], $overrides);
    }

    public function test_le_depot_professionnel_exige_les_quatre_pieces(): void
    {
        Mail::fake();
        Storage::fake('local');

        $response = $this->post('/api/v1/public/demandes-stage', $this->payloadBase([
            'type_stage' => 'professionnel',
            ...$this->piecesProfessionnelles(),
        ]));

        $response->assertCreated();

        $courrier = Courrier::query()->where('numero_enregistrement', $response->json('numero_accuse_reception'))->firstOrFail();
        $this->assertSame('professionnel', $courrier->type_stage);
        $this->assertNull($courrier->lettre_stage_chemin);
        $this->assertNotNull($courrier->lettre_demande_chemin);
        $this->assertNotNull($courrier->cv_chemin);
        $this->assertNotNull($courrier->diplome_etat_chemin);
        $this->assertNotNull($courrier->dernier_diplome_chemin);
    }

    public function test_le_depot_professionnel_avec_une_piece_manquante_est_rejete(): void
    {
        $pieces = $this->piecesProfessionnelles();
        unset($pieces['dernier_diplome']);

        $this->post('/api/v1/public/demandes-stage', $this->payloadBase([
            'type_stage' => 'professionnel',
            ...$pieces,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dernier_diplome']);
    }

    public function test_le_depot_professionnel_sans_lettre_de_demande_est_rejete(): void
    {
        $pieces = $this->piecesProfessionnelles();
        unset($pieces['lettre_demande']);

        $this->post('/api/v1/public/demandes-stage', $this->payloadBase([
            'type_stage' => 'professionnel',
            ...$pieces,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lettre_demande']);
    }

    public function test_type_stage_invalide_est_rejete(): void
    {
        $this->post('/api/v1/public/demandes-stage', $this->payloadBase(['type_stage' => 'inconnu']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type_stage']);
    }
}
