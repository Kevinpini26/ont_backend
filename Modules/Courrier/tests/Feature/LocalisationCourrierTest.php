<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;

class LocalisationCourrierTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_une_cote_de_classement_est_generee_a_lenregistrement(): void
    {
        Date::setTestNow('2026-11-01');

        $direction = Direction::query()->where('code', 'DFP')->firstOrFail();
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::SIGNE, 'direction_destination_id' => $direction->id]);
        $courrier->imputations()->create(['direction_id' => $direction->id, 'mention' => 'pour_attribution', 'est_principale' => true]);
        $this->marquerDecharge($courrier);
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $direction);

        $response = $this->actingAs($secretariat2)->postJson("/api/v1/courriers/{$courrier->id}/enregistrer", [
            'classification' => 'interne',
            'note_technique' => 'RAS.',
        ])->assertOk();

        $cote = $response->json('data.cote_classement');

        $this->assertNull($response->json('data.numero_enregistrement'));
        $this->assertNotNull($cote);
        $this->assertStringContainsString('DFP', $cote);
        $this->assertStringContainsString('2026', $cote);
    }

    public function test_la_cote_de_classement_utilise_ont_a_defaut_de_direction_imputee(): void
    {
        Date::setTestNow('2026-11-01');

        $direction = Direction::factory()->create();
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::SIGNE, 'direction_destination_id' => null]);
        $this->marquerDecharge($courrier);
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $direction);

        $response = $this->actingAs($secretariat2)->postJson("/api/v1/courriers/{$courrier->id}/enregistrer", [
            'classification' => 'interne',
            'note_technique' => 'RAS.',
        ])->assertOk();

        $this->assertStringContainsString('ONT', $response->json('data.cote_classement'));
    }

    public function test_un_courrier_est_recherchable_par_sa_cote_de_classement(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $courrier = Courrier::factory()->create(['cote_classement' => 'ONT-2026-0099']);
        Courrier::factory()->create(['cote_classement' => 'ONT-2026-0001']);

        $this->actingAs($reception)
            ->getJson('/api/v1/courriers?cote_classement=ONT-2026-0099')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $courrier->id);
    }
}
