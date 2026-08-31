<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Enums\StagiaireTypeStage;
use Modules\Stagiaires\Models\Stagiaire;

/**
 * SMS en complément du courriel (voir Modules\Kernel\Support\SmsNotificationCanal
 * et docs/conformite-donnees.md) : un contact qui n'est pas une adresse
 * email valide était auparavant notifié par aucun canal.
 */
class NotificationSmsTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_un_contact_telephonique_recoit_un_sms_plutot_quun_email(): void
    {
        Log::spy();

        $dfp = User::factory()->agentDfp()->create();
        $direction = Direction::factory()->create();
        $stagiaire = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::AFFECTE,
            'direction_id' => $direction->id,
            'type_stage' => StagiaireTypeStage::ACADEMIQUE,
            'contact' => '+243 812 345 678',
        ]);

        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/valider-arrivee", [
                'date_debut_stage' => now()->toDateString(),
                'date_fin_stage' => now()->addMonths(2)->toDateString(),
            ])
            ->assertOk();

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $contexte) => str_contains($message, 'SMS') && $contexte['destinataire'] === '+243 812 345 678')
            ->atLeast()->once();
    }

    public function test_un_contact_ni_email_ni_telephone_ne_recoit_aucune_notification(): void
    {
        Log::spy();

        $dfp = User::factory()->agentDfp()->create();
        $direction = Direction::factory()->create();
        $stagiaire = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::AFFECTE,
            'direction_id' => $direction->id,
            'type_stage' => StagiaireTypeStage::ACADEMIQUE,
            'contact' => 'RAS',
        ]);

        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/valider-arrivee", [
                'date_debut_stage' => now()->toDateString(),
                'date_fin_stage' => now()->addMonths(2)->toDateString(),
            ])
            ->assertOk();

        Log::shouldNotHaveReceived('info', fn ($message) => str_contains($message, 'SMS'));
    }
}
