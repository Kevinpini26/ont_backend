<?php

namespace Modules\Public\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Stagiaires\Models\Stagiaire;
use Tests\TestCase;

class VerificationAttestationPublicTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_tiers_peut_verifier_une_attestation_via_le_jeton_du_qr_code_sans_second_facteur(): void
    {
        Stagiaire::factory()->create([
            'nom' => 'Jean Kabila',
            'numero_attestation' => 'ATT-2026-000042',
            'token_verification' => str_repeat('a', 32),
            'date_debut_stage' => '2026-01-05',
            'date_fin_stage' => '2026-03-05',
            'etablissement_origine' => 'Université de Kinshasa',
            'evaluation_direction_total' => 78,
        ]);

        $response = $this->getJson('/api/v1/public/attestations/token/'.str_repeat('a', 32));

        $response->assertOk()
            ->assertJsonPath('data.numero_attestation', 'ATT-2026-000042')
            ->assertJsonPath('data.nom', 'Jean Kabila')
            ->assertJsonPath('data.date_debut_stage', '2026-01-05')
            ->assertJsonPath('data.date_fin_stage', '2026-03-05')
            ->assertJsonMissing(['etablissement_origine'])
            ->assertJsonMissing(['evaluation_direction_total']);
    }

    public function test_un_jeton_inconnu_renvoie_404(): void
    {
        $this->getJson('/api/v1/public/attestations/token/'.str_repeat('z', 32))
            ->assertStatus(404);
    }

    public function test_lancien_numero_reste_verifiable_accompagne_du_nom_du_stagiaire(): void
    {
        Stagiaire::factory()->create([
            'nom' => 'Jean Kabila',
            'numero_attestation' => 'ATT-2026-000043',
            'token_verification' => str_repeat('b', 32),
        ]);

        $this->postJson('/api/v1/public/attestations/verifier', [
            'numero' => 'ATT-2026-000043',
            'nom' => 'Kabila',
        ])
            ->assertOk()
            ->assertJsonPath('data.numero_attestation', 'ATT-2026-000043');
    }

    public function test_lancien_numero_seul_sans_le_nom_correct_est_rejete(): void
    {
        Stagiaire::factory()->create([
            'nom' => 'Jean Kabila',
            'numero_attestation' => 'ATT-2026-000044',
        ]);

        $this->postJson('/api/v1/public/attestations/verifier', [
            'numero' => 'ATT-2026-000044',
            'nom' => 'Un Autre Nom',
        ])->assertStatus(404);
    }

    public function test_un_numero_inconnu_renvoie_404(): void
    {
        $this->postJson('/api/v1/public/attestations/verifier', [
            'numero' => 'ATT-2026-999999',
            'nom' => 'Peu Importe',
        ])->assertStatus(404);
    }
}
