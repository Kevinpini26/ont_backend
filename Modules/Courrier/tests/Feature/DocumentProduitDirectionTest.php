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
use Carbon\Carbon;

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

        $this->assertSame(
            $source->dossier_id,
            Courrier::withoutGlobalScopes()->findOrFail(DocumentProduitDirection::findOrFail($id)->courrier_id)->dossier_id,
        );
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
        $this->assertSame(sprintf('001/ABC/%s/ONT/%d', $traitement->direction->code, now()->year), $reference);
        $produit = DocumentProduitDirection::findOrFail($id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'courrier.reference_documentaire_interne_attribuee',
            'auditable_id' => $produit->courrier_id,
            'user_id' => $directeur->id,
        ]);
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
    public function test_le_directeur_sans_initiales_ne_peut_pas_attribuer_de_reference(): void
    {
        $direction = Direction::query()->where('code', 'DMC')->firstOrFail();
        [$source, $traitement, $secretariat, $directeur] = $this->traitementTermine('retour_a_preparer', $direction, null);
        $id = $this->creerEtSoumettreDocument($traitement, $secretariat);

        $this->actingAs($directeur)->postJson("/api/v1/documents-produits-direction/{$id}/valider")
            ->assertUnprocessable()->assertJsonValidationErrors('initiales_reference');

        $document = DocumentProduitDirection::findOrFail($id);
        $this->assertSame('soumis_directeur', $document->statut->value);
        $this->assertNull($document->courrier->reference_documentaire);
    }

    public function test_la_reference_reste_celle_de_la_direction_productrice_meme_si_le_destinataire_est_la_dg(): void
    {
        $direction = Direction::query()->where('code', 'DMC')->firstOrFail();
        [, $traitement, $secretariat, $directeur] = $this->traitementTermine('retour_a_preparer', $direction);
        $id = $this->creerEtSoumettreDocument($traitement, $secretariat);
        $document = DocumentProduitDirection::findOrFail($id);
        $courrier = Courrier::withoutGlobalScopes()->findOrFail($document->courrier_id);
        $courrier->update(['direction_destination_id' => Direction::query()->where('code', 'DG')->value('id')]);

        $validation = $this->actingAs($directeur)->postJson("/api/v1/documents-produits-direction/{$id}/valider")->assertOk();

        $this->assertSame('001/ABC/DMC/ONT/'.now()->year, $validation->json('data.courrier.reference_documentaire'));
        $this->assertSame('ABC', $directeur->fresh()->initiales_reference);
        $this->assertSame('SEC', $secretariat->fresh()->initiales_reference);
    }

    public function test_une_reference_historique_deja_attribuee_nest_pas_remplacee(): void
    {
        $direction = Direction::query()->where('code', 'DMC')->firstOrFail();
        [, $traitement, $secretariat] = $this->traitementTermine('retour_a_preparer', $direction);
        $id = $this->creerEtSoumettreDocument($traitement, $secretariat);
        $document = DocumentProduitDirection::findOrFail($id);
        $document->courrier->update(['reference_documentaire' => '001/ONT/DMC/2026']);

        $this->actingAs($traitement->directeur)->postJson("/api/v1/documents-produits-direction/{$id}/valider")
            ->assertUnprocessable()->assertJsonValidationErrors('reference_documentaire');

        $this->assertSame('001/ONT/DMC/2026', $document->courrier->fresh()->reference_documentaire);
        $this->assertSame('soumis_directeur', $document->fresh()->statut->value);
    }

    public function test_le_directeur_dune_autre_direction_ne_peut_pas_valider(): void
    {
        $direction = Direction::query()->where('code', 'DMC')->firstOrFail();
        [, $traitement, $secretariat] = $this->traitementTermine('retour_a_preparer', $direction);
        $id = $this->creerEtSoumettreDocument($traitement, $secretariat);
        $autreDirection = Direction::query()->where('code', 'DFP')->firstOrFail();
        $autreDirecteur = User::factory()->directeurDirection($autreDirection)->create(['initiales_reference' => 'XYZ']);

        $this->actingAs($autreDirecteur)->postJson("/api/v1/documents-produits-direction/{$id}/valider")->assertForbidden();
        $this->assertNull(Courrier::withoutGlobalScopes()->findOrFail(
            DocumentProduitDirection::findOrFail($id)->courrier_id,
        )->reference_documentaire);
    }

    public function test_une_direction_productrice_incoherente_est_refusee(): void
    {
        $direction = Direction::query()->where('code', 'DMC')->firstOrFail();
        [, $traitement, $secretariat, $directeur] = $this->traitementTermine('retour_a_preparer', $direction);
        $id = $this->creerEtSoumettreDocument($traitement, $secretariat);
        $document = DocumentProduitDirection::findOrFail($id);
        Courrier::withoutGlobalScopes()->whereKey($document->courrier_id)->update([
            'direction_origine_id' => Direction::query()->where('code', 'DFP')->value('id'),
        ]);

        $this->actingAs($directeur)->postJson("/api/v1/documents-produits-direction/{$id}/valider")
            ->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->assertNull(Courrier::withoutGlobalScopes()->findOrFail($document->courrier_id)->reference_documentaire);
    }

    public function test_compteur_interne_est_independant_par_direction_et_par_annee(): void
    {
        $directionDmc = Direction::query()->where('code', 'DMC')->firstOrFail();
        $directionDfp = Direction::query()->where('code', 'DFP')->firstOrFail();
        $this->travelTo(Carbon::parse('2026-06-01 10:00:00'));

        try {
            $premierDmc = $this->produireEtValider($directionDmc);
            $deuxiemeDmc = $this->produireEtValider($directionDmc);
            $premierDfp = $this->produireEtValider($directionDfp);
            $this->travelTo(Carbon::parse('2027-06-01 10:00:00'));
            $premierDmc2027 = $this->produireEtValider($directionDmc);

            $this->assertSame('001/ABC/DMC/ONT/2026', $premierDmc);
            $this->assertSame('002/ABC/DMC/ONT/2026', $deuxiemeDmc);
            $this->assertSame('001/ABC/DFP/ONT/2026', $premierDfp);
            $this->assertSame('001/ABC/DMC/ONT/2027', $premierDmc2027);
        } finally {
            $this->travelBack();
        }
    }

    private function traitementTermine(string $decision, ?Direction $direction = null, ?string $initiales = 'ABC'): array
    {
        $centrale = Direction::factory()->create();
        $direction ??= Direction::factory()->create();
        $dg = User::query()->where('poste', Poste::DG)->orderBy('id')->first();
        $dg ??= $this->agent(Poste::DG, $centrale);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $centrale);
        $reception = $this->agent(Poste::RECEPTION, $centrale);
        $secretariat = User::factory()->secretariatDirection($direction)->create(['initiales_reference' => 'SEC']);
        $directeur = User::query()->where('direction_id', $direction->id)->where('role', UserRole::DIRECTEUR_DIRECTION)->first();
        $directeur ??= User::factory()->directeurDirection($direction)->create(['initiales_reference' => $initiales]);
        $source = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$source->id}/dispatchs", ['destinations' => [['type' => 'direction', 'direction_id' => $direction->id, 'instruction' => 'Traiter']]])->assertOk();
        $dispatch = $source->dispatchs()->firstOrFail();
        $this->receptionnerDispatchSec2($dispatch, $sec2);
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/executer")->assertOk();
        $this->actingAs($secretariat)->postJson("/api/v1/dispatchs/{$dispatch->id}/accuser-reception")->assertOk();
        $traitement = TraitementDirection::query()->where('dispatch_courrier_id', $dispatch->id)->firstOrFail();
        $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/transmettre-directeur")->assertOk();
        $this->actingAs($directeur)->postJson("/api/v1/traitements-direction/{$traitement->id}/prendre-en-charge")->assertOk();
        $this->actingAs($directeur)->postJson("/api/v1/traitements-direction/{$traitement->id}/decision", ['decision' => $decision])->assertOk();

        return [$source, $traitement->fresh(), $secretariat, $directeur, $reception];
    }

    private function creerEtSoumettreDocument(TraitementDirection $traitement, User $secretariat): int
    {
        $id = $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/document-produit", [
            'objet' => 'Document produit pour test', 'contenu' => $this->contenu('Contenu du document.'),
        ])->assertCreated()->json('data.id');
        $this->actingAs($secretariat)->postJson("/api/v1/documents-produits-direction/{$id}/soumettre")->assertOk();

        return $id;
    }

    private function produireEtValider(Direction $direction): string
    {
        [, $traitement, $secretariat, $directeur] = $this->traitementTermine('retour_a_preparer', $direction);
        $id = $this->creerEtSoumettreDocument($traitement, $secretariat);

        return (string) $this->actingAs($directeur)
            ->postJson("/api/v1/documents-produits-direction/{$id}/valider")
            ->assertOk()->json('data.courrier.reference_documentaire');
    }

    private function contenu(string $texte): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $texte]]]]];
    }
}
