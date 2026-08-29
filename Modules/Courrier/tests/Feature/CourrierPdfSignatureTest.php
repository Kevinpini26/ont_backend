<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;

class CourrierPdfSignatureTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_le_pdf_definitif_est_genere_exactement_a_la_signature_pas_avant(): void
    {
        $direction = Direction::factory()->create();
        $relecteur = $this->agent(Poste::ASSISTANT_1, $direction);
        $dg = $this->agent(Poste::DG, $direction);

        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_RELECTURE,
            'relecteur_id' => $relecteur->id,
            'relecture_validee_at' => now(),
            'signataire_id' => null,
            'signe_at' => null,
            'pdf_chemin' => null,
            'projet_reponse_contenu' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Corps du courrier signé.']]],
                ],
            ],
        ]);
        $this->marquerDecharge($courrier);

        $this->assertNull($courrier->pdf_chemin);

        $response = $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/signer")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::SIGNE->value);

        $courrier->refresh();
        $this->assertNotNull($courrier->pdf_chemin);
        Storage::disk('local')->assertExists($courrier->pdf_chemin);
    }

    public function test_le_pdf_est_telechargeable_apres_signature(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);

        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::SIGNE,
            'signataire_id' => $dg->id,
            'signe_at' => now(),
            'pdf_chemin' => 'courriers-signes/courrier-test.pdf',
        ]);
        Storage::disk('local')->put($courrier->pdf_chemin, '%PDF-1.7 contenu de test');

        $this->actingAs($dg)
            ->get("/api/v1/courriers/{$courrier->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_le_telechargement_renvoie_404_si_le_courrier_nest_pas_encore_signe(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::RECU, 'pdf_chemin' => null]);

        $this->actingAs($dg)
            ->get("/api/v1/courriers/{$courrier->id}/pdf")
            ->assertStatus(404);
    }
}
