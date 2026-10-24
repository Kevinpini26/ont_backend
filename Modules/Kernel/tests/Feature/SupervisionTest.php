<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Courrier\Mail\AccuseReceptionCandidatMail;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\StatutNotificationEmail;
use Modules\Kernel\Jobs\EnvoyerNotificationEmailJob;
use Modules\Kernel\Mail\EmailTestSupervisionMail;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\NotificationEmail;
use Modules\Kernel\Models\User;
use Tests\TestCase;

class SupervisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_non_administrateur_ne_peut_pas_consulter_la_supervision(): void
    {
        $responsable = User::factory()->responsableDirection(Direction::factory()->create())->create();

        $this->actingAs($responsable)->getJson('/api/v1/supervision')->assertStatus(403);
    }

    public function test_lapercu_de_supervision_expose_file_planificateur_emails_sauvegarde_et_disque(): void
    {
        $admin = User::factory()->administrateur()->create();
        NotificationEmail::query()->create(['type' => 'test', 'destinataire' => 'x@example.test', 'statut' => StatutNotificationEmail::ECHEC]);

        $reponse = $this->actingAs($admin)->getJson('/api/v1/supervision')->assertOk();

        $reponse->assertJsonStructure([
            'file_attente' => ['connexion', 'en_attente', 'en_echec'],
            'planificateur' => ['dernier_tick', 'actif'],
            'emails' => ['en_file', 'en_echec'],
            'disque' => ['libre_octets', 'total_octets'],
        ]);
        $this->assertSame(1, $reponse->json('emails.en_echec'));
    }

    public function test_relancer_un_job_introuvable_echoue_proprement(): void
    {
        $admin = User::factory()->administrateur()->create();

        $this->actingAs($admin)->postJson('/api/v1/supervision/jobs-en-echec/uuid-inconnu/relancer')->assertStatus(404);
    }

    public function test_un_job_en_echec_peut_etre_relance_et_disparait_des_echecs(): void
    {
        config(['queue.default' => 'database']);
        $admin = User::factory()->administrateur()->create();
        $courrier = Courrier::factory()->create();
        $notification = NotificationEmail::query()->create([
            'type' => 'test', 'destinataire' => 'x@example.test', 'statut' => StatutNotificationEmail::EN_FILE,
        ]);
        EnvoyerNotificationEmailJob::dispatch($notification->id, new AccuseReceptionCandidatMail($courrier), 'x@example.test');

        $jobRow = DB::table('jobs')->first();
        $payload = json_decode($jobRow->payload, true);
        $uuid = $payload['uuid'];
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => $jobRow->queue,
            'payload' => $jobRow->payload,
            'exception' => 'Exception simulée pour le test.',
            'failed_at' => now(),
        ]);
        DB::table('jobs')->delete();

        $this->actingAs($admin)->postJson("/api/v1/supervision/jobs-en-echec/{$uuid}/relancer")->assertOk();

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'supervision.job_relance', 'user_id' => $admin->id]);
    }

    public function test_renvoyer_une_notification_qui_nest_pas_en_echec_est_refuse(): void
    {
        $admin = User::factory()->administrateur()->create();
        $notification = NotificationEmail::query()->create([
            'type' => 'test', 'destinataire' => 'x@example.test', 'statut' => StatutNotificationEmail::ENVOYE,
        ]);

        $this->actingAs($admin)->postJson("/api/v1/supervision/emails-en-echec/{$notification->id}/renvoyer")
            ->assertUnprocessable();
    }

    public function test_renvoyer_une_notification_en_echec_sans_job_dorigine_disponible_est_refuse(): void
    {
        $admin = User::factory()->administrateur()->create();
        $notification = NotificationEmail::query()->create([
            'type' => 'test', 'destinataire' => 'x@example.test', 'statut' => StatutNotificationEmail::ECHEC, 'job_uuid' => 'uuid-disparu',
        ]);

        $this->actingAs($admin)->postJson("/api/v1/supervision/emails-en-echec/{$notification->id}/renvoyer")
            ->assertUnprocessable();
    }

    public function test_le_bouton_email_de_test_met_un_email_en_file_vers_ladresse_de_lappelant(): void
    {
        Mail::fake();
        $admin = User::factory()->administrateur()->create(['email' => 'admin@ont.test']);

        $this->actingAs($admin)->postJson('/api/v1/supervision/email-test')->assertOk();

        $this->assertDatabaseHas('notifications_email', ['destinataire' => 'admin@ont.test', 'type' => 'supervision.test']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'supervision.email_test_envoye', 'user_id' => $admin->id]);
    }

    public function test_le_mailable_de_test_se_rend_sans_erreur(): void
    {
        $html = (new EmailTestSupervisionMail)->render();

        $this->assertStringContainsString('Email de test', $html);
    }
}
