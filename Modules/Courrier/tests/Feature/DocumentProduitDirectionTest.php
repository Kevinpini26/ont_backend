<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;

class DocumentProduitDirectionTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_retour_a_preparer_cree_un_nouveau_courrier_dans_le_meme_dossier_et_une_relation_unique(): void
    {
        [$source, $traitement, $secretariat] = $this->traitementTermine('retour_a_preparer');
        $reponse = $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/document-produit", [
            'objet' => 'Réponse préparée par la direction', 'contenu' => $this->contenu('Brouillon'),
        ])->assertCreated()->assertJsonPath('data.statut', 'brouillon');
        $produit = DocumentProduitDirection::findOrFail($reponse->json('data.id'));
        $this->assertNotSame($source->id, $produit->courrier_id);
        $this->assertSame($source->dossier_id, $produit->courrier->dossier_id);
        $this->assertNull($produit->courrier->reference_documentaire);
        $this->assertNull($produit->courrier->numero_enregistrement);
        $this->assertNull($produit->courrier->numero_depart);
        $this->assertDatabaseHas('document_relations', ['document_source_id' => $produit->courrier_id, 'document_cible_id' => $source->id, 'type_relation' => 'produit_a_partir_de']);
        $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/document-produit", ['objet' => 'Doublon', 'contenu' => $this->contenu('x')])->assertUnprocessable();
        $this->assertDatabaseCount('courriers', 2);
        $this->assertDatabaseCount('dossiers', 1);
    }

    public function test_creation_est_refusee_avant_decision_compatible_et_acces_horizontal_interdit(): void
    {
        [$source, $traitement, $secretariat] = $this->traitementTermine('traitement_termine');
        $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/document-produit", ['objet' => 'Non', 'contenu' => $this->contenu('x')])->assertUnprocessable();
        $autre = Direction::factory()->create();
        $autreSecretariat = User::factory()->secretariatDirection($autre)->create();
        $traitement->update(['decision_directeur' => 'retour_a_preparer']);
        $this->actingAs($autreSecretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/document-produit", ['objet' => 'Non', 'contenu' => $this->contenu('x')])->assertForbidden();
        $this->assertDatabaseCount('documents_produits_direction', 0);
    }

    public function test_index_est_limite_aux_roles_metier_et_a_la_direction(): void
    {
        [$source, $traitement, $secretariat, $directeur, $reception] = $this->traitementTermine('retour_a_preparer');
        $id = $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/document-produit", [
            'objet' => 'Document DMC', 'contenu' => $this->contenu('Contenu'),
        ])->assertCreated()->json('data.id');

        $this->actingAs($secretariat)->getJson('/api/v1/documents-produits-direction')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->actingAs($directeur)->getJson('/api/v1/documents-produits-direction')->assertOk()->assertJsonPath('data.0.id', $id);

        $autreDirection = Direction::factory()->create();
        $this->actingAs(User::factory()->secretariatDirection($autreDirection)->create())->getJson('/api/v1/documents-produits-direction')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs(User::factory()->directeurDirection($autreDirection)->create())->getJson('/api/v1/documents-produits-direction')->assertOk()->assertJsonCount(0, 'data');
        $generique = User::factory()->create(['role' => UserRole::AGENT_DFP, 'direction_id' => $secretariat->direction_id]);
        $this->actingAs($generique)->getJson('/api/v1/documents-produits-direction')->assertForbidden();
        $this->actingAs($reception)->getJson('/api/v1/documents-produits-direction')->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame($source->dossier_id, DocumentProduitDirection::findOrFail($id)->courrier->dossier_id);
    }

    public function test_soumission_correction_validation_reference_et_retour_reception_vers_sec1(): void
    {
        [$source, $traitement, $secretariat, $directeur, $reception] = $this->traitementTermine('retour_a_preparer');
        $admin = User::factory()->administrateur()->create();
        $id = $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/document-produit", ['objet' => 'Retour officiel', 'contenu' => $this->contenu('Version 1')])->assertCreated()->json('data.id');
        $this->actingAs($secretariat)->postJson("/api/v1/documents-produits-direction/{$id}/soumettre")->assertOk()->assertJsonPath('data.statut', 'soumis_directeur');
        $this->actingAs($admin)->postJson("/api/v1/documents-produits-direction/{$id}/valider")->assertForbidden();
        $this->actingAs($directeur)->postJson("/api/v1/documents-produits-direction/{$id}/demander-correction", ['motif' => 'Préciser la conclusion.'])->assertOk()->assertJsonPath('data.statut', 'a_corriger');
        $this->actingAs($secretariat)->postJson("/api/v1/documents-produits-direction/{$id}/soumettre", ['contenu' => $this->contenu('Version corrigée')])->assertOk();
        $validation = $this->actingAs($directeur)->postJson("/api/v1/documents-produits-direction/{$id}/valider")->assertOk()->assertJsonPath('data.statut', 'valide');
        $reference = $validation->json('data.courrier.reference_documentaire');
        $this->assertMatchesRegularExpression('/^001\/ONT\/.+\/\d{4}$/', $reference);
        $produit = DocumentProduitDirection::findOrFail($id);
        $this->actingAs($secretariat)->postJson("/api/v1/documents-produits-direction/{$id}/soumettre", ['contenu' => $this->contenu('Mutation')])->assertForbidden();
        $this->actingAs($secretariat)->postJson("/api/v1/documents-produits-direction/{$id}/transmettre-reception")->assertOk()->assertJsonPath('data.statut', 'transmis_reception');
        $courrierId = $produit->courrier_id;
        $this->actingAs($reception)->postJson("/api/v1/documents-produits-direction/{$id}/recevoir")->assertOk()->assertJsonPath('data.statut', 'entre_circuit');
        $courrier = Courrier::withoutGlobalScopes()->findOrFail($courrierId);
        $this->assertSame(CourrierStatut::RECU, $courrier->statut);
        $this->assertNotNull($courrier->numero_enregistrement);
        $this->assertNotSame($reference, $courrier->numero_enregistrement);
        $this->assertSame($reference, $courrier->reference_documentaire);
        $this->assertSame($source->dossier_id, $courrier->dossier_id);
        $this->assertDatabaseCount('courriers', 2);
        $this->assertDatabaseHas('courrier_transitions', ['courrier_id' => $courrierId, 'nouveau_statut' => 'recu', 'destinataire_poste' => 'secretariat_1']);
        $this->actingAs($reception)->postJson("/api/v1/documents-produits-direction/{$id}/recevoir")->assertForbidden();
    }

    /** @return array{Courrier, TraitementDirection, User, User, User} */
    private function traitementTermine(string $decision): array
    {
        $centrale = Direction::factory()->create();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $centrale);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $centrale);
        $reception = $this->agent(Poste::RECEPTION, $centrale);
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        $directeur = User::factory()->directeurDirection($direction)->create();
        $source = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$source->id}/dispatchs", ['destinations' => [['type' => 'direction', 'direction_id' => $direction->id, 'instruction' => 'Traiter']]])->assertOk();
        $dispatch = $source->dispatchs()->firstOrFail();
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertOk();
        $this->actingAs($secretariat)->postJson("/api/v1/dispatchs/{$dispatch->id}/accuser-reception")->assertOk();
        $traitement = TraitementDirection::query()->firstOrFail();
        $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/transmettre-directeur")->assertOk();
        $this->actingAs($directeur)->postJson("/api/v1/traitements-direction/{$traitement->id}/prendre-en-charge")->assertOk();
        $this->actingAs($directeur)->postJson("/api/v1/traitements-direction/{$traitement->id}/decision", ['decision' => $decision])->assertOk();

        return [$source, $traitement->fresh(), $secretariat, $directeur, $reception];
    }

    private function contenu(string $texte): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $texte]]]]];
    }
}
