<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Enums\TypeLienPublic;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Models\StagiaireLienPublic;
use Modules\Stagiaires\Notifications\ConventionASignerNotification;

class ConventionStageTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_la_validation_de_larrivee_genere_la_convention_et_envoie_le_lien_de_signature(): void
    {
        Notification::fake();

        $dfp = User::factory()->agentDfp()->create();
        $direction = Direction::factory()->create(['actif' => true]);
        $stagiaire = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::AFFECTE,
            'direction_id' => $direction->id,
            'contact' => 'candidat@example.com',
        ]);

        $response = $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/valider-arrivee", [
                'date_debut_stage' => now()->addDays(5)->toDateString(),
                'date_fin_stage' => now()->addDays(65)->toDateString(),
            ])
            ->assertOk();

        $this->assertNotNull($response->json('data.convention.genere_at'));
        $this->assertDatabaseHas('stagiaire_liens_publics', [
            'stagiaire_id' => $stagiaire->id,
            'type' => TypeLienPublic::CONVENTION->value,
        ]);

        Notification::assertSentOnDemand(ConventionASignerNotification::class);
    }

    public function test_le_responsable_de_direction_peut_signer_la_convention_une_seule_fois(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $stagiaire = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::STAGE_EN_COURS,
            'direction_id' => $direction->id,
            'convention_chemin' => 'conventions/fake.pdf',
        ]);

        $this->actingAs($responsable)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/convention/signer-direction")
            ->assertOk()
            ->assertJsonPath('data.convention.signee_direction_par', $responsable->name);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'stagiaire.convention_signature_direction',
            'auditable_id' => $stagiaire->id,
        ]);

        $this->actingAs($responsable)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/convention/signer-direction")
            ->assertStatus(422);
    }

    public function test_le_stagiaire_peut_signer_via_le_lien_public_a_usage_unique(): void
    {
        $stagiaire = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::STAGE_EN_COURS,
            'convention_chemin' => 'conventions/fake.pdf',
        ]);

        $lien = StagiaireLienPublic::genererPour($stagiaire, TypeLienPublic::CONVENTION);

        $this->getJson("/api/v1/public/liens/{$lien->token}")
            ->assertOk()
            ->assertJsonPath('data.type', 'convention')
            ->assertJsonPath('data.valide', true);

        $this->postJson("/api/v1/public/liens/{$lien->token}/signer-convention")
            ->assertOk();

        $this->assertNotNull($stagiaire->fresh()->convention_signee_stagiaire_at);

        // Lien à usage unique : une seconde tentative doit être refusée,
        // par un 404 générique (pas de distinction avec un jeton inconnu).
        $this->postJson("/api/v1/public/liens/{$lien->token}/signer-convention")
            ->assertStatus(404);
    }

    public function test_un_lien_du_mauvais_type_est_refuse_pour_signer_une_convention(): void
    {
        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::CLOTURE]);
        $lien = StagiaireLienPublic::genererPour($stagiaire, TypeLienPublic::RETOUR_EXPERIENCE);

        $this->postJson("/api/v1/public/liens/{$lien->token}/signer-convention")
            ->assertStatus(404);
    }
}
