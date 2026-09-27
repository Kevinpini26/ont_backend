<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class CourrierSortantTest extends CourrierTestCase
{
    use RefreshDatabase;

    private array $projetVide;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projetVide = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Réponse.']]]]];
    }

    public function test_le_responsable_de_la_direction_concernee_peut_initier_une_reponse_sortante(): void
    {
        $direction = Direction::factory()->create();
        $original = Courrier::factory()->create(['direction_origine_id' => $direction->id]);
        $this->marquerDecharge($original);
        $responsable = User::factory()->responsableDirection($direction)->create();
        $relecteur = User::factory()->responsableDirection($direction)->create();

        $response = $this->actingAs($responsable)->postJson("/api/v1/courriers/{$original->id}/initier-reponse", [
            'objet' => 'Réponse à la demande',
            'projet_reponse_contenu' => $this->projetVide,
            'relecteur_id' => $relecteur->id,
        ])->assertCreated();

        $response->assertJsonPath('data.sens', 'sortant')
            ->assertJsonPath('data.en_reponse_a_courrier_id', $original->id)
            ->assertJsonPath('data.statut', CourrierStatut::EN_RELECTURE->value);
    }

    public function test_secretariat_1_ne_peut_plus_contourner_la_mission_dg_pour_initier_une_reponse(): void
    {
        $direction = Direction::factory()->create();
        $original = Courrier::factory()->create(['direction_origine_id' => $direction->id]);
        $this->marquerDecharge($original);
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_1, $direction);

        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$original->id}/initier-reponse", [
            'projet_reponse_contenu' => $this->projetVide,
            'relecteur_id' => $relecteur->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('courrier');
    }

    /**
     * Une direction imputée seulement "en copie" (voir lot 1) voit le
     * courrier (scope) mais n'est ni origine ni destinataire — le même
     * principe "en copie = lecture, jamais action" s'applique à l'envoi
     * d'une réponse, pas seulement à la transmission dans le circuit.
     */
    public function test_une_direction_seulement_en_copie_ne_peut_pas_repondre(): void
    {
        $direction = Direction::factory()->create();
        $directionEnCopie = Direction::factory()->create();
        $original = Courrier::factory()->create(['direction_origine_id' => $direction->id]);
        $original->imputations()->create(['direction_id' => $directionEnCopie->id, 'mention' => 'pour_information', 'est_principale' => false]);
        $this->marquerDecharge($original);
        $responsable = User::factory()->responsableDirection($directionEnCopie)->create();
        $relecteur = User::factory()->responsableDirection($directionEnCopie)->create();

        // Refusé par le service (vérification précise de la direction
        // concernée), pas par la policy (filtre grossier "responsable de
        // direction") — d'où 422 (TransitionNonAutoriseeException), pas 403.
        $this->actingAs($responsable)->postJson("/api/v1/courriers/{$original->id}/initier-reponse", [
            'projet_reponse_contenu' => $this->projetVide,
            'relecteur_id' => $relecteur->id,
        ])->assertUnprocessable();
    }

    public function test_le_relecteur_ne_peut_pas_etre_le_redacteur(): void
    {
        $direction = Direction::factory()->create();
        $original = Courrier::factory()->create(['direction_origine_id' => $direction->id]);
        $this->marquerDecharge($original);
        $responsable = User::factory()->responsableDirection($direction)->create();

        $this->actingAs($responsable)->postJson("/api/v1/courriers/{$original->id}/initier-reponse", [
            'projet_reponse_contenu' => $this->projetVide,
            'relecteur_id' => $responsable->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('relecteur_id');
    }

    private function creerReponseEnRelecture(Direction $direction, User $relecteur): Courrier
    {
        $original = Courrier::factory()->create(['direction_origine_id' => $direction->id]);
        $this->marquerDecharge($original);
        $responsable = User::factory()->responsableDirection($direction)->create();

        $id = $this->actingAs($responsable)->postJson("/api/v1/courriers/{$original->id}/initier-reponse", [
            'projet_reponse_contenu' => $this->projetVide,
            'relecteur_id' => $relecteur->id,
        ])->json('data.id');

        return Courrier::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_le_cycle_complet_relecture_signature_envoi_fonctionne(): void
    {
        $direction = Direction::factory()->create();
        $relecteur = User::factory()->responsableDirection($direction)->create();
        $dg = $this->agent(Poste::DG, $direction);
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $direction);

        $reponse = $this->creerReponseEnRelecture($direction, $relecteur);

        $this->actingAs($relecteur)->postJson("/api/v1/courriers/{$reponse->id}/accuser-reception")->assertOk();
        $this->actingAs($relecteur)->postJson("/api/v1/courriers/{$reponse->id}/valider-relecture", [])->assertOk();
        $signature = $this->actingAs($dg)->postJson("/api/v1/courriers/{$reponse->id}/signer")->assertOk();
        $signature->assertJsonPath('data.statut', CourrierStatut::SIGNE->value);
        // Le numéro de départ est attribué à la signature, pas à l'envoi
        // effectif (voir CourrierCircuitService::signer()).
        $this->assertNotNull($signature->json('data.numero_depart'));

        $this->actingAs($secretariat2)->postJson("/api/v1/courriers/{$reponse->id}/accuser-reception")->assertOk();
        $envoi = $this->actingAs($secretariat2)->postJson("/api/v1/courriers/{$reponse->id}/envoyer", [
            'destinataire_externe_nom' => 'Ministère du Tourisme',
            'destinataire_externe_email' => 'contact@ministere.cd',
            'mode_expedition' => 'courriel',
        ])->assertOk();

        $envoi->assertJsonPath('data.statut', CourrierStatut::ENVOYE->value)
            ->assertJsonPath('data.destinataire_externe_nom', 'Ministère du Tourisme')
            ->assertJsonPath('data.numero_depart', $signature->json('data.numero_depart'));
        $this->assertNotNull($envoi->json('data.numero_depart'));
        $this->assertNotNull($envoi->json('data.date_envoi'));
    }

    public function test_seul_secretariat_2_peut_envoyer_un_courrier_signe(): void
    {
        $direction = Direction::factory()->create();
        $relecteur = User::factory()->responsableDirection($direction)->create();
        $dg = $this->agent(Poste::DG, $direction);
        $autrePoste = $this->agent(Poste::PROTOCOLE, $direction);

        $reponse = $this->creerReponseEnRelecture($direction, $relecteur);
        $this->actingAs($relecteur)->postJson("/api/v1/courriers/{$reponse->id}/accuser-reception")->assertOk();
        $this->actingAs($relecteur)->postJson("/api/v1/courriers/{$reponse->id}/valider-relecture", [])->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$reponse->id}/signer")->assertOk();

        $this->actingAs($autrePoste)->postJson("/api/v1/courriers/{$reponse->id}/accuser-reception");
        $this->actingAs($autrePoste)->postJson("/api/v1/courriers/{$reponse->id}/envoyer", [
            'destinataire_externe_nom' => 'Partenaire',
            'mode_expedition' => 'courriel',
        ])->assertNotFound();
    }

    public function test_la_remise_ne_peut_etre_enregistree_quapres_lenvoi(): void
    {
        $direction = Direction::factory()->create();
        $relecteur = User::factory()->responsableDirection($direction)->create();
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $direction);

        $reponse = $this->creerReponseEnRelecture($direction, $relecteur);
        $reponse->forceFill(['created_by' => $secretariat2->id])->save();

        $this->actingAs($secretariat2)->postJson("/api/v1/courriers/{$reponse->id}/enregistrer-remise", [
            'remis_a' => 'Jean Kabila',
            'mode_remise' => 'porteur_avec_decharge',
        ])->assertUnprocessable();
    }

    public function test_la_remise_est_enregistree_apres_lenvoi(): void
    {
        $direction = Direction::factory()->create();
        $relecteur = User::factory()->responsableDirection($direction)->create();
        $dg = $this->agent(Poste::DG, $direction);
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $direction);

        $reponse = $this->creerReponseEnRelecture($direction, $relecteur);
        $this->actingAs($relecteur)->postJson("/api/v1/courriers/{$reponse->id}/accuser-reception")->assertOk();
        $this->actingAs($relecteur)->postJson("/api/v1/courriers/{$reponse->id}/valider-relecture", [])->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$reponse->id}/signer")->assertOk();
        $this->actingAs($secretariat2)->postJson("/api/v1/courriers/{$reponse->id}/accuser-reception")->assertOk();
        $this->actingAs($secretariat2)->postJson("/api/v1/courriers/{$reponse->id}/envoyer", [
            'destinataire_externe_nom' => 'Partenaire',
            'mode_expedition' => 'poste',
        ])->assertOk();

        $response = $this->actingAs($secretariat2)->postJson("/api/v1/courriers/{$reponse->id}/enregistrer-remise", [
            'remis_a' => 'Jean Kabila',
            'mode_remise' => 'porteur_avec_decharge',
        ])->assertOk();

        $response->assertJsonPath('data.remis_a', 'Jean Kabila');
        $this->assertNotNull($response->json('data.remis_le'));
    }

    public function test_le_fil_de_correspondance_apparait_sur_loriginal(): void
    {
        $direction = Direction::factory()->create();
        $relecteur = User::factory()->responsableDirection($direction)->create();

        $reponse = $this->creerReponseEnRelecture($direction, $relecteur);
        $original = $reponse->courrierOrigine;

        $responsable = User::factory()->responsableDirection($direction)->create();

        $this->actingAs($responsable)
            ->getJson("/api/v1/courriers/{$original->id}")
            ->assertOk()
            ->assertJsonPath('data.correspondance.0.id', $reponse->id);
    }

    public function test_une_direction_peut_initier_un_courrier_sortant_proactif_sans_original(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $relecteur = User::factory()->responsableDirection($direction)->create();

        $response = $this->actingAs($responsable)->postJson('/api/v1/courriers/initier-sortant', [
            'objet' => 'Courrier à un partenaire',
            'projet_reponse_contenu' => $this->projetVide,
            'relecteur_id' => $relecteur->id,
        ])->assertCreated();

        $response->assertJsonPath('data.sens', 'sortant')
            ->assertJsonPath('data.en_reponse_a_courrier_id', null)
            ->assertJsonPath('data.statut', CourrierStatut::EN_RELECTURE->value);
    }

    public function test_un_agent_du_circuit_courrier_sans_poste_secretariat_1_ne_peut_pas_initier_un_sortant(): void
    {
        $direction = Direction::factory()->create();
        $protocole = $this->agent(Poste::PROTOCOLE, $direction);
        $relecteur = User::factory()->responsableDirection($direction)->create();

        $this->actingAs($protocole)->postJson('/api/v1/courriers/initier-sortant', [
            'objet' => 'Courrier à un partenaire',
            'projet_reponse_contenu' => $this->projetVide,
            'relecteur_id' => $relecteur->id,
        ])->assertForbidden();
    }
}
