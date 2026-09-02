<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class ConfidentialiteCourrierTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_un_responsable_dont_la_direction_nest_pas_imputee_ne_peut_pas_voir_un_courrier_confidentiel(): void
    {
        $direction = Direction::factory()->create();
        // direction_destination_id = sa propre direction (sinon le scope
        // bloque avant même la policy, voir ImputationCourrierTest) —
        // mais SANS imputation explicite, ce qui doit rester insuffisant
        // pour un courrier confidentiel.
        $courrier = Courrier::factory()->create([
            'direction_destination_id' => $direction->id,
            'niveau_confidentialite' => 'confidentiel',
        ]);
        $responsable = User::factory()->responsableDirection($direction)->create();

        $this->actingAs($responsable)
            ->getJson("/api/v1/courriers/{$courrier->id}")
            ->assertForbidden();
    }

    public function test_une_direction_imputee_peut_voir_un_courrier_confidentiel(): void
    {
        $direction = Direction::factory()->create();
        $courrier = Courrier::factory()->create(['niveau_confidentialite' => 'confidentiel']);
        $courrier->imputations()->create(['direction_id' => $direction->id, 'mention' => 'pour_attribution', 'est_principale' => true]);
        $responsable = User::factory()->responsableDirection($direction)->create();

        $this->actingAs($responsable)
            ->getJson("/api/v1/courriers/{$courrier->id}")
            ->assertOk();
    }

    public function test_un_poste_du_circuit_central_voit_un_courrier_secret_sans_imputation(): void
    {
        $courrier = Courrier::factory()->create(['niveau_confidentialite' => 'secret']);
        $protocole = $this->agent(Poste::PROTOCOLE, Direction::factory()->create());

        $this->actingAs($protocole)
            ->getJson("/api/v1/courriers/{$courrier->id}")
            ->assertOk();
    }

    public function test_lacces_a_un_courrier_confidentiel_est_journalise(): void
    {
        $courrier = Courrier::factory()->create(['niveau_confidentialite' => 'confidentiel']);
        $protocole = $this->agent(Poste::PROTOCOLE, Direction::factory()->create());

        $this->actingAs($protocole)->getJson("/api/v1/courriers/{$courrier->id}")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'courrier.acces_confidentiel',
            'auditable_type' => (new Courrier)->getMorphClass(),
            'auditable_id' => $courrier->id,
            'user_id' => $protocole->id,
        ]);
    }

    public function test_lacces_a_un_courrier_ordinaire_nest_pas_journalise(): void
    {
        $courrier = Courrier::factory()->create(['niveau_confidentialite' => 'ordinaire']);
        $protocole = $this->agent(Poste::PROTOCOLE, Direction::factory()->create());

        $this->actingAs($protocole)->getJson("/api/v1/courriers/{$courrier->id}")->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'courrier.acces_confidentiel']);
    }

    /**
     * degre_urgence n'a plus de défaut applicatif 'normal' depuis le Lot 2
     * (tri par urgence) : "pas encore trié" (null, tant que le Secrétariat
     * 01 n'a pas transmis à la DG) et "trié comme normal" ne sont pas le
     * même état — voir Courrier::urgenceTriee(). niveau_confidentialite,
     * lui, n'est pas concerné par ce changement et garde son défaut.
     */
    public function test_le_degre_durgence_par_defaut_est_non_renseigne_et_le_niveau_de_confidentialite_ordinaire(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $response = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Correspondance',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire',
            'piece_jointe' => UploadedFile::fake()->create('lettre.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $response->assertJsonPath('data.degre_urgence', null)
            ->assertJsonPath('data.niveau_confidentialite', 'ordinaire');
    }

    public function test_un_degre_durgence_et_niveau_de_confidentialite_explicites_sont_pris_en_compte(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $response = $this->actingAs($reception)->post('/api/v1/courriers', [
            'objet' => 'Correspondance sensible',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'expediteur_externe_nom' => 'Partenaire',
            'piece_jointe' => UploadedFile::fake()->create('lettre.pdf', 100, 'application/pdf'),
            'degre_urgence' => 'tres_urgent',
            'niveau_confidentialite' => 'secret',
        ])->assertCreated();

        $response->assertJsonPath('data.degre_urgence', 'tres_urgent')
            ->assertJsonPath('data.niveau_confidentialite', 'secret');
    }
}
