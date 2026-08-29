<?php

namespace Modules\Kernel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Modules\Kernel\Models\User;
use Modules\Kernel\Notifications\ReinitialisationMotDePasseNotification;
use Tests\TestCase;

class ReinitialisationMotDePasseTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_demande_renvoie_le_meme_message_quel_que_soit_lemail(): void
    {
        User::factory()->create(['email' => 'connu@ont.cd']);

        $reponseConnue = $this->postJson('/api/v1/auth/mot-de-passe/oublie', ['email' => 'connu@ont.cd']);
        $reponseInconnue = $this->postJson('/api/v1/auth/mot-de-passe/oublie', ['email' => 'inconnu@ont.cd']);

        $reponseConnue->assertOk();
        $reponseInconnue->assertOk();
        $this->assertSame($reponseConnue->json('message'), $reponseInconnue->json('message'));
    }

    public function test_un_email_connu_recoit_la_notification_de_reinitialisation(): void
    {
        Notification::fake();
        $utilisateur = User::factory()->create(['email' => 'connu@ont.cd']);

        $this->postJson('/api/v1/auth/mot-de-passe/oublie', ['email' => 'connu@ont.cd'])->assertOk();

        Notification::assertSentTo($utilisateur, ReinitialisationMotDePasseNotification::class);
    }

    public function test_un_email_inconnu_ne_declenche_aucune_notification(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/mot-de-passe/oublie', ['email' => 'inconnu@ont.cd'])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_le_lien_recu_permet_de_reinitialiser_le_mot_de_passe(): void
    {
        $utilisateur = User::factory()->create(['email' => 'connu@ont.cd', 'password' => 'AncienMotDePasse#12']);
        $token = Password::broker()->createToken($utilisateur);

        $this->postJson('/api/v1/auth/mot-de-passe/reinitialiser', [
            'email' => 'connu@ont.cd',
            'token' => $token,
            'mot_de_passe' => 'Xk9mQprT4vLw#26',
            'mot_de_passe_confirmation' => 'Xk9mQprT4vLw#26',
        ])->assertOk();

        $this->assertTrue(Hash::check('Xk9mQprT4vLw#26', $utilisateur->fresh()->password));
    }

    public function test_la_reinitialisation_leve_lobligation_de_changement_et_revoque_les_jetons(): void
    {
        $utilisateur = User::factory()->create([
            'email' => 'connu@ont.cd',
            'password' => 'AncienMotDePasse#12',
            'doit_changer_mot_de_passe' => true,
        ]);
        $utilisateur->createToken('appareil-perdu');
        $token = Password::broker()->createToken($utilisateur);

        $this->postJson('/api/v1/auth/mot-de-passe/reinitialiser', [
            'email' => 'connu@ont.cd',
            'token' => $token,
            'mot_de_passe' => 'Xk9mQprT4vLw#26',
            'mot_de_passe_confirmation' => 'Xk9mQprT4vLw#26',
        ])->assertOk();

        $utilisateur->refresh();
        $this->assertFalse($utilisateur->doit_changer_mot_de_passe);
        $this->assertCount(0, $utilisateur->tokens);
    }

    public function test_un_token_invalide_est_rejete(): void
    {
        User::factory()->create(['email' => 'connu@ont.cd']);

        $this->postJson('/api/v1/auth/mot-de-passe/reinitialiser', [
            'email' => 'connu@ont.cd',
            'token' => 'un-jeton-qui-ne-correspond-a-rien',
            'mot_de_passe' => 'Xk9mQprT4vLw#26',
            'mot_de_passe_confirmation' => 'Xk9mQprT4vLw#26',
        ])->assertStatus(422);
    }

    /**
     * Le lien pointe vers la page de réinitialisation du frontend, jamais
     * vers une route "password.reset" (inexistante dans cette API) — voir
     * User::sendPasswordResetNotification().
     */
    public function test_le_lien_envoye_pointe_vers_le_frontend(): void
    {
        Notification::fake();
        $utilisateur = User::factory()->create(['email' => 'connu@ont.cd']);

        $this->postJson('/api/v1/auth/mot-de-passe/oublie', ['email' => 'connu@ont.cd']);

        Notification::assertSentTo($utilisateur, ReinitialisationMotDePasseNotification::class, function (ReinitialisationMotDePasseNotification $notification) use ($utilisateur) {
            $mail = $notification->toMail($utilisateur);

            return str_contains($mail->actionUrl, rtrim(config('app.frontend_url'), '/').'/reinitialiser-mot-de-passe');
        });
    }
}
