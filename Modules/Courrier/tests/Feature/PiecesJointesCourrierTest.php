<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;

class PiecesJointesCourrierTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_un_courrier_avec_plusieurs_annexes_les_enregistre_toutes(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $response = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Correspondance avec annexes',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire',
            'piece_jointe' => UploadedFile::fake()->create('lettre.pdf', 100, 'application/pdf'),
            'pieces_jointes' => [
                UploadedFile::fake()->create('annexe1.pdf', 50, 'application/pdf'),
                UploadedFile::fake()->create('annexe2.jpg', 80, 'image/jpeg'),
            ],
        ])->assertCreated();

        $numero = $response->json('data.numero_accuse_reception');
        $courrier = Courrier::query()->where('numero_accuse_reception', $numero)->firstOrFail();

        $this->assertCount(3, $courrier->piecesJointes);
        $this->assertSame(['Pièce jointe', 'Annexe 1', 'Annexe 2'], $courrier->piecesJointes->pluck('libelle')->all());
        foreach ($courrier->piecesJointes as $piece) {
            Storage::disk('local')->assertExists($piece->chemin);
        }
    }

    public function test_les_annexes_supplementaires_sont_facultatives(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $response = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Correspondance simple',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire',
            'piece_jointe' => UploadedFile::fake()->create('lettre.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $numero = $response->json('data.numero_accuse_reception');
        $courrier = Courrier::query()->where('numero_accuse_reception', $numero)->firstOrFail();

        $this->assertCount(1, $courrier->piecesJointes);
    }

    public function test_telecharger_une_piece_precise_par_id(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $response = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Correspondance',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire',
            'piece_jointe' => UploadedFile::fake()->create('lettre.pdf', 100, 'application/pdf'),
            'pieces_jointes' => [UploadedFile::fake()->create('annexe1.pdf', 50, 'application/pdf')],
        ])->assertCreated();

        $numero = $response->json('data.numero_accuse_reception');
        $courrier = Courrier::query()->where('numero_accuse_reception', $numero)->firstOrFail();
        $annexe = $courrier->piecesJointes->firstWhere('libelle', 'Annexe 1');

        $this->actingAs($reception)
            ->get("/api/v1/courriers/{$courrier->id}/pieces-jointes/{$annexe->id}")
            ->assertOk();
    }

    public function test_telecharger_une_piece_dun_autre_courrier_est_refuse(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $courrierA = Courrier::factory()->create();
        $courrierB = Courrier::factory()->create();
        $piece = $courrierA->piecesJointes()->create([
            'libelle' => 'Pièce jointe',
            'chemin' => 'courriers/fake.pdf',
        ]);

        $this->actingAs($reception)
            ->get("/api/v1/courriers/{$courrierB->id}/pieces-jointes/{$piece->id}")
            ->assertNotFound();
    }
}
