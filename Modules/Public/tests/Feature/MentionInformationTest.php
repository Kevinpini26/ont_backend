<?php

namespace Modules\Public\Tests\Feature;

use Tests\TestCase;

class MentionInformationTest extends TestCase
{
    public function test_la_mention_dinformation_est_accessible_sans_authentification(): void
    {
        $this->getJson('/api/v1/public/mention-information')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'responsable_traitement',
                    'finalites',
                    'destinataires',
                    'duree_conservation',
                    'droits',
                    'transfert_etat_tiers',
                    'caractere_obligatoire',
                ],
            ]);
    }
}
