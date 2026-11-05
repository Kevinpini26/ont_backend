<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Mail\AvisDgEnAttenteMail;
use Modules\Courrier\Mail\CourrierRecuMail;
use Modules\Courrier\Models\Courrier;

/** Lot 2 : un e-mail sort du système ; l'objet d'un courrier confidentiel ou secret n'y figure jamais. */
class ConfidentialiteEmailsTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_les_emails_internes_ne_revelent_pas_lobjet_dun_courrier_confidentiel(): void
    {
        foreach (['confidentiel', 'secret'] as $niveau) {
            $courrier = Courrier::factory()->create(['objet' => "OBJET-{$niveau}-MAIL", 'niveau_confidentialite' => $niveau]);

            foreach ([new CourrierRecuMail($courrier), new AvisDgEnAttenteMail($courrier)] as $mail) {
                $rendu = $mail->render();
                $this->assertStringNotContainsString("OBJET-{$niveau}-MAIL", $rendu, $mail::class);
                $this->assertStringNotContainsString("OBJET-{$niveau}-MAIL", $mail->envelope()->subject ?? '');
                $this->assertStringContainsString('Courrier confidentiel', $rendu);
            }
        }
    }

    public function test_un_courrier_ordinaire_garde_son_objet_dans_lemail(): void
    {
        $courrier = Courrier::factory()->create(['objet' => 'OBJET-ORDINAIRE-MAIL', 'niveau_confidentialite' => 'ordinaire']);

        $this->assertStringContainsString('OBJET-ORDINAIRE-MAIL', (new CourrierRecuMail($courrier))->render());
    }
}
