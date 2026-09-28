<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class ImputationCourrierTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_une_seule_imputation_principale_est_autorisee_par_courrier(): void
    {
        $courrier = Courrier::factory()->create();
        $directionA = Direction::factory()->create();
        $directionB = Direction::factory()->create();

        $courrier->imputations()->create([
            'direction_id' => $directionA->id,
            'mention' => 'pour_attribution',
            'est_principale' => true,
        ]);

        $this->expectException(QueryException::class);

        $courrier->imputations()->create([
            'direction_id' => $directionB->id,
            'mention' => 'pour_attribution',
            'est_principale' => true,
        ]);
    }

    public function test_plusieurs_imputations_en_copie_sont_autorisees_sur_le_meme_courrier(): void
    {
        $courrier = Courrier::factory()->create();
        $directionA = Direction::factory()->create();
        $directionB = Direction::factory()->create();

        $courrier->imputations()->create(['direction_id' => $directionA->id, 'mention' => 'pour_information', 'est_principale' => false]);
        $courrier->imputations()->create(['direction_id' => $directionB->id, 'mention' => 'pour_information', 'est_principale' => false]);

        $this->assertCount(2, $courrier->imputations()->get());
    }

    /**
     * Une direction en copie voit le courrier (voir
     * CourrierDirectionScopeTest) mais ne peut jamais le faire avancer
     * dans le circuit — la policy transmettre() ne compare qu'au poste
     * d'un agent du circuit central, jamais atteignable par un responsable
     * de direction, imputation ou non.
     */
    public function test_une_direction_en_copie_ne_peut_pas_transmettre_le_courrier(): void
    {
        $directionOrigine = Direction::factory()->create();
        $directionDestination = Direction::factory()->create();
        $directionEnCopie = Direction::factory()->create();

        $courrier = Courrier::factory()->create([
            'direction_origine_id' => $directionOrigine->id,
            'direction_destination_id' => $directionDestination->id,
        ]);
        $courrier->imputations()->create([
            'direction_id' => $directionEnCopie->id,
            'mention' => 'pour_information',
            'est_principale' => false,
        ]);
        $this->marquerDecharge($courrier);

        $responsableEnCopie = User::factory()->responsableDirection($directionEnCopie)->create();

        $this->actingAs($responsableEnCopie)
            ->postJson("/api/v1/courriers/{$courrier->id}/transmettre-tri")
            ->assertForbidden();
    }

    public function test_nouvelle_imputation_est_refusee_au_profit_du_dispatch(): void
    {
        $courrier = Courrier::factory()->create();
        $principale = Direction::factory()->create();
        $copie = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, Direction::factory()->create());

        $reponse = $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/imputer", [
            'imputations' => [
                ['direction_id' => $principale->id, 'mention' => 'pour_attribution', 'est_principale' => true],
                ['direction_id' => $copie->id, 'mention' => 'pour_information', 'est_principale' => false],
            ],
        ]);

        $reponse->assertUnprocessable()->assertJsonValidationErrors('imputations');
        $this->assertCount(0, $courrier->fresh()->imputations);
    }

    public function test_imputer_sans_direction_principale_est_refuse(): void
    {
        $courrier = Courrier::factory()->create();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, Direction::factory()->create());

        $this->actingAs($dg)
            ->postJson("/api/v1/courriers/{$courrier->id}/imputer", [
                'imputations' => [
                    ['direction_id' => $direction->id, 'mention' => 'pour_information', 'est_principale' => false],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('imputations');
    }

    public function test_imputation_historique_ne_peut_pas_etre_remplacee(): void
    {
        $courrier = Courrier::factory()->create();
        $directionInitiale = Direction::factory()->create();
        $nouvelleDirection = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, Direction::factory()->create());

        $payload = fn ($directionId) => [
            'imputations' => [
                ['direction_id' => $directionId, 'mention' => 'pour_attribution', 'est_principale' => true],
            ],
        ];

        $historique = $courrier->imputations()->create([
            'direction_id' => $directionInitiale->id, 'mention' => 'pour_attribution',
            'est_principale' => true, 'imputee_par_id' => $dg->id,
        ]);
        $historique->refresh();
        $avant = $historique->getRawOriginal();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/imputer", $payload($nouvelleDirection->id))
            ->assertUnprocessable()->assertJsonValidationErrors('imputations');

        $imputations = $courrier->fresh()->imputations;
        $this->assertCount(1, $imputations);
        $this->assertSame($directionInitiale->id, $imputations->first()->direction_id);
        $this->assertSame($avant, $historique->fresh()->getRawOriginal());
    }

    public function test_un_responsable_de_direction_ne_peut_pas_imputer_un_courrier(): void
    {
        $directionResponsable = Direction::factory()->create();
        // direction_destination_id = sa propre direction : sinon le
        // CourrierDirectionScope filtre le courrier avant même d'atteindre
        // la policy, et le test obtiendrait 404 plutôt que 403.
        $courrier = Courrier::factory()->create(['direction_destination_id' => $directionResponsable->id]);
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($directionResponsable)->create();

        $this->actingAs($responsable)
            ->postJson("/api/v1/courriers/{$courrier->id}/imputer", [
                'imputations' => [
                    ['direction_id' => $direction->id, 'mention' => 'pour_attribution', 'est_principale' => true],
                ],
            ])
            ->assertForbidden();
    }
}
