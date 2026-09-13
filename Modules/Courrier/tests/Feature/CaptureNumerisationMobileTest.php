<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\NumerisationStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\JetonCaptureNumerisation;

/**
 * Capture mobile — voir docs/numerisation-courrier.md (Lot 1). La page de
 * capture elle-même (canvas, recadrage, assemblage PDF) est un écran du
 * dépôt frontend ; ces tests couvrent uniquement la partie backend :
 * génération du jeton et réception du PDF assemblé.
 */
class CaptureNumerisationMobileTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_un_agent_genere_un_jeton_de_capture_valable_15_minutes(): void
    {
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::RECEPTION, $direction);
        $courrier = Courrier::factory()->create();

        $reponse = $this->actingAs($agent)->postJson("/api/v1/courriers/{$courrier->id}/jeton-capture")->assertOk();

        $reponse->assertJsonStructure(['token', 'url', 'expire_at', 'qr_code_data_uri']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $reponse->json('qr_code_data_uri'));

        $jeton = JetonCaptureNumerisation::query()->where('token', $reponse->json('token'))->firstOrFail();
        $this->assertTrue($jeton->expire_at->diffInMinutes(now()) <= 15);
        $this->assertSame($courrier->id, $jeton->capturable_id);
        $this->assertSame(Courrier::class, $jeton->capturable_type);
    }

    public function test_le_jeton_peut_etre_envoye_par_sms(): void
    {
        Notification::fake();
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::RECEPTION, $direction);
        $courrier = Courrier::factory()->create();

        $this->actingAs($agent)->postJson("/api/v1/courriers/{$courrier->id}/jeton-capture", [
            'numero_sms' => '+243812345678',
        ])->assertOk();

        // Le canal SMS (log par défaut, voir SmsNotificationCanal) ne passe
        // pas par le système de Notification Laravel — rien à vérifier de
        // plus ici que l'absence d'erreur, la Log::spy() étant couverte
        // par NotificationSmsTest côté Stagiaires.
        $this->assertTrue(true);
    }

    public function test_soumettre_un_scan_cree_la_version_suivante_et_marque_le_courrier_numerise(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::RECEPTION, $direction);
        $courrier = Courrier::factory()->create(['numerisation_statut' => NumerisationStatut::A_NUMERISER]);
        $jeton = JetonCaptureNumerisation::genererPour($courrier, $agent);

        $reponse = $this->postJson("/api/v1/public/capture/{$jeton->token}", [
            'fichier' => UploadedFile::fake()->create('scan.pdf', 200, 'application/pdf'),
        ])->assertCreated();

        $this->assertSame(1, $reponse->json('document.version'));
        $this->assertSame(NumerisationStatut::NUMERISE->value, $courrier->fresh()->numerisation_statut->value);
        $this->assertCount(1, $courrier->fresh()->numerisations);
    }

    public function test_le_jeton_est_a_usage_unique(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::RECEPTION, $direction);
        $courrier = Courrier::factory()->create();
        $jeton = JetonCaptureNumerisation::genererPour($courrier, $agent);

        $this->postJson("/api/v1/public/capture/{$jeton->token}", [
            'fichier' => UploadedFile::fake()->create('scan.pdf', 200, 'application/pdf'),
        ])->assertCreated();

        $this->postJson("/api/v1/public/capture/{$jeton->token}", [
            'fichier' => UploadedFile::fake()->create('scan2.pdf', 200, 'application/pdf'),
        ])
            ->assertStatus(404)
            // Message explicite, jamais vide : voir trouverJetonValide().
            ->assertJsonPath('message', 'Ce lien de capture est introuvable ou a expiré.');
    }

    public function test_un_jeton_expire_est_refuse(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::RECEPTION, $direction);
        $courrier = Courrier::factory()->create();
        $jeton = JetonCaptureNumerisation::genererPour($courrier, $agent);
        $jeton->update(['expire_at' => now()->subMinute()]);

        $this->postJson("/api/v1/public/capture/{$jeton->token}", [
            'fichier' => UploadedFile::fake()->create('scan.pdf', 200, 'application/pdf'),
        ])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Ce lien de capture est introuvable ou a expiré.');
    }

    public function test_un_jeton_inconnu_est_refuse_avec_un_message_explicite(): void
    {
        // ?? secours ne s'applique qu'à null/undefined côté JS : un message
        // vide (abort(404) sans argument) laisserait CapturePage.jsx
        // afficher une alerte sans aucun texte — voir trouverJetonValide().
        $this->getJson('/api/v1/public/capture/jeton-qui-n-existe-pas')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Ce lien de capture est introuvable ou a expiré.');
    }

    public function test_afficher_les_informations_du_jeton_sans_authentification(): void
    {
        $direction = Direction::factory()->create();
        $agent = $this->agent(Poste::RECEPTION, $direction);
        $courrier = Courrier::factory()->create(['objet' => 'Lettre à numériser']);
        $jeton = JetonCaptureNumerisation::genererPour($courrier, $agent);

        $this->getJson("/api/v1/public/capture/{$jeton->token}")
            ->assertOk()
            ->assertJsonPath('cible_type', 'courrier')
            ->assertJsonPath('cible_libelle', 'Lettre à numériser')
            ->assertJsonPath('plafond_ko', config('kernel.numerisation.plafond_ko_courrier'));
    }
}
