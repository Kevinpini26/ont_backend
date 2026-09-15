<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;

/**
 * Lot A (tableau généré, non ressaisi) : une demande de stage n'a de sens
 * que traitée par la DFP — si la DG rend un avis favorable sans jamais
 * imputer explicitement (le geste d'imputation reste distinct, voir
 * Lot 3), le courrier est imputé automatiquement à la DFP, sans quoi il
 * ne serait jamais éligible à aucun tableau de répartition. Voir
 * config('stagiaires.imputation_automatique_dfp'),
 * CourrierCircuitService::rendreAvisDg().
 */
class ImputationAutomatiqueDfpTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_un_avis_favorable_sans_imputation_manuelle_impute_automatiquement_a_la_dfp(): void
    {
        Direction::factory()->create(['code' => config('stagiaires.direction_dfp_code')]);
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);

        $courrier = Courrier::factory()->demandeStage()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
        ]);
        $this->marquerDecharge($courrier);

        $reponse = $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/rendre-avis", ['avis_dg' => 'favorable'])
            ->assertOk();

        // Imputé automatiquement -> part en dispatch, pas en rédaction
        // interne (même branchement que pour une imputation manuelle).
        $this->assertSame(CourrierStatut::EN_DISPATCH->value, $reponse->json('data.statut'));

        $imputation = $courrier->fresh()->imputations()->where('est_principale', true)->first();
        $this->assertNotNull($imputation);
        $this->assertSame(config('stagiaires.direction_dfp_code'), $imputation->direction->code);
        $this->assertSame('pour_attribution', $imputation->mention->value);
    }

    public function test_une_imputation_manuelle_deja_posee_nest_pas_remplacee(): void
    {
        $direction = Direction::factory()->create();
        $directionChoisie = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);

        $courrier = Courrier::factory()->demandeStage()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
        ]);
        $this->marquerDecharge($courrier);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/imputer", [
            'imputations' => [
                ['direction_id' => $directionChoisie->id, 'mention' => 'pour_avis', 'est_principale' => true],
            ],
        ])->assertOk();

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/rendre-avis", ['avis_dg' => 'favorable'])->assertOk();

        $imputation = $courrier->fresh()->imputations()->where('est_principale', true)->first();
        $this->assertSame($directionChoisie->id, $imputation->direction_id);
        $this->assertSame('pour_avis', $imputation->mention->value);
    }

    public function test_le_reglage_desactive_neffectue_aucune_imputation_automatique(): void
    {
        config(['stagiaires.imputation_automatique_dfp' => false]);
        Direction::factory()->create(['code' => config('stagiaires.direction_dfp_code')]);
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);

        $courrier = Courrier::factory()->demandeStage()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
        ]);
        $this->marquerDecharge($courrier);

        $reponse = $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/rendre-avis", ['avis_dg' => 'favorable'])
            ->assertOk();

        $this->assertSame(CourrierStatut::PROJET_A_REDIGER->value, $reponse->json('data.statut'));
        $this->assertTrue($courrier->fresh()->imputations()->doesntExist());
    }
}
