<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Console\RelancerAvisDgEnAttenteCommand;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Mail\AvisDgEnAttenteMail;
use Modules\Courrier\Mail\CourrierRecuMail;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Jobs\EnvoyerNotificationEmailJob;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class NotificationsEmailTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_la_creation_dun_courrier_adresse_a_une_direction_ne_la_notifie_pas_avant_le_dispatch(): void
    {
        Queue::fake();
        Storage::fake('local');

        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $reception = $this->agent(Poste::RECEPTION, Direction::factory()->create());

        $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Test notification',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire externe',
            'mode_reception' => 'porteur',
            'direction_destination_id' => $direction->id,
            'piece_jointe' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        // La direction n'apprend rien avant la décision de la DG et le dispatch exécuté par SEC2.
        Queue::assertNotPushed(
            EnvoyerNotificationEmailJob::class,
            fn ($job) => $job->mailable instanceof CourrierRecuMail && $job->email === $responsable->email,
        );
        $this->assertDatabaseMissing('notifications_email', ['type' => 'courrier.recu_direction', 'destinataire' => $responsable->email]);
    }

    public function test_la_relance_davis_dg_ne_seffectue_que_pour_les_courriers_en_attente_depuis_plus_de_48h(): void
    {
        Queue::fake();

        $direction = Direction::factory()->create();
        $dg = User::factory()->agentCircuitCourrier(Poste::DG, $direction)->create();

        $ancien = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'direction_origine_id' => null,
            'direction_destination_id' => null,
        ]);
        $ancien->transitions()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'created_at' => now()->subHours(50)]);

        $recent = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'direction_origine_id' => null,
            'direction_destination_id' => null,
        ]);
        $recent->transitions()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'created_at' => now()->subHours(2)]);

        $this->artisan(RelancerAvisDgEnAttenteCommand::class)->assertSuccessful();

        Queue::assertPushed(
            EnvoyerNotificationEmailJob::class,
            fn ($job) => $job->mailable instanceof AvisDgEnAttenteMail && $job->mailable->courrier->is($ancien) && $job->email === $dg->email,
        );
        Queue::assertNotPushed(
            EnvoyerNotificationEmailJob::class,
            fn ($job) => $job->mailable instanceof AvisDgEnAttenteMail && $job->mailable->courrier->is($recent),
        );

        $this->assertNotNull($ancien->fresh()->relance_avis_dg_envoyee_at);

        // Une seconde exécution ne doit pas relancer une seconde fois.
        Queue::fake();
        $this->artisan(RelancerAvisDgEnAttenteCommand::class)->assertSuccessful();
        Queue::assertNothingPushed();
    }
}
