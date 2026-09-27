<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierClassification;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

/**
 * Audit de conformité au cahier des charges — couvre les points non déjà
 * couverts par CircuitCourrierTest/DgInterimTest/EnvoiParDirectionTest/
 * CourrierDirectionScopeTest, voir TESTS_MODULE_COURRIER.md pour le détail
 * scénario par scénario.
 */
class ConformiteCahierDesChargesTest extends CourrierTestCase
{
    use RefreshDatabase;

    // --- Scénario 1 : aucun agent ne dépasse son habilitation ---

    public function test_aucun_poste_ne_peut_agir_hors_de_son_etape_habilitee(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courrierRecu = Courrier::factory()->create([
            'statut' => CourrierStatut::RECU,
            'necessite_avis_dg' => true,
            'created_by' => $reception->id,
        ]);

        // Réception (créateur) ne peut pas enregistrer directement un
        // courrier tout juste reçu — poste non habilité à cette étape.
        $this->actingAs($reception)
            ->postJson("/api/v1/courriers/{$courrierRecu->id}/enregistrer", ['classification' => 'interne'])
            ->assertStatus(403);

        // Secrétariat 02 ne peut pas signer à la place de la DG — poste non
        // habilité à cette étape (encore "recu", pas "en_relecture").
        $this->actingAs($secretariat2)
            ->postJson("/api/v1/courriers/{$courrierRecu->id}/signer")
            ->assertNotFound();

        $courrierRecu->refresh();
        $this->assertSame(CourrierStatut::RECU, $courrierRecu->statut);

        // La Réception ne peut pas rendre l'avis DG à la place de la DG :
        // une fois le courrier réellement à l'étape "en_attente_avis_dg",
        // seuls DG/DGA figurent parmi les postes habilités pour cette étape
        // précise (voir config('courrier.circuit_transitions')).
        $courrierEnAttenteAvis = Courrier::factory()->create([
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
            'necessite_avis_dg' => true,
            'created_by' => $reception->id,
        ]);

        $this->actingAs($reception)
            ->postJson("/api/v1/courriers/{$courrierEnAttenteAvis->id}/rendre-avis", ['avis_dg' => 'favorable'])
            ->assertStatus(403);

        $courrierEnAttenteAvis->refresh();
        $this->assertSame(CourrierStatut::EN_ATTENTE_AVIS_DG, $courrierEnAttenteAvis->statut);
    }

    // --- Scénario 4 : la relecture ne peut pas être auto-validée ---

    public function test_le_redacteur_ne_peut_pas_se_designer_lui_meme_comme_relecteur(): void
    {
        $direction = Direction::factory()->create();
        $redacteur = $this->agent(Poste::ASSISTANT_1, $direction);

        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::PROJET_A_REDIGER,
            'created_by' => $redacteur->id,
        ]);

        $this->actingAs($redacteur)
            ->postJson("/api/v1/courriers/{$courrier->id}/soumettre-projet-reponse", [
                'projet_reponse_contenu' => ['type' => 'doc', 'content' => []],
                'relecteur_id' => $redacteur->id,
            ])
            ->assertStatus(422);

        $courrier->refresh();
        $this->assertSame(CourrierStatut::PROJET_A_REDIGER, $courrier->statut);
        $this->assertNull($courrier->relecteur_id);
    }

    // --- Scénario 5 : classification interne/externe automatique ---

    public function test_un_courrier_entre_deux_directions_ne_peut_pas_etre_enregistre_comme_externe(): void
    {
        $directionA = Direction::factory()->create();
        $directionB = Direction::factory()->create();
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $directionA);

        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::SIGNE,
            'direction_origine_id' => $directionA->id,
            'direction_destination_id' => $directionB->id,
            'expediteur_externe_nom' => null,
            'candidat_nom' => null,
        ]);

        $this->actingAs($secretariat2)
            ->postJson("/api/v1/courriers/{$courrier->id}/enregistrer", [
                'classification' => CourrierClassification::EXTERNE->value,
                'accuse_reception_partenaire' => 'AR-PARTENAIRE-001',
            ])
            ->assertStatus(422);
    }

    public function test_un_courrier_dun_partenaire_externe_ne_peut_pas_etre_enregistre_comme_interne(): void
    {
        $direction = Direction::factory()->create();
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $direction);

        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::SIGNE,
            'direction_origine_id' => null,
            'direction_destination_id' => null,
            'expediteur_externe_nom' => 'Agence Voyage Congo',
            'expediteur_externe_email' => 'contact@agence.cd',
        ]);

        $this->actingAs($secretariat2)
            ->postJson("/api/v1/courriers/{$courrier->id}/enregistrer", [
                'classification' => CourrierClassification::INTERNE->value,
                'note_technique' => 'RAS',
            ])
            ->assertStatus(422);
    }

    public function test_un_courrier_entre_deux_directions_est_automatiquement_classe_interne(): void
    {
        $directionA = Direction::factory()->create();
        $directionB = Direction::factory()->create();
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $directionA);

        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::SIGNE,
            'direction_origine_id' => $directionA->id,
            'direction_destination_id' => $directionB->id,
            'expediteur_externe_nom' => null,
            'candidat_nom' => null,
        ]);
        $this->marquerDecharge($courrier);

        $this->actingAs($secretariat2)
            ->postJson("/api/v1/courriers/{$courrier->id}/enregistrer", [
                'classification' => CourrierClassification::INTERNE->value,
                'note_technique' => 'RAS',
            ])
            ->assertOk()
            ->assertJsonPath('data.classification', CourrierClassification::INTERNE->value);
    }

    /**
     * Régression : un courrier créé par la Réception (mail physique externe)
     * n'a, avec le formulaire actuel, aucun champ "expéditeur externe" saisi
     * — seule l'absence de direction d'origine (jamais forcée pour la
     * Réception, contrairement à une direction, voir
     * CourrierCircuitService::creer()) permet de le distinguer d'un
     * échange interne. Sans cette règle, un tel courrier serait
     * incorrectement classé interne faute de tout autre signal.
     */
    public function test_un_courrier_cree_par_la_reception_sans_expediteur_saisi_est_classe_externe(): void
    {
        $direction = Direction::factory()->create();
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $direction);

        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::SIGNE,
            'direction_origine_id' => null,
            'direction_destination_id' => $direction->id,
            'expediteur_externe_nom' => null,
            'candidat_nom' => null,
        ]);
        $this->marquerDecharge($courrier);

        $this->actingAs($secretariat2)
            ->postJson("/api/v1/courriers/{$courrier->id}/enregistrer", [
                'classification' => CourrierClassification::EXTERNE->value,
                'accuse_reception_partenaire' => 'AR-2026-000001',
            ])
            ->assertOk()
            ->assertJsonPath('data.classification', CourrierClassification::EXTERNE->value);
    }

    public function test_un_courrier_dun_candidat_de_stage_est_automatiquement_classe_externe(): void
    {
        $direction = Direction::factory()->create();
        $secretariat2 = $this->agent(Poste::SECRETARIAT_2, $direction);

        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::SIGNE,
            'type' => CourrierType::DEMANDE_STAGE,
            'direction_origine_id' => null,
            'direction_destination_id' => null,
            'candidat_nom' => 'Jean Kabila',
            'candidat_contact' => 'jean@example.com',
            'expediteur_externe_nom' => null,
        ]);
        $this->marquerDecharge($courrier);

        $this->actingAs($secretariat2)
            ->postJson("/api/v1/courriers/{$courrier->id}/enregistrer", [
                'classification' => CourrierClassification::EXTERNE->value,
                'accuse_reception_partenaire' => 'AR-2026-000001',
            ])
            ->assertOk()
            ->assertJsonPath('data.classification', CourrierClassification::EXTERNE->value);
    }

    // --- Scénario 6 : statut public toujours simplifié, y compris pour un courrier externe (pas seulement une demande de stage) ---

    public function test_le_suivi_public_dun_courrier_externe_najamais_le_statut_interne_du_circuit(): void
    {
        Storage::fake('local');

        $numero = $this->post('/api/v1/public/courriers-externes', [
            'expediteur_externe_nom' => 'Agence Voyage Congo SARL',
            'expediteur_externe_email' => 'contact@agence-exemple.cd',
            'objet' => 'Proposition de partenariat',
            'piece_jointe' => UploadedFile::fake()->create('courrier.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('numero_accuse_reception');

        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);
        $secretariat1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $courrier = Courrier::withoutGlobalScopes()->where('numero_accuse_reception', $numero)->firstOrFail();
        $this->actingAs($reception)->postJson("/api/v1/courriers/{$courrier->id}/enregistrer", [
            'classification' => CourrierClassification::EXTERNE->value,
            'accuse_reception_partenaire' => 'Dépôt public reçu',
        ])->assertOk();
        $this->actingAs($reception)->postJson("/api/v1/courriers/{$courrier->id}/transmettre-sec1")->assertOk();
        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")->assertOk();
        $this->actingAs($secretariat1)->postJson("/api/v1/courriers/{$courrier->id}/transmettre-tri")->assertOk();

        $response = $this->postJson('/api/v1/public/dossiers/verifier', [
            'numero' => $numero,
            'nom' => 'Agence Voyage Congo SARL',
        ])->assertOk();

        $this->assertArrayNotHasKey('statut', $response->json('data'));
        $this->assertArrayNotHasKey('statut_label', $response->json('data'));
        $this->assertNotNull($response->json('data.statut_simplifie'));
        // Aucune étape interne du circuit (tri, Protocole, DGA, DG...) ne
        // doit fuiter dans la valeur simplifiée elle-même.
        $this->assertStringNotContainsString('tri', strtolower($response->json('data.statut_simplifie')));
    }

    // --- Scénario 7 : numérisation obligatoire à la Réception ---

    public function test_la_reception_ne_peut_pas_enregistrer_un_courrier_physique_sans_document_scanne(): void
    {
        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $this->actingAs($reception)
            ->postJson('/api/v1/courriers', [
                'objet' => 'Courrier physique reçu au guichet',
                'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
                'expediteur_externe_nom' => 'Partenaire externe',
                'mode_reception' => 'porteur',
                'direction_destination_id' => $direction->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['piece_jointe']);
    }

    public function test_la_reception_peut_enregistrer_un_courrier_physique_avec_document_scanne(): void
    {
        Storage::fake('local');

        $direction = Direction::factory()->create();
        $reception = $this->agent(Poste::RECEPTION, $direction);

        $this->actingAs($reception)
            ->post('/api/v1/courriers', [
                'objet' => 'Courrier physique reçu au guichet',
                'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
                'expediteur_externe_nom' => 'Partenaire externe',
                'mode_reception' => 'porteur',
                'direction_destination_id' => $direction->id,
                'piece_jointe' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
            ])
            ->assertCreated();
    }

    public function test_une_direction_ne_peut_plus_ouvrir_un_circuit_sans_passage_par_la_reception(): void
    {
        // Une direction n'est plus un point d'entrée du courrier entrant,
        // avec ou sans pièce jointe.
        $directionEmettrice = Direction::factory()->create();
        $directionDestinataire = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($directionEmettrice)->create();

        $this->actingAs($responsable)->postJson('/api/v1/courriers', [
            'objet' => 'Demande de collaboration',
            'type' => CourrierType::CORRESPONDANCE_GENERALE->value,
            'direction_destination_id' => $directionDestinataire->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('courriers', ['objet' => 'Demande de collaboration']);
    }

    // --- Scénario 8 : portée par direction, y compris en accès direct par id ---

    public function test_un_responsable_ne_peut_pas_acceder_par_id_a_un_courrier_dune_autre_direction(): void
    {
        $directionA = Direction::factory()->create();
        $directionB = Direction::factory()->create();
        $directionC = Direction::factory()->create();

        $courrier = Courrier::factory()->create([
            'direction_origine_id' => $directionA->id,
            'direction_destination_id' => $directionB->id,
        ]);

        $responsableC = User::factory()->responsableDirection($directionC)->create();

        $this->actingAs($responsableC)
            ->getJson("/api/v1/courriers/{$courrier->id}")
            ->assertStatus(404);
    }

    public function test_un_responsable_ne_peut_pas_telecharger_la_piece_jointe_dun_courrier_dune_autre_direction(): void
    {
        Storage::fake('local');

        $directionA = Direction::factory()->create();
        $directionB = Direction::factory()->create();
        $directionC = Direction::factory()->create();

        Storage::disk('local')->put('courriers/piece-test.pdf', '%PDF-1.7 contenu de test');

        $courrier = Courrier::factory()->create([
            'direction_origine_id' => $directionA->id,
            'direction_destination_id' => $directionB->id,
            'piece_jointe_chemin' => 'courriers/piece-test.pdf',
        ]);

        $responsableC = User::factory()->responsableDirection($directionC)->create();

        // Circuit court entre deux AUTRES directions (A et B) : même un
        // responsable d'une troisième direction, sans lien avec ce
        // courrier, ne doit pas pouvoir en récupérer la pièce jointe par
        // appel direct.
        $this->actingAs($responsableC)
            ->get("/api/v1/courriers/{$courrier->id}/piece-jointe")
            ->assertStatus(404);
    }
}
