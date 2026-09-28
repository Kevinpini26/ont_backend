<?php

namespace Modules\Public\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Mail\AccuseReceptionCourrierExterneMail;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\NotificationService;
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
        $this->assertNull($courrier->numero_enregistrement);
        $this->assertSame('recu', $courrier->statut->value);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'courrier.depot_public',
            'auditable_id' => $courrier->id,
            'user_id' => null,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'courrier.numero_enregistrement_attribue',
            'auditable_id' => $courrier->id,
        ]);
        $this->assertNotNull($courrier->piece_jointe_chemin);
        Storage::disk('local')->assertExists($courrier->piece_jointe_chemin);

        Mail::assertQueued(
            AccuseReceptionCourrierExterneMail::class,
            fn ($mail) => $mail->hasTo('contact@agence-exemple.cd')
                && $mail->courrier->is($courrier)
                && str_contains($mail->render(), 'Proposition de partenariat')
                && str_contains($mail->render(), $courrier->numero_accuse_reception)
                && ! str_contains($mail->render(), '2026-0001'),
        );
        Mail::assertQueued(AccuseReceptionCourrierExterneMail::class, 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'courrier.accuse_reception_envoye',
            'auditable_id' => $courrier->id,
            'user_id' => null,
        ]);
    }

    public function test_un_echec_de_mise_en_file_ne_supprime_pas_le_depot_public(): void
    {
        Storage::fake('local');
        $this->mock(NotificationService::class)
            ->shouldReceive('envoyerMail')
            ->once()
            ->andThrow(new \RuntimeException('SMTP indisponible'));

        $numero = $this->post('/api/v1/public/courriers-externes', $this->payloadValide())
            ->assertCreated()
            ->json('numero_accuse_reception');

        $courrier = Courrier::withoutGlobalScopes()->where('numero_accuse_reception', $numero)->firstOrFail();
        $this->assertNull($courrier->numero_enregistrement);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'courrier.accuse_reception_echec',
            'auditable_id' => $courrier->id,
        ]);
        Storage::disk('local')->assertExists($courrier->piece_jointe_chemin);
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

    public function test_public_deposit_requires_reception_registration(): void
    {
        Mail::fake();
        Storage::fake('local');

        $numero = $this->post('/api/v1/public/courriers-externes', $this->payloadValide())
            ->assertCreated()
            ->json('numero_accuse_reception');

        $direction = Direction::factory()->create();
        $reception = User::factory()->agentCircuitCourrier(Poste::RECEPTION, $direction)->create();
        $secretariat1 = User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_1, $direction)->create();

        $courrier = Courrier::withoutGlobalScopes()->where('numero_accuse_reception', $numero)->firstOrFail();
        $chemin = $courrier->piece_jointe_chemin;
        $empreinte = hash_file('sha256', Storage::disk('local')->path($chemin));

        $this->assertNull($courrier->numero_enregistrement);
        $this->actingAs($reception)->getJson("/api/v1/courriers/{$courrier->id}")->assertOk();
        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$courrier->id}/enregistrer", [
            'classification' => 'externe',
            'accuse_reception_partenaire' => 'Dépôt public reçu',
        ])->assertForbidden();
        $this->actingAs($reception)->postJson("/api/v1/courriers/{$courrier->id}/transmettre-sec1")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('courrier');

        $premiereReponse = $this->actingAs($reception)->postJson("/api/v1/courriers/{$courrier->id}/enregistrer", [
            'classification' => 'externe',
            'accuse_reception_partenaire' => 'Dépôt public reçu',
        ])->assertOk();
        $numeroEnregistrement = $premiereReponse->json('data.numero_enregistrement');
        $this->assertNotNull($numeroEnregistrement);
        $this->assertSame($numero, $courrier->fresh()->numero_accuse_reception);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'courrier.numero_enregistrement_attribue',
            'auditable_id' => $courrier->id,
            'user_id' => $reception->id,
        ]);

        $this->actingAs($reception)->postJson("/api/v1/courriers/{$courrier->id}/enregistrer", [
            'classification' => 'externe',
            'accuse_reception_partenaire' => 'Dépôt public reçu',
        ])->assertUnprocessable();
        $this->assertSame($numeroEnregistrement, $courrier->fresh()->numero_enregistrement);

        $this->actingAs($reception)->postJson("/api/v1/courriers/{$courrier->id}/transmettre-sec1", [
            'instruction' => 'À traiter par SEC1.',
        ])->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'courrier.transmis_sec1',
            'auditable_id' => $courrier->id,
            'user_id' => $reception->id,
        ]);

        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")->assertOk();

        $this->actingAs($secretariat1)
            ->postJson("/api/v1/courriers/{$courrier->id}/transmettre-tri")
            ->assertOk()
            ->assertJsonPath('data.statut', 'en_attente_tri');

        Storage::disk('local')->assertExists($chemin);
        $this->assertSame($empreinte, hash_file('sha256', Storage::disk('local')->path($chemin)));
    }

    public function test_les_numeros_suivent_lordre_denregistrement_et_non_lordre_depot(): void
    {
        Mail::fake();
        Storage::fake('local');
        $direction = Direction::factory()->create();
        $reception = User::factory()->agentCircuitCourrier(Poste::RECEPTION, $direction)->create();

        $accuseA = $this->post('/api/v1/public/courriers-externes', $this->payloadValide(['objet' => 'Dépôt A']))->assertCreated()->json('numero_accuse_reception');
        $accuseB = $this->post('/api/v1/public/courriers-externes', $this->payloadValide(['objet' => 'Dépôt B']))->assertCreated()->json('numero_accuse_reception');
        $a = Courrier::withoutGlobalScopes()->where('numero_accuse_reception', $accuseA)->firstOrFail();
        $b = Courrier::withoutGlobalScopes()->where('numero_accuse_reception', $accuseB)->firstOrFail();
        $this->assertNull($a->numero_enregistrement);
        $this->assertNull($b->numero_enregistrement);

        $payload = ['classification' => 'externe', 'accuse_reception_partenaire' => 'Reçu'];
        $numeroB = $this->actingAs($reception)->postJson("/api/v1/courriers/{$b->id}/enregistrer", $payload)->assertOk()->json('data.numero_enregistrement');
        $numeroA = $this->actingAs($reception)->postJson("/api/v1/courriers/{$a->id}/enregistrer", $payload)->assertOk()->json('data.numero_enregistrement');

        $this->assertLessThan((int) substr($numeroA, -4), (int) substr($numeroB, -4));
        $this->assertNotSame($numeroA, $numeroB);
    }

    public function test_seule_la_reception_peut_enregistrer_un_depot_public(): void
    {
        Mail::fake();
        Storage::fake('local');
        $numero = $this->post('/api/v1/public/courriers-externes', $this->payloadValide())->assertCreated()->json('numero_accuse_reception');
        $courrier = Courrier::withoutGlobalScopes()->where('numero_accuse_reception', $numero)->firstOrFail();
        $direction = Direction::factory()->create();
        $acteursInterdits = [
            User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_1, $direction)->create(),
            User::factory()->agentCircuitCourrier(Poste::DG, $direction)->create(),
            User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_2, $direction)->create(),
            User::factory()->agentCircuitCourrier(Poste::ASSISTANT_1, $direction)->create(),
        ];
        $payload = ['classification' => 'externe', 'accuse_reception_partenaire' => 'Reçu'];

        foreach ($acteursInterdits as $acteur) {
            $reponse = $this->actingAs($acteur)->postJson("/api/v1/courriers/{$courrier->id}/enregistrer", $payload);
            in_array($acteur->poste, [Poste::DG, Poste::SECRETARIAT_1], true)
                ? $reponse->assertForbidden()
                : $reponse->assertNotFound();
        }
        $this->actingAs(User::factory()->directeurDirection($direction)->create())
            ->postJson("/api/v1/courriers/{$courrier->id}/enregistrer", $payload)
            ->assertNotFound();

        $this->assertNull($courrier->fresh()->numero_enregistrement);
        $reception = User::factory()->agentCircuitCourrier(Poste::RECEPTION, $direction)->create();
        $this->actingAs($reception)->postJson("/api/v1/courriers/{$courrier->id}/enregistrer", $payload)->assertOk();
        $this->assertNotNull($courrier->fresh()->numero_enregistrement);
    }
}
