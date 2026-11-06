<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Relevé à la répétition : un envoi au-delà de post_max_size affichait « The POST data is too large. »,
 * alors que l'interface est entièrement en français.
 */
class EnvoiTropVolumineuxTest extends TestCase
{
    public function test_un_envoi_trop_volumineux_est_refuse_avec_un_message_en_francais(): void
    {
        $reponse = $this->call('POST', '/api/v1/auth/login', [], [], [], [
            'CONTENT_LENGTH' => (string) (1024 * 1024 * 1024 * 4),
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $reponse->assertStatus(413);
        $this->assertSame('Le fichier envoyé est trop volumineux.', $reponse->json('message'));
    }
}
