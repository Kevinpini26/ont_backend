<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Courrier\Enums\CourrierStatut;
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
            ->assertNotFound();
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

    public function test_un_poste_central_ne_voit_pas_un_courrier_secret_sans_relation_metier(): void
    {
        $courrier = Courrier::factory()->create(['niveau_confidentialite' => 'secret']);
        $reception = $this->agent(Poste::RECEPTION, Direction::factory()->create());

        $this->actingAs($reception)
            ->getJson("/api/v1/courriers/{$courrier->id}")
            ->assertNotFound();
    }

    public function test_lacces_a_un_courrier_confidentiel_est_journalise(): void
    {
        $courrier = Courrier::factory()->create(['niveau_confidentialite' => 'confidentiel']);
        $reception = $this->agent(Poste::RECEPTION, Direction::factory()->create());
        $courrier->transitions()->create([
            'statut' => $courrier->statut,
            'nouveau_statut' => $courrier->statut,
            'destinataire_poste' => Poste::RECEPTION,
            'created_at' => now(),
        ]);

        $this->actingAs($reception)->getJson("/api/v1/courriers/{$courrier->id}")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'courrier.acces_confidentiel',
            'auditable_type' => (new Courrier)->getMorphClass(),
            'auditable_id' => $courrier->id,
            'user_id' => $reception->id,
        ]);
    }

    public function test_lacces_a_un_courrier_ordinaire_nest_pas_journalise(): void
    {
        $courrier = Courrier::factory()->create(['niveau_confidentialite' => 'ordinaire']);
        $reception = $this->agent(Poste::RECEPTION, Direction::factory()->create());
        $courrier->transitions()->create([
            'statut' => $courrier->statut,
            'nouveau_statut' => $courrier->statut,
            'destinataire_poste' => Poste::RECEPTION,
            'created_at' => now(),
        ]);

        $this->actingAs($reception)->getJson("/api/v1/courriers/{$courrier->id}")->assertOk();

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
            'mode_reception' => 'porteur',
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
            'mode_reception' => 'porteur',
            'piece_jointe' => UploadedFile::fake()->create('lettre.pdf', 100, 'application/pdf'),
            'degre_urgence' => 'tres_urgent',
            'niveau_confidentialite' => 'secret',
        ])->assertCreated();

        $response->assertJsonPath('data.degre_urgence', 'tres_urgent')
            ->assertJsonPath('data.niveau_confidentialite', 'secret');
    }

    public function test_une_mission_nominative_naccorde_aucun_acces_aux_autres_assistants_et_expire_au_retour(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $assistant1 = $this->agent(Poste::ASSISTANT_1, $direction);
        $assistant2 = $this->agent(Poste::ASSISTANT_2, $direction);
        $assistantDga = $this->agent(Poste::ASSISTANT_DGA, $direction);
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'niveau_confidentialite' => 'secret',
        ]);

        $missionId = $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/missions", [
            'assistant_id' => $assistant1->id, 'instruction' => 'Analyser ce dossier.',
        ])->assertCreated()->json('data.id');

        $this->actingAs($assistant1)->getJson("/api/v1/courriers/{$courrier->id}")->assertOk();
        $this->actingAs($assistant2)->getJson("/api/v1/courriers/{$courrier->id}")->assertNotFound();
        $this->actingAs($assistantDga)->getJson("/api/v1/courriers/{$courrier->id}")->assertNotFound();

        $this->actingAs($assistant1)->postJson("/api/v1/missions-documentaires/{$missionId}/prendre-en-charge")->assertOk();
        $this->actingAs($assistant1)->postJson("/api/v1/missions-documentaires/{$missionId}/retourner", ['compte_rendu' => 'Analyse terminée.'])->assertOk();
        $this->actingAs($assistant1)->getJson("/api/v1/courriers/{$courrier->id}")->assertNotFound();

        $projetSansMission = Courrier::factory()->create(['statut' => CourrierStatut::PROJET_A_REDIGER, 'niveau_confidentialite' => 'secret']);
        $this->actingAs($assistant1)->getJson("/api/v1/courriers/{$projetSansMission->id}")->assertNotFound();
        $this->actingAs($assistant2)->getJson("/api/v1/courriers/{$projetSansMission->id}")->assertNotFound();
        $this->actingAs($assistantDga)->getJson("/api/v1/courriers/{$projetSansMission->id}")->assertNotFound();
    }
}
