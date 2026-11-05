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

    public function test_la_mention_publique_ne_contient_aucune_note_interne(): void
    {
        $duree = (string) $this->getJson('/api/v1/public/mention-information')->json('data.duree_conservation');

        $this->assertStringNotContainsString('confirmer', mb_strtolower($duree));
        $this->assertStringNotContainsString('DFP', $duree);
        $this->assertStringNotContainsString('docs/', $duree);
    }
}
