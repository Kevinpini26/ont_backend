<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\InstructionCourrierDg;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\Direction;

class InstructionCourrierDgTest extends CourrierTestCase
{
    use RefreshDatabase;

    private function payload(int $instructionId, int $directionId, int $relecteurId): array
    {
        return [
            'instruction_courrier_dg_id' => $instructionId,
            'direction_destination_id' => $directionId,
            'objet' => 'Note proactive de la DG',
            'projet_reponse_contenu' => ['type' => 'doc', 'content' => []],
            'relecteur_id' => $relecteurId,
        ];
    }

    public function test_dg_cree_sec1_ouvre_et_consomme_une_seule_fois_avec_audit(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_1, $direction);
        $etranger = $this->agent(Poste::SECRETARIAT_2, $direction);
        $id = $this->actingAs($dg)->postJson('/api/v1/instructions-courrier-dg', [
            'instruction' => 'Préparer une note à la direction concernée.',
        ])->assertCreated()->assertJsonPath('data.active', true)->json('data.id');

        $this->actingAs($etranger)->getJson('/api/v1/instructions-courrier-dg')->assertForbidden();
        $this->actingAs($etranger)->getJson("/api/v1/instructions-courrier-dg/{$id}")->assertNotFound();
        $this->actingAs($sec1)->getJson('/api/v1/instructions-courrier-dg')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($sec1)->getJson("/api/v1/instructions-courrier-dg/{$id}")->assertOk()
            ->assertJsonPath('data.ouvert_par_id', $sec1->id);

        $payload = $this->payload($id, $direction->id, $relecteur->id);
        $courrierId = $this->actingAs($sec1)->postJson('/api/v1/courriers/initier-dg', $payload)
            ->assertCreated()->assertJsonPath('data.initie_par_dg', true)->json('data.id');
        $instruction = InstructionCourrierDg::query()->findOrFail($id);
        $this->assertSame($courrierId, $instruction->courrier_id);
        $this->assertSame($sec1->id, $instruction->consomme_par_id);
        $this->assertNotNull($instruction->consomme_at);
        $this->assertFalse($instruction->active());
        $this->assertNull(Courrier::withoutGlobalScopes()->findOrFail($courrierId)->en_reponse_a_courrier_id);
        $this->actingAs($sec1)->postJson('/api/v1/courriers/initier-dg', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('instruction_courrier_dg_id');
        $this->assertDatabaseCount('courriers', 1);
        foreach (['instruction_courrier_dg.creee', 'instruction_courrier_dg.ouverte', 'instruction_courrier_dg.consommee', 'courrier.initie_par_dg'] as $action) {
            $this->assertSame(1, AuditLog::query()->where('action', $action)->count());
        }
    }

    public function test_sec1_sans_instruction_et_autres_postes_ne_peuvent_pas_initier_dg(): void
    {
        $direction = Direction::factory()->create();
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $dg = $this->agent(Poste::DG, $direction);
        $assistant = $this->agent(Poste::ASSISTANT_1, $direction);
        $payload = $this->payload(999999, $direction->id, $assistant->id);

        $this->actingAs($sec1)->postJson('/api/v1/courriers/initier-dg', array_diff_key($payload, ['instruction_courrier_dg_id' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('instruction_courrier_dg_id');
        $this->actingAs($sec1)->postJson('/api/v1/courriers/initier-dg', $payload)->assertNotFound();
        $this->actingAs($sec1)->postJson('/api/v1/instructions-courrier-dg', ['instruction' => 'Auto-instruction interdite'])
            ->assertForbidden();
        $this->actingAs($assistant)->postJson('/api/v1/instructions-courrier-dg', ['instruction' => 'Instruction assistant interdite'])
            ->assertForbidden();
        $this->actingAs($dg)->postJson('/api/v1/courriers/initier-dg', $payload)->assertForbidden();
        $this->assertDatabaseCount('courriers', 0);
    }

    public function test_instruction_nominative_est_invisible_et_inutilisable_pour_un_autre_sec1(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec1A = $this->agent(Poste::SECRETARIAT_1, $direction);
        $sec1B = $this->agent(Poste::SECRETARIAT_1, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_1, $direction);
        $id = $this->actingAs($dg)->postJson('/api/v1/instructions-courrier-dg', [
            'instruction' => 'Préparer une note nominative.',
            'destinataire_user_id' => $sec1A->id,
        ])->assertCreated()->json('data.id');

        $this->actingAs($sec1B)->getJson('/api/v1/instructions-courrier-dg')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($sec1B)->getJson("/api/v1/instructions-courrier-dg/{$id}")->assertNotFound();
        $this->actingAs($sec1B)->postJson('/api/v1/courriers/initier-dg', $this->payload($id, $direction->id, $relecteur->id))->assertNotFound();
        $this->actingAs($sec1A)->postJson('/api/v1/courriers/initier-dg', $this->payload($id, $direction->id, $relecteur->id))->assertCreated();
    }

    public function test_annulation_et_expiration_interdisent_consommation(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_1, $direction);
        $annulee = $this->actingAs($dg)->postJson('/api/v1/instructions-courrier-dg', ['instruction' => 'Instruction à annuler.'])
            ->assertCreated()->json('data.id');
        $this->actingAs($dg)->postJson("/api/v1/instructions-courrier-dg/{$annulee}/annuler")->assertOk()->assertJsonPath('data.active', false);
        $this->actingAs($sec1)->postJson('/api/v1/courriers/initier-dg', $this->payload($annulee, $direction->id, $relecteur->id))->assertUnprocessable();

        $expiree = $this->actingAs($dg)->postJson('/api/v1/instructions-courrier-dg', [
            'instruction' => 'Instruction à expiration proche.', 'expire_at' => now()->addMinute()->toIso8601String(),
        ])->assertCreated()->json('data.id');
        $this->travel(2)->minutes();
        $this->actingAs($sec1)->getJson('/api/v1/instructions-courrier-dg')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($sec1)->postJson('/api/v1/courriers/initier-dg', $this->payload($expiree, $direction->id, $relecteur->id))->assertUnprocessable();
        $this->actingAs($dg)->postJson("/api/v1/instructions-courrier-dg/{$annulee}/annuler")->assertUnprocessable();
        $this->assertDatabaseCount('courriers', 0);
    }

    public function test_instruction_de_nouveau_courrier_ne_peut_pas_devenir_une_reponse_d(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_1, $direction);
        $a = Courrier::factory()->create();
        $id = $this->actingAs($dg)->postJson('/api/v1/instructions-courrier-dg', [
            'instruction' => 'Préparer une note proactive, sans réponse à A.',
        ])->assertCreated()->json('data.id');

        $this->actingAs($sec1)->postJson('/api/v1/courriers/initier-dg', [
            ...$this->payload($id, $direction->id, $relecteur->id),
            'en_reponse_a_courrier_id' => $a->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('en_reponse_a_courrier_id');
        $this->assertNull(InstructionCourrierDg::query()->findOrFail($id)->consomme_at);
        $this->assertDatabaseCount('missions_documentaires', 0);
    }

    public function test_courrier_historique_initie_par_dg_reste_lisible_sans_instruction(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $courrier = Courrier::factory()->create(['initie_par_dg' => true]);

        $this->actingAs($dg)->getJson("/api/v1/courriers/{$courrier->id}")->assertOk()->assertJsonPath('data.initie_par_dg', true);
        $this->assertDatabaseCount('instructions_courrier_dg', 0);
    }
}
