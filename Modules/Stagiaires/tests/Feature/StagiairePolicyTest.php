<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Models\Stagiaire;
use Tests\TestCase;

/**
 * Lecture des dossiers stagiaires (StagiairePolicy::viewAny()/view()) :
 * avant cette correction, les deux méthodes retournaient
 * inconditionnellement true, et un agent du circuit courrier central (ex.
 * Réception) voyait tous les stagiaires de toutes les directions via le
 * contournement du DirectionScope.
 */
class StagiairePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function demandeDeStageAuStatut(CourrierStatut $statut): Courrier
    {
        return Courrier::factory()->demandeStage()->create(['statut' => $statut]);
    }

    public function test_la_dfp_et_ladministrateur_voient_nimporte_quel_stagiaire(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $administrateur = User::factory()->administrateur()->create();
        $stagiaire = Stagiaire::factory()->create();

        $this->actingAs($dfp)->getJson("/api/v1/stagiaires/{$stagiaire->id}")->assertOk();
        $this->actingAs($administrateur)->getJson("/api/v1/stagiaires/{$stagiaire->id}")->assertOk();
    }

    public function test_un_responsable_de_direction_ne_voit_que_les_stagiaires_de_sa_propre_direction(): void
    {
        $saDirection = Direction::factory()->create();
        $autreDirection = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($saDirection)->create();

        $stagiaireDeSaDirection = Stagiaire::factory()->create(['direction_id' => $saDirection->id]);
        $stagiaireDuneAutreDirection = Stagiaire::factory()->create(['direction_id' => $autreDirection->id]);

        $this->actingAs($responsable)->getJson("/api/v1/stagiaires/{$stagiaireDeSaDirection->id}")->assertOk();

        // Le DirectionScope global (première barrière) exclut déjà ce
        // stagiaire de la résolution de route elle-même : 404, pas 403
        // (StagiairePolicy::view() n'est même pas atteinte pour ce cas —
        // elle reste une deuxième barrière, utile surtout au circuit
        // courrier qui contourne ce scope).
        $this->actingAs($responsable)->getJson("/api/v1/stagiaires/{$stagiaireDuneAutreDirection->id}")->assertNotFound();
    }

    public function test_le_secretariat_01_voit_un_stagiaire_tant_que_le_courrier_dorigine_est_dans_sa_file(): void
    {
        $secretariat1 = User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_1)->create();
        $courrier = $this->demandeDeStageAuStatut(CourrierStatut::RECU);
        $stagiaire = Stagiaire::factory()->create(['courrier_id' => $courrier->id]);

        $this->actingAs($secretariat1)->getJson("/api/v1/stagiaires/{$stagiaire->id}")->assertOk();
    }

    /**
     * Cas concret relevé par l'audit : la Réception n'apparaît dans aucune
     * étape du circuit courrier habilitée à agir sur une demande de stage
     * (elle ne fait que la créer) — elle ne doit donc jamais voir la fiche
     * stagiaire correspondante, à aucun moment.
     */
    public function test_la_reception_ne_voit_jamais_un_stagiaire_meme_au_statut_recu(): void
    {
        $reception = User::factory()->agentCircuitCourrier(Poste::RECEPTION)->create();
        $courrier = $this->demandeDeStageAuStatut(CourrierStatut::RECU);
        $stagiaire = Stagiaire::factory()->create(['courrier_id' => $courrier->id]);

        $this->actingAs($reception)->getJson("/api/v1/stagiaires/{$stagiaire->id}")->assertForbidden();
    }

    public function test_le_secretariat_01_perd_la_visibilite_une_fois_le_courrier_transmis_a_letape_suivante(): void
    {
        $secretariat1 = User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_1)->create();
        $courrier = $this->demandeDeStageAuStatut(CourrierStatut::EN_ATTENTE_AVIS_DG);
        $stagiaire = Stagiaire::factory()->create(['courrier_id' => $courrier->id]);

        $this->actingAs($secretariat1)->getJson("/api/v1/stagiaires/{$stagiaire->id}")->assertForbidden();
    }

    public function test_personne_du_circuit_courrier_ne_voit_plus_le_stagiaire_une_fois_le_courrier_enregistre(): void
    {
        $secretariat2 = User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_2)->create();
        $courrier = $this->demandeDeStageAuStatut(CourrierStatut::ENREGISTRE);
        $stagiaire = Stagiaire::factory()->create(['courrier_id' => $courrier->id]);

        $this->actingAs($secretariat2)->getJson("/api/v1/stagiaires/{$stagiaire->id}")->assertForbidden();
    }

    public function test_la_liste_des_stagiaires_est_filtree_pour_un_agent_du_circuit_courrier(): void
    {
        $secretariat1 = User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_1)->create();

        $courrierVisible = $this->demandeDeStageAuStatut(CourrierStatut::RECU);
        $stagiaireVisible = Stagiaire::factory()->create(['courrier_id' => $courrierVisible->id]);

        $courrierInvisible = $this->demandeDeStageAuStatut(CourrierStatut::ENREGISTRE);
        Stagiaire::factory()->create(['courrier_id' => $courrierInvisible->id]);

        $response = $this->actingAs($secretariat1)->getJson('/api/v1/stagiaires')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($stagiaireVisible->id));
        $this->assertCount(1, $ids);
    }
}
