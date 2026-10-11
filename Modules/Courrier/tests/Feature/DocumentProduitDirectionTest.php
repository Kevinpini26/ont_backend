<?php

namespace Modules\Courrier\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DocumentProduitOrigine;
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

    public function test_creation_autonome_cree_un_dossier_et_ne_fabrique_aucun_courrier_source(): void
    {
        $direction = Direction::factory()->create(['code' => 'AUT']);
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        $collaborateur = User::factory()->secretariatDirection($direction)->create();
        $autreDirection = Direction::factory()->create();
        $autreSecretariat = User::factory()->secretariatDirection($autreDirection)->create();
        $directeur = User::factory()->directeurDirection($direction)->create(['initiales_reference' => 'ABC']);

        $reponse = $this->actingAs($secretariat)->postJson('/api/v1/documents-produits-direction/autonomes', [
            'objet' => 'Note interne autonome',
            'contenu' => $this->contenu('Texte initial.'),
        ])->assertCreated()
            ->assertJsonPath('data.origine', 'autonome')
            ->assertJsonPath('data.statut', 'brouillon')
            ->assertJsonPath('data.directeur_id', $directeur->id)
            ->assertJsonPath('data.version_contenu', 1);

        $document = DocumentProduitDirection::query()->findOrFail($reponse->json('data.id'));
        $courrier = Courrier::withoutGlobalScopes()->findOrFail($document->courrier_id);
        $this->assertNull($document->traitement_direction_id);
        $this->assertNull($document->document_source_id);
        $this->assertSame($direction->id, $document->direction_id);
        $this->assertSame($secretariat->id, $document->cree_par_id);
        $this->assertNull($courrier->numero_accuse_reception);
        $this->assertNull($courrier->numero_enregistrement);
        $this->assertNull($courrier->reference_documentaire);
        $this->assertSame($secretariat->id, $courrier->created_by);
        $this->assertSame($secretariat->id, $courrier->dossier->created_by);
        $this->assertDatabaseCount('courriers', 1);
        $this->assertDatabaseCount('dossiers', 1);
        $this->assertDatabaseCount('dispatchs_courrier', 0);
        $this->assertDatabaseCount('traitements_direction', 0);
        $this->assertDatabaseCount('document_relations', 0);

        $this->actingAs($collaborateur)->getJson("/api/v1/documents-produits-direction/{$document->id}")
            ->assertOk()->assertJsonPath('data.id', $document->id);
        $this->actingAs($collaborateur)->getJson('/api/v1/documents-produits-direction')
            ->assertOk()->assertJsonPath('data.0.id', $document->id);
        $this->actingAs($autreSecretariat)->patchJson("/api/v1/documents-produits-direction/{$document->id}/brouillon", [
            'version_contenu' => 1,
            'objet' => 'Intrusion horizontale',
            'contenu' => $this->contenu('Ne doit pas être sauvegardé.'),
        ])->assertForbidden();
    }

    public function test_index_filtre_par_origine_avant_pagination_sans_elargir_la_visibilite(): void
    {
        $direction = Direction::factory()->create();
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        $collaborateur = User::factory()->secretariatDirection($direction)->create();
        $directeur = User::factory()->directeurDirection($direction)->create(['initiales_reference' => 'ABC']);
        $autreDirection = Direction::factory()->create();
        $autreSecretariat = User::factory()->secretariatDirection($autreDirection)->create();
        $autreDirecteur = User::factory()->directeurDirection($autreDirection)->create();
        $dg = User::factory()->agentCircuitCourrier(Poste::DG)->create();
        $dga = User::factory()->agentCircuitCourrier(Poste::DGA)->create();
        $sec1 = User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_1)->create();
        $sec2 = User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_2)->create();
        $reception = User::factory()->agentCircuitCourrier(Poste::RECEPTION)->create();
        $admin = User::factory()->administrateur()->create();

        for ($i = 1; $i <= 21; $i++) {
            $this->actingAs($secretariat)->postJson('/api/v1/documents-produits-direction/autonomes', [
                'objet' => "Document autonome {$i}",
                'contenu' => $this->contenu("Contenu {$i}."),
            ])->assertCreated();
        }

        [, $traitement, $secretariatHistorique, $directeurHistorique] = $this->traitementTermine('retour_a_preparer', $direction);
        $historiqueId = $this->actingAs($secretariatHistorique)
            ->postJson("/api/v1/traitements-direction/{$traitement->id}/document-produit", [
                'objet' => 'Document historique',
                'contenu' => $this->contenu('Document issu du traitement.'),
            ])->assertCreated()->json('data.id');

        $sansFiltre = $this->actingAs($secretariat)->getJson('/api/v1/documents-produits-direction')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 22)
            ->assertJsonPath('meta.per_page', 20);
        $pageHistorique = $this->actingAs($secretariat)->getJson('/api/v1/documents-produits-direction?page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 22);
        $this->assertSame(
            1,
            collect($sansFiltre->json('data'))->merge($pageHistorique->json('data'))
                ->where('origine', DocumentProduitOrigine::TRAITEMENT_DIRECTION->value)
                ->count(),
        );
        $this->assertSame(
            $historiqueId,
            collect($sansFiltre->json('data'))->merge($pageHistorique->json('data'))
                ->firstWhere('origine', DocumentProduitOrigine::TRAITEMENT_DIRECTION->value)['id'],
        );

        $pageAutonome1 = $this->actingAs($secretariat)
            ->getJson('/api/v1/documents-produits-direction?origine=autonome&page=1')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 21);
        $this->assertStringContainsString('origine=autonome', $pageAutonome1->json('links.next'));
        $pageAutonome2 = $this->actingAs($secretariat)
            ->getJson('/api/v1/documents-produits-direction?origine=autonome&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 21);
        $autonomes = collect($pageAutonome1->json('data'))->merge($pageAutonome2->json('data'));
        $this->assertCount(21, $autonomes);
        $this->assertSame(
            [DocumentProduitOrigine::AUTONOME->value],
            $autonomes->pluck('origine')->unique()->values()->all(),
        );

        $this->actingAs($secretariat)
            ->getJson('/api/v1/documents-produits-direction?origine=traitement_direction')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $historiqueId)
            ->assertJsonPath('data.0.origine', DocumentProduitOrigine::TRAITEMENT_DIRECTION->value);
        $this->actingAs($secretariat)
            ->getJson('/api/v1/documents-produits-direction?origine=inconnue')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('origine');

        foreach ([$collaborateur, $directeur] as $autoriseMemeDirection) {
            $this->actingAs($autoriseMemeDirection)
                ->getJson('/api/v1/documents-produits-direction?origine=autonome')
                ->assertOk()
                ->assertJsonPath('meta.total', 21)
                ->assertJsonPath('data.0.origine', DocumentProduitOrigine::AUTONOME->value);
        }
        foreach ([$autreSecretariat, $autreDirecteur] as $autreDirectionActeur) {
            $this->actingAs($autreDirectionActeur)
                ->getJson('/api/v1/documents-produits-direction?origine=autonome')
                ->assertOk()
                ->assertJsonCount(0, 'data')
                ->assertJsonPath('meta.total', 0);
        }
        foreach ([$dg, $dga, $sec1, $sec2, $admin] as $interdit) {
            $this->actingAs($interdit)
                ->getJson('/api/v1/documents-produits-direction?origine=autonome')
                ->assertForbidden();
        }
        $this->actingAs($admin)
            ->getJson('/api/v1/documents-produits-direction?origine=inconnue')
            ->assertForbidden();
        $this->actingAs($reception)
            ->getJson('/api/v1/documents-produits-direction?origine=autonome')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($secretariatHistorique)
            ->postJson("/api/v1/documents-produits-direction/{$historiqueId}/soumettre")
            ->assertOk();
        $this->actingAs($directeurHistorique)
            ->postJson("/api/v1/documents-produits-direction/{$historiqueId}/valider")
            ->assertOk();
        $this->actingAs($secretariatHistorique)
            ->postJson("/api/v1/documents-produits-direction/{$historiqueId}/transmettre-reception")
            ->assertOk();
        $this->actingAs($reception)
            ->postJson("/api/v1/documents-produits-direction/{$historiqueId}/recevoir")
            ->assertOk();
        $this->actingAs($reception)
            ->getJson('/api/v1/documents-produits-direction?origine=traitement_direction')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $historiqueId);
        $this->actingAs($reception)
            ->getJson('/api/v1/documents-produits-direction?origine=autonome')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($secretariat)
            ->getJson("/api/v1/documents-produits-direction/{$historiqueId}")
            ->assertOk()
            ->assertJsonPath('data.id', $historiqueId)
            ->assertJsonPath('data.origine', DocumentProduitOrigine::TRAITEMENT_DIRECTION->value);
    }

    public function test_document_autonome_est_versionne_corrige_valide_et_garde_prive_jusqua_transmission_centrale(): void
    {
        $direction = Direction::factory()->create(['code' => 'AUT']);
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        $collaborateur = User::factory()->secretariatDirection($direction)->create();
        $directeur = User::factory()->directeurDirection($direction)->create(['initiales_reference' => 'ABC']);
        $dga = User::factory()->agentCircuitCourrier(Poste::DGA)->create();
        $dg = User::factory()->agentCircuitCourrier(Poste::DG)->create();
        $sec1 = User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_1)->create();
        $sec2 = User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_2)->create();
        $reception = User::factory()->agentCircuitCourrier(Poste::RECEPTION)->create();
        $admin = User::factory()->administrateur()->create();

        $creation = $this->actingAs($secretariat)->postJson('/api/v1/documents-produits-direction/autonomes', [
            'objet' => 'Note confidentielle de direction',
            'contenu' => $this->contenu('Version initiale.'),
        ])->assertCreated();
        $id = $creation->json('data.id');
        $creation->assertJsonPath('data.tracabilite.createur.id', $secretariat->id)
            ->assertJsonPath('data.tracabilite.dernier_editeur.id', $secretariat->id)
            ->assertJsonPath('data.directeur.id', $directeur->id)
            ->assertJsonPath('data.historique.0.action', 'document_direction.autonome_cree')
            ->assertJsonPath('data.historique.0.acteur.id', $secretariat->id)
            ->assertJsonMissingPath('data.historique.0.meta');
        $document = DocumentProduitDirection::query()->findOrFail($id);
        $courrierId = $document->courrier_id;
        $dossierId = Courrier::withoutGlobalScopes()->findOrFail($courrierId)->dossier_id;

        $autreDirection = Direction::factory()->create();
        $autreSecretariat = User::factory()->secretariatDirection($autreDirection)->create();
        $autreDirecteur = User::factory()->directeurDirection($autreDirection)->create();
        foreach ([$dga, $dg, $sec1, $sec2, $reception, $admin] as $acteurCentral) {
            $this->actingAs($acteurCentral)->getJson("/api/v1/documents-produits-direction/{$id}")
                ->assertForbidden()
                ->assertJsonMissingPath('data.tracabilite')
                ->assertJsonMissingPath('data.historique');
            $this->actingAs($acteurCentral)->getJson("/api/v1/courriers/{$courrierId}")->assertNotFound();
            $this->actingAs($acteurCentral)->getJson("/api/v1/dossiers/{$dossierId}")->assertNotFound();
        }
        foreach ([$autreSecretariat, $autreDirecteur] as $acteurAutreDirection) {
            $this->actingAs($acteurAutreDirection)->getJson("/api/v1/documents-produits-direction/{$id}")
                ->assertForbidden()
                ->assertJsonMissingPath('data.tracabilite')
                ->assertJsonMissingPath('data.historique');
        }
        $this->actingAs($collaborateur)->getJson('/api/v1/documents-produits-direction')
            ->assertOk()
            ->assertJsonPath('data.0.tracabilite.createur.id', $secretariat->id)
            ->assertJsonMissingPath('data.0.historique');

        $statistiques = $this->actingAs($dg)->getJson('/api/v1/courriers/statistiques')->assertOk();
        $this->assertSame(
            0,
            collect($statistiques->json('par_statut'))->firstWhere('statut', 'brouillon_direction')['total'],
        );
        $this->actingAs($dg)->getJson('/api/v1/courriers/statistiques-dg')
            ->assertOk()->assertJsonPath('en_attente_decision', 0);

        $this->actingAs($secretariat)->patchJson("/api/v1/documents-produits-direction/{$id}/brouillon", [
            'version_contenu' => 1,
            'objet' => 'Note confidentielle révisée',
            'contenu' => $this->contenu('Version deux.'),
        ])->assertOk()->assertJsonPath('data.version_contenu', 2)
            ->assertJsonPath('data.tracabilite.dernier_editeur.id', $secretariat->id);

        $this->actingAs($collaborateur)->patchJson("/api/v1/documents-produits-direction/{$id}/brouillon", [
            'version_contenu' => 1,
            'objet' => 'Version périmée',
            'contenu' => $this->contenu('Ne doit pas écraser la version courante.'),
        ])->assertStatus(409);

        $this->actingAs($collaborateur)->postJson("/api/v1/documents-produits-direction/{$id}/soumettre", [
            'version_contenu' => 2,
        ])->assertOk()->assertJsonPath('data.statut', 'soumis_directeur')
            ->assertJsonPath('data.version_contenu', 2)
            ->assertJsonPath('data.tracabilite.soumis_par.id', $collaborateur->id);
        $this->actingAs($admin)->postJson("/api/v1/documents-produits-direction/{$id}/valider")->assertForbidden();
        $this->actingAs($autreDirecteur)->postJson("/api/v1/documents-produits-direction/{$id}/valider")->assertForbidden();
        $this->actingAs($directeur)->postJson("/api/v1/documents-produits-direction/{$id}/demander-correction", [
            'motif' => 'Préciser la portée de la note.',
        ])->assertOk()->assertJsonPath('data.statut', 'a_corriger')
            ->assertJsonPath('data.tracabilite.correction_demandee_par.id', $directeur->id)
            ->assertJsonPath('data.motif_correction', 'Préciser la portée de la note.')
            ->assertJsonPath('data.correction_demandee_at', fn ($date) => is_string($date));

        $this->actingAs($collaborateur)->patchJson("/api/v1/documents-produits-direction/{$id}/brouillon", [
            'version_contenu' => 2,
            'objet' => 'Note corrigée',
            'contenu' => $this->contenu('Version corrigée.'),
        ])->assertOk()->assertJsonPath('data.statut', 'brouillon')
            ->assertJsonPath('data.version_contenu', 3);

        $this->actingAs($secretariat)->postJson("/api/v1/documents-produits-direction/{$id}/soumettre", [
            'version_contenu' => 3,
        ])->assertOk();
        $validation = $this->actingAs($directeur)->postJson("/api/v1/documents-produits-direction/{$id}/valider")
            ->assertOk()->assertJsonPath('data.statut', 'valide')
            ->assertJsonPath('data.courrier.reference_documentaire', sprintf('001/ABC/AUT/ONT/%d', now()->year))
            ->assertJsonPath('data.tracabilite.createur.id', $secretariat->id)
            ->assertJsonPath('data.tracabilite.dernier_editeur.id', $collaborateur->id)
            ->assertJsonPath('data.tracabilite.soumis_par.id', $secretariat->id)
            ->assertJsonPath('data.tracabilite.correction_demandee_par.id', $directeur->id)
            ->assertJsonPath('data.tracabilite.valide_par.id', $directeur->id)
            ->assertJsonPath('data.correction_demandee_at', fn ($date) => is_string($date))
            ->assertJsonMissingPath('data.historique.0.meta')
            ->assertJsonMissingPath('data.historique.0.description')
            ->assertDontSee('Version initiale.');
        $this->assertSame([
            'document_direction.autonome_cree',
            'document_direction.brouillon_modifie',
            'document_direction.soumis',
            'document_direction.correction_demandee',
            'document_direction.brouillon_modifie',
            'document_direction.soumis',
            'document_direction.valide',
        ], collect($validation->json('data.historique'))->pluck('action')->all());

        $document->refresh();
        $courrier = Courrier::withoutGlobalScopes()->findOrFail($courrierId);
        $this->assertSame(3, $document->version_contenu);
        $this->assertSame('Note corrigée', $courrier->objet);
        $this->assertSame('001/ABC/AUT/ONT/'.now()->year, $courrier->reference_documentaire);
        $this->assertNull($courrier->numero_enregistrement);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document_direction.correction_demandee',
            'auditable_id' => $document->id,
            'user_id' => $directeur->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document_direction.brouillon_modifie',
            'auditable_id' => $document->id,
            'user_id' => $collaborateur->id,
        ]);
        $this->actingAs($secretariat)->postJson("/api/v1/documents-produits-direction/{$id}/transmettre-reception")->assertForbidden();
        $this->actingAs($reception)->postJson("/api/v1/documents-produits-direction/{$id}/recevoir")->assertForbidden();
        $this->actingAs($dg)->getJson("/api/v1/courriers/{$courrierId}")->assertNotFound();
    }

    public function test_creation_autonome_refuse_un_directeur_ambigu_et_un_role_non_habilite(): void
    {
        $direction = Direction::factory()->create();
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        User::factory()->directeurDirection($direction)->create();
        User::factory()->directeurDirection($direction)->create();

        $this->actingAs($secretariat)->postJson('/api/v1/documents-produits-direction/autonomes', [
            'objet' => 'Ne doit pas être créé',
            'contenu' => $this->contenu('Texte.'),
        ])->assertUnprocessable()->assertJsonValidationErrors('directeur');
        $this->assertDatabaseCount('documents_produits_direction', 0);
        $this->assertDatabaseCount('courriers', 0);
        $this->assertDatabaseCount('dossiers', 0);

        $autreDirection = Direction::factory()->create();
        $agent = User::factory()->agentDfp()->create();
        $this->actingAs($agent)->postJson('/api/v1/documents-produits-direction/autonomes', [
            'objet' => 'Refus',
            'contenu' => $this->contenu('Texte.'),
        ])->assertForbidden();

        $autreSecretariat = User::factory()->secretariatDirection($autreDirection)->create();
        $this->actingAs($autreSecretariat)->getJson('/api/v1/documents-produits-direction')
            ->assertOk()->assertJsonCount(0, 'data');
    }

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

    public function test_migration_preserve_lorigine_historique_et_refuse_une_origine_incoherente(): void
    {
        [, $traitement, $secretariat] = $this->traitementTermine('retour_a_preparer');
        $id = $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/document-produit", [
            'objet' => 'Document historique',
            'contenu' => $this->contenu('Origine historique préservée.'),
        ])->assertCreated()->json('data.id');
        $document = DocumentProduitDirection::query()->findOrFail($id);

        $this->assertSame('traitement_direction', $document->origine->value);
        $this->assertNotNull($document->traitement_direction_id);
        $this->assertNotNull($document->document_source_id);
        $this->expectException(QueryException::class);
        $document->update(['origine' => 'autonome']);
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
        $creation = $this->actingAs($secretariat)->postJson("/api/v1/traitements-direction/{$traitement->id}/document-produit", ['objet' => 'Retour officiel', 'contenu' => $this->contenu('Version 1')])
            ->assertCreated()
            ->assertJsonMissingPath('data.tracabilite');
        $id = $creation->json('data.id');
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
