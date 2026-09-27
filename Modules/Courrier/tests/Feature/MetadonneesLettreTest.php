<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Courrier\Enums\CourrierType;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;

class MetadonneesLettreTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_la_reception_peut_saisir_les_metadonnees_de_la_lettre_a_lenregistrement(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $response = $this->actingAs($reception)->postJson('/api/v1/courriers', [
            'objet' => 'Correspondance ministérielle',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Ministère du Tourisme',
            'piece_jointe' => UploadedFile::fake()->create('lettre.pdf', 100, 'application/pdf'),
            'date_courrier' => '2026-08-20',
            'reference_expediteur' => 'MIN-TOUR/2026/0456',
            'qualite_expediteur' => 'Secrétaire Général du Ministère',
            'mode_reception' => 'porteur',
            'nombre_annexes' => 3,
        ])->assertCreated();

        $response->assertJsonPath('data.date_courrier', '2026-08-20')
            ->assertJsonPath('data.reference_expediteur', 'MIN-TOUR/2026/0456')
            ->assertJsonPath('data.qualite_expediteur', 'Secrétaire Général du Ministère')
            ->assertJsonPath('data.mode_reception', 'porteur')
            ->assertJsonPath('data.mode_reception_label', 'Porteur')
            ->assertJsonPath('data.nombre_annexes', 3);
    }

    public function test_les_metadonnees_complementaires_restent_facultatives(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $this->actingAs($reception)->postJson('/api/v1/courriers', [
            'objet' => 'Lettre sans métadonnées',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire externe',
            'mode_reception' => 'porteur',
            'numerisation_impossible' => true,
        ])->assertCreated()
            ->assertJsonPath('data.nombre_annexes', 0)
            ->assertJsonPath('data.mode_reception', 'porteur');
    }

    public function test_un_mode_de_reception_invalide_est_rejete(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $this->actingAs($reception)->postJson('/api/v1/courriers', [
            'objet' => 'Correspondance',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire',
            'piece_jointe' => UploadedFile::fake()->create('lettre.pdf', 100, 'application/pdf'),
            'mode_reception' => 'fax',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('mode_reception');
    }
}
