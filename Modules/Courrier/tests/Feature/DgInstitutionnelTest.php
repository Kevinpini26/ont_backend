<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;

class DgInstitutionnelTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_dg_ne_decide_ni_dispatch_ni_preparation_avant_reception_du_bordereau(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $assistant = $this->agent(Poste::ASSISTANT_1, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $courrier->transitions()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'destinataire_poste' => Poste::DG->value,
            'created_at' => now(),
        ]);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [[
            'type' => 'classement', 'instruction' => 'Classer.',
        ]]])->assertUnprocessable()->assertJsonValidationErrors('courrier');
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/demander-preparation-reponse", [
            'assistant_id' => $assistant->id, 'instruction' => 'Préparer D.',
        ])->assertUnprocessable()->assertJsonValidationErrors('courrier');
        $this->assertDatabaseCount('dispatchs_courrier', 0);
        $this->assertDatabaseCount('missions_documentaires', 0);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/demander-preparation-reponse", [
            'assistant_id' => $assistant->id, 'instruction' => 'Préparer D.',
        ])->assertCreated();
    }

    public function test_champs_decisionnels_sensibles_sont_determines_par_le_serveur(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", [
            'decisionnaire_id' => 999999, 'signataire_id' => 999999, 'cycle' => 99,
            'numero_depart' => 'FAUX', 'signe_at' => now()->toISOString(),
            'statut' => 'envoye', 'projet_courrier_id' => 999999,
            'en_reponse_a_courrier_id' => 999999, 'dossier_id' => 999999,
            'destinations' => [[
                'type' => 'classement', 'instruction' => 'Classer.', 'decisionnaire_id' => 999999,
                'cycle' => 99,
            ]],
        ])->assertOk();
        $dispatch = $courrier->dispatchs()->sole();
        $this->assertSame($dg->id, $dispatch->decisionnaire_id);
        $this->assertSame(1, $dispatch->cycle);
        $this->assertSame($courrier->dossier_id, $dispatch->dossier_id);
        $courrier->refresh();
        $this->assertNull($courrier->signataire_id);
        $this->assertNull($courrier->numero_depart);
        $this->assertNull($courrier->en_reponse_a_courrier_id);
        $this->assertSame(CourrierStatut::EN_DISPATCH, $courrier->statut);
    }

    public function test_dg_sans_delegation_sec2_ne_peut_pas_executer_classer_archiver_ni_envoyer(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [[
            'type' => 'classement', 'instruction' => 'Classer.',
        ]]])->assertOk();
        $dispatch = $courrier->dispatchs()->sole();
        $this->actingAs($dg)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertForbidden();
        $this->actingAs($dg)->postJson("/api/v1/dispatchs/{$dispatch->id}/classer", ['emplacement' => 'Archives'])->assertUnprocessable();
        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$courrier->dossier_id}/archiver")->assertUnprocessable();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/envoyer", [
            'destinataire_externe_nom' => 'Destinataire', 'mode_expedition' => 'poste',
        ])->assertForbidden();
    }
}
