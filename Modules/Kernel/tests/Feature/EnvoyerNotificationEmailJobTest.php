<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Modules\Courrier\Mail\AccuseReceptionCandidatMail;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\StatutNotificationEmail;
use Modules\Kernel\Jobs\EnvoyerNotificationEmailJob;
use Modules\Kernel\Models\NotificationEmail;
use Modules\Kernel\Models\User;
use Modules\Kernel\Notifications\EchecEnvoiEmailNotification;
use Tests\TestCase;

class EnvoyerNotificationEmailJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_envoi_reussi_marque_la_notification_envoyee_et_incremente_les_tentatives(): void
    {
        Mail::fake();
        $courrier = Courrier::factory()->create();
        $notification = NotificationEmail::query()->create([
            'type' => 'test.envoi',
            'destinataire' => 'destinataire@example.test',
            'notifiable_type' => $courrier->getMorphClass(),
            'notifiable_id' => $courrier->id,
            'statut' => StatutNotificationEmail::EN_FILE,
        ]);
        $mail = new AccuseReceptionCandidatMail($courrier);

        (new EnvoyerNotificationEmailJob($notification->id, $mail, 'destinataire@example.test'))->handle();

        Mail::assertSent(AccuseReceptionCandidatMail::class, fn ($sent) => $sent->hasTo('destinataire@example.test'));
        $notification->refresh();
        $this->assertSame(StatutNotificationEmail::ENVOYE, $notification->statut);
        $this->assertSame(1, $notification->tentatives);
        $this->assertNotNull($notification->envoye_at);
        $this->assertTrue($notification->notifiable->is($courrier));
    }

    public function test_un_echec_definitif_marque_la_notification_en_echec_et_alerte_les_agents_desired(): void
    {
        Notification::fake();
        $courrier = Courrier::factory()->create();
        $notification = NotificationEmail::query()->create([
            'type' => 'test.envoi',
            'destinataire' => 'destinataire@example.test',
            'statut' => StatutNotificationEmail::EN_FILE,
            'tentatives' => 3,
        ]);
        $agent = User::factory()->administrateur()->create();
        $mail = new AccuseReceptionCandidatMail($courrier);

        (new EnvoyerNotificationEmailJob($notification->id, $mail, 'destinataire@example.test', [$agent]))
            ->failed(new \RuntimeException('SMTP indisponible'));

        $notification->refresh();
        $this->assertSame(StatutNotificationEmail::ECHEC, $notification->statut);
        $this->assertSame('SMTP indisponible', $notification->derniere_erreur);
        Notification::assertSentTo($agent, EchecEnvoiEmailNotification::class);
    }

    public function test_un_echec_definitif_sans_agent_a_alerter_ne_tente_aucune_notification(): void
    {
        Notification::fake();
        $notification = NotificationEmail::query()->create([
            'type' => 'test.envoi',
            'destinataire' => 'destinataire@example.test',
            'statut' => StatutNotificationEmail::EN_FILE,
            'tentatives' => 3,
        ]);
        $courrier = Courrier::factory()->create();
        $mail = new AccuseReceptionCandidatMail($courrier);

        (new EnvoyerNotificationEmailJob($notification->id, $mail, 'destinataire@example.test'))
            ->failed(new \RuntimeException('SMTP indisponible'));

        $notification->refresh();
        $this->assertSame(StatutNotificationEmail::ECHEC, $notification->statut);
        Notification::assertNothingSent();
    }
}
