<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Mail\AccuseReceptionCandidatMail;
use Modules\Courrier\Mail\AccuseReceptionCourrierExterneMail;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Jobs\EnvoyerNotificationEmailJob;
use Modules\Kernel\Models\Direction;

class ReceptionCourrierPhysiqueTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_la_reception_enregistre_un_courrier_physique_complet_une_seule_fois(): void
    {
        Storage::fake('local');
        Mail::fake();
        Queue::fake();
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $reponse = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Demande de partenariat',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Entreprise Test ONT',
            'mode_reception' => 'porteur',
            'date_courrier' => '2026-09-26',
            'reference_expediteur' => 'REF-EXT-001',
            'expediteur_externe_email' => 'contact@entreprise-test.cd',
            'expediteur_externe_telephone' => '+243 900 000 001',
            'piece_jointe' => UploadedFile::fake()->create('courrier.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $id = $reponse->json('data.id');
        $courrier = Courrier::withoutGlobalScopes()->with(['dossier', 'numerisations', 'piecesJointes', 'transitions'])->findOrFail($id);

        $this->assertDatabaseCount('courriers', 1);
        $this->assertNotNull($courrier->dossier);
        $this->assertSame('Entreprise Test ONT', $courrier->expediteur_externe_nom);
        $this->assertSame('porteur', $courrier->mode_reception->value);
        $this->assertNull($courrier->numero_accuse_reception);
        $this->assertNotNull($courrier->numero_enregistrement);
        $this->assertNotNull($courrier->enregistre_at);
        $this->assertSame($reception->id, $courrier->created_by);
        $this->assertCount(1, $courrier->piecesJointes);
        $this->assertCount(1, $courrier->numerisations);
        $this->assertNotNull($courrier->numerisations->first()->sha256);
        Storage::disk('local')->assertExists($courrier->numerisations->first()->chemin);
        $this->assertCount(1, $courrier->transitions);
        $this->assertSame(Poste::RECEPTION->value, $courrier->transitions->first()->expediteur_poste);
        $this->assertSame(Poste::SECRETARIAT_1->value, $courrier->transitions->first()->destinataire_poste);
        Queue::assertPushed(
            EnvoyerNotificationEmailJob::class,
            fn ($job) => $job->mailable instanceof AccuseReceptionCourrierExterneMail && $job->email === 'contact@entreprise-test.cd',
        );
    }

    public function test_un_candidat_enregistre_au_guichet_avec_email_recoit_un_accuse_de_reception(): void
    {
        Storage::fake('local');
        Mail::fake();
        Queue::fake();
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Demande de stage académique',
            'type' => CourrierType::DEMANDE_STAGE->value,
            'candidat_nom' => 'Jean Kabila',
            'candidat_email' => 'jean.kabila@example.test',
            'candidat_contact' => '+243 900 000 002',
            'candidat_etablissement' => 'Université de Kinshasa',
            'periode_souhaitee_debut' => now()->addMonth()->toDateString(),
            'periode_souhaitee_fin' => now()->addMonths(3)->toDateString(),
            'mode_reception' => 'porteur',
            'date_courrier' => '2026-09-26',
            'piece_jointe' => UploadedFile::fake()->create('lettre.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        Queue::assertPushed(
            EnvoyerNotificationEmailJob::class,
            fn ($job) => $job->mailable instanceof AccuseReceptionCandidatMail && $job->email === 'jean.kabila@example.test',
        );
    }

    public function test_un_expediteur_enregistre_au_guichet_sans_email_ne_recoit_aucun_mail_mais_peut_obtenir_la_fiche_imprimable(): void
    {
        Storage::fake('local');
        Mail::fake();
        Queue::fake();
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $id = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Demande sans contact électronique',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Entreprise Sans Email',
            'mode_reception' => 'porteur',
            'date_courrier' => '2026-09-26',
            'piece_jointe' => UploadedFile::fake()->create('courrier.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data.id');

        Queue::assertNothingPushed();

        $reponse = $this->actingAs($reception)->get("/api/v1/courriers/{$id}/accuse-reception-guichet");
        $reponse->assertOk();
        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
    }

    public function test_une_correspondance_physique_exige_un_expediteur_et_un_mode_non_public(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $this->actingAs($reception)->postJson('/api/v1/courriers', [
            'objet' => 'Courrier incomplet',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'numerisation_impossible' => true,
            'mode_reception' => 'depot_en_ligne',
        ])->assertUnprocessable()->assertJsonValidationErrors(['expediteur_externe_nom', 'mode_reception']);

        $this->assertDatabaseCount('courriers', 0);
    }
}
