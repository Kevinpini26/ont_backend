<?php

namespace Modules\Public\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Mail\AccuseReceptionCourrierExterneMail;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class DeposerCourrierExternePublicTest extends TestCase
{
    use RefreshDatabase;

    private function payloadValide(array $overrides = []): array
    {
        return array_merge([
            'expediteur_externe_nom' => 'Agence Voyage Congo SARL',
            'expediteur_externe_email' => 'contact@agence-exemple.cd',
            'expediteur_externe_telephone' => '+243 900 000 000',
            'objet' => 'Proposition de partenariat',
            'piece_jointe' => UploadedFile::fake()->create('courrier.pdf', 100, 'application/pdf'),
        ], $overrides);
    }

    public function test_un_partenaire_peut_deposer_un_courrier_externe_sans_authentification(): void
    {
        Mail::fake();
        Storage::fake('local');

        $response = $this->post('/api/v1/public/courriers-externes', $this->payloadValide());

        $response->assertCreated();
        $numero = $response->json('numero_accuse_reception');
        $this->assertNotNull($numero);

        $this->assertDatabaseHas('courriers', [
            'numero_accuse_reception' => $numero,
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Agence Voyage Congo SARL',
            'expediteur_externe_email' => 'contact@agence-exemple.cd',
            'created_by' => null,
            'necessite_avis_dg' => true,
            // Jamais laissé au choix du déposant : déterminé par le canal
            // d'entrée (voir CourrierCircuitService::creerCourrierExterneDepuisPublic).
            'mode_reception' => 'depot_en_ligne',
        ]);

        $courrier = Courrier::query()->where('numero_accuse_reception', $numero)->firstOrFail();
        $this->assertNotNull($courrier->piece_jointe_chemin);
        Storage::disk('local')->assertExists($courrier->piece_jointe_chemin);

        Mail::assertQueued(
            AccuseReceptionCourrierExterneMail::class,
            fn ($mail) => $mail->hasTo('contact@agence-exemple.cd') && $mail->courrier->is($courrier),
        );
    }

    public function test_les_champs_obligatoires_sont_valides(): void
    {
        $this->post('/api/v1/public/courriers-externes', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expediteur_externe_nom', 'expediteur_externe_email', 'objet', 'piece_jointe']);
    }

    public function test_la_piece_jointe_doit_etre_un_pdf_ou_une_image(): void
    {
        Storage::fake('local');

        $this->post('/api/v1/public/courriers-externes', $this->payloadValide([
            'piece_jointe' => UploadedFile::fake()->create('courrier.exe', 100, 'application/x-msdownload'),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['piece_jointe']);
    }

    public function test_le_courrier_externe_suit_le_circuit_complet_et_est_visible_par_le_secretariat_01(): void
    {
        Mail::fake();
        Storage::fake('local');

        $numero = $this->post('/api/v1/public/courriers-externes', $this->payloadValide())
            ->assertCreated()
            ->json('numero_accuse_reception');

        $direction = Direction::factory()->create();
        // Le Protocole n'est plus l'étape par défaut (Lot 1, bouclage) : un
        // dépôt public suit le circuit complet comme tout courrier entrant,
        // directement au tri du Secrétariat 01.
        $secretariat1 = User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_1, $direction)->create();

        $courrier = Courrier::withoutGlobalScopes()->where('numero_accuse_reception', $numero)->firstOrFail();

        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")->assertOk();

        $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$courrier->id}/transmettre-tri")
            ->assertOk()
            ->assertJsonPath('data.statut', 'en_attente_tri');
    }
}
