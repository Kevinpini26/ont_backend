<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Enums\DocumentRelationType;
use Modules\Courrier\Enums\SensCourrier;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\DocumentRelation;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\SourceDocumentNumerise;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Smalot\PdfParser\Parser;

class VisibiliteCourrierPerimetreTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_matrice_direction_multi_direction_et_enumeration_id(): void
    {
        $centrale = Direction::factory()->create();
        $finances = Direction::factory()->create();
        $etudes = Direction::factory()->create();
        $marketing = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $centrale);
        $courrier = Courrier::factory()->create([
            'objet' => 'CONFIDENTIEL-TEST-ONT-92841',
            'direction_origine_id' => $centrale->id,
            'direction_destination_id' => $centrale->id,
            'statut' => CourrierStatut::DISPATCH_EXECUTE,
        ]);
        $this->dispatchExecute($courrier, $finances);
        $this->dispatchExecute($courrier, $etudes);

        $sec2 = $this->agent(Poste::SECRETARIAT_2, $centrale);
        $assistant = $this->agent(Poste::ASSISTANT_1, $centrale);
        $directeurFinances = User::factory()->directeurDirection($finances)->create();
        $secretariatFinances = User::factory()->secretariatDirection($finances)->create();
        $directeurEtudes = User::factory()->directeurDirection($etudes)->create();
        $directeurMarketing = User::factory()->directeurDirection($marketing)->create();
        $secretariatMarketing = User::factory()->secretariatDirection($marketing)->create();

        foreach ([$dg, $sec2, $directeurFinances, $secretariatFinances, $directeurEtudes] as $autorise) {
            $this->actingAs($autorise)->getJson("/api/v1/courriers/{$courrier->id}")->assertOk();
        }
        foreach ([$directeurMarketing, $secretariatMarketing, $assistant] as $interdit) {
            $this->actingAs($interdit)->getJson("/api/v1/courriers/{$courrier->id}")->assertNotFound();
        }
    }

    public function test_objet_recherche_dossier_et_piece_jointe_ne_fuitent_pas(): void
    {
        Storage::fake('local');
        $finances = Direction::factory()->create();
        $marketing = Direction::factory()->create();
        $secret = 'CONFIDENTIEL-TEST-ONT-92841';
        $chemin = UploadedFile::fake()->create('secret.pdf', 20, 'application/pdf')->store('courriers', 'local');
        $courrier = Courrier::factory()->create([
            'objet' => $secret,
            'reference_documentaire' => 'REF-SECRET-92841',
            'expediteur_externe_nom' => 'EXPEDITEUR-SECRET-92841',
            'piece_jointe_chemin' => $chemin,
            'direction_origine_id' => $finances->id,
            'direction_destination_id' => $finances->id,
        ]);
        $marketingUser = User::factory()->directeurDirection($marketing)->create();
        $financesUser = User::factory()->directeurDirection($finances)->create();

        $this->actingAs($marketingUser)->getJson('/api/v1/courriers')->assertOk()->assertDontSee($secret);
        foreach ([$secret, 'REF-SECRET-92841', 'EXPEDITEUR-SECRET-92841'] as $recherche) {
            $this->actingAs($marketingUser)->getJson('/api/v1/courriers?recherche='.urlencode($recherche))
                ->assertOk()->assertJsonCount(0, 'data')->assertDontSee($secret);
        }
        $this->actingAs($marketingUser)->getJson("/api/v1/courriers/{$courrier->id}")->assertNotFound();
        $this->actingAs($marketingUser)->get("/api/v1/courriers/{$courrier->id}/piece-jointe")->assertNotFound();
        $this->actingAs($marketingUser)->getJson("/api/v1/dossiers/{$courrier->dossier_id}")->assertNotFound()->assertDontSee($secret);

        $this->actingAs($financesUser)->getJson('/api/v1/courriers?recherche='.urlencode($secret))
            ->assertOk()->assertJsonPath('data.0.id', $courrier->id);
    }

    public function test_surfaces_http_ne_revelent_pas_les_documents_autonomes_non_transmis(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create(['code' => 'AUT']);
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        $directeur = User::factory()->directeurDirection($direction)->create(['initiales_reference' => 'ABC']);
        $central = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $central);
        $dga = $this->agent(Poste::DGA, $central);
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $central);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $central);
        $reception = $this->agent(Poste::RECEPTION, $central);
        $assistant = $this->agent(Poste::ASSISTANT_1, $central);
        $assistantDga = $this->agent(Poste::ASSISTANT_DGA, $central);
        $admin = User::factory()->administrateur()->create();
        $dfp = User::factory()->agentDfp()->create();
        $autreDirection = Direction::factory()->create();
        $autreSecretariat = User::factory()->secretariatDirection($autreDirection)->create();
        $autreDirecteur = User::factory()->directeurDirection($autreDirection)->create();
        $courrierHistorique = Courrier::factory()->create([
            'objet' => 'Courrier historique autorisé 4D',
            'direction_origine_id' => $direction->id,
        ]);
        $statistiquesAvant = $this->actingAs($dg)->getJson('/api/v1/courriers/statistiques')->assertOk()->json();
        $statistiquesDgAvant = $this->actingAs($dg)->getJson('/api/v1/courriers/statistiques-dg')->assertOk()->json();
        $statistiquesDirectionAvant = $this->actingAs($dfp)->getJson('/api/v1/courriers/statistiques-direction')->assertOk()->json();

        $token = 'AUTONOME-PRIVE-4D-'.bin2hex(random_bytes(5));
        $id = $this->actingAs($secretariat)->postJson('/api/v1/documents-produits-direction/autonomes', [
            'objet' => $token,
            'contenu' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $token]]]]],
        ])->assertCreated()->json('data.id');
        $document = DocumentProduitDirection::query()->findOrFail($id);
        $autonome = Courrier::withoutGlobalScopes()->findOrFail($document->courrier_id);
        $dossierId = $autonome->dossier_id;

        $primaryPath = UploadedFile::fake()->create('autonome-prive.pdf', 10, 'application/pdf')->store('courriers', 'local');
        $annexePath = UploadedFile::fake()->create('annexe-prive.pdf', 10, 'application/pdf')->store('courriers', 'local');
        $autonome->update(['piece_jointe_chemin' => $primaryPath]);
        $piece = $autonome->piecesJointes()->create([
            'libelle' => 'Annexe privée',
            'chemin' => $annexePath,
            'type_mime' => 'application/pdf',
            'taille_octets' => 1024,
            'ordre' => 1,
            'uploaded_by_id' => $secretariat->id,
        ]);

        DocumentRelation::query()->create([
            'document_source_id' => $courrierHistorique->id,
            'document_cible_id' => $autonome->id,
            'type_relation' => DocumentRelationType::AUTRE,
            'created_by' => $secretariat->id,
            'importe_historique' => false,
        ]);

        $statistiquesApres = $this->actingAs($dg)->getJson('/api/v1/courriers/statistiques')->assertOk()->json();
        $this->assertSame($statistiquesAvant['par_statut'], $statistiquesApres['par_statut']);
        $this->assertSame(
            $statistiquesDgAvant['en_attente_decision'],
            $this->actingAs($dg)->getJson('/api/v1/courriers/statistiques-dg')->assertOk()->json('en_attente_decision'),
        );
        $this->assertSame(
            $statistiquesDirectionAvant['courriers_recus_periode'],
            $this->actingAs($dfp)->getJson('/api/v1/courriers/statistiques-direction')->assertOk()->json('courriers_recus_periode'),
        );

        $acteursCentraux = [$dg, $dga, $sec1, $sec2, $reception, $assistant, $assistantDga, $admin, $dfp];
        foreach ($acteursCentraux as $acteur) {
            $this->actingAs($acteur)->getJson('/api/v1/courriers')->assertOk()->assertDontSee($token);
            $this->actingAs($acteur)->getJson('/api/v1/courriers?recherche='.urlencode($token))
                ->assertOk()->assertJsonCount(0, 'data')->assertDontSee($token);
            $this->actingAs($acteur)->getJson("/api/v1/courriers/{$autonome->id}")->assertNotFound();
            $this->actingAs($acteur)->getJson("/api/v1/dossiers/{$dossierId}")->assertNotFound();
            $this->actingAs($acteur)->getJson("/api/v1/courriers/{$autonome->id}/relations")->assertNotFound();
            $this->actingAs($acteur)->get("/api/v1/courriers/{$autonome->id}/piece-jointe")->assertNotFound();
            $this->actingAs($acteur)->get("/api/v1/courriers/{$autonome->id}/pieces-jointes/{$piece->id}")->assertNotFound();
            $this->actingAs($acteur)->getJson("/api/v1/documents-produits-direction/{$document->id}")->assertForbidden();
        }

        $this->actingAs($dg)->getJson("/api/v1/courriers/{$courrierHistorique->id}")->assertOk();
        $this->actingAs($directeur)->getJson("/api/v1/courriers/{$courrierHistorique->id}")->assertOk();
        $this->actingAs($directeur)->getJson('/api/v1/courriers?recherche='.urlencode('historique autorisé 4D'))
            ->assertOk()->assertJsonPath('data.0.id', $courrierHistorique->id);
        $this->actingAs($dg)->getJson("/api/v1/courriers/{$courrierHistorique->id}/relations")
            ->assertOk()->assertJsonCount(0, 'data')->assertDontSee($token);
        $this->actingAs($directeur)->get("/api/v1/courriers/{$autonome->id}/piece-jointe")->assertOk();
        $this->actingAs($secretariat)->get("/api/v1/courriers/{$autonome->id}/pieces-jointes/{$piece->id}")->assertOk();
        $this->actingAs($autreSecretariat)->getJson("/api/v1/courriers/{$autonome->id}")->assertNotFound();
        $this->actingAs($autreDirecteur)->getJson('/api/v1/courriers?recherche='.urlencode($token))
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($reception)->getJson('/api/v1/documents-produits-direction')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($reception)->postJson("/api/v1/documents-produits-direction/{$document->id}/recevoir")
            ->assertForbidden();
        $this->actingAs($admin)->patchJson("/api/v1/documents-produits-direction/{$document->id}/brouillon", [
            'version_contenu' => 1,
            'objet' => 'Mutation administrative interdite',
        ])->assertForbidden();
        $this->actingAs($admin)->postJson("/api/v1/documents-produits-direction/{$document->id}/valider")
            ->assertForbidden();
        $this->actingAs($admin)->getJson('/api/v1/documents-produits-direction')->assertForbidden();
        $export = $this->actingAs($admin)->get('/api/v1/courriers/export')->assertOk()->streamedContent();
        $this->assertStringNotContainsString($token, $export);
        $this->assertStringContainsString('Courrier historique autorisé 4D', $export);
        foreach ([
            "/api/v1/courriers/{$autonome->id}/pdf",
            "/api/v1/courriers/{$autonome->id}/pdf-a-signer",
            "/api/v1/courriers/{$autonome->id}/imprimer",
            "/api/v1/courriers/{$autonome->id}/feuille-couverture",
        ] as $url) {
            $this->actingAs($dg)->get($url)->assertNotFound();
        }

        $registre = $this->actingAs($admin)->get('/api/v1/courriers/registre?debut='.now()->startOfMonth()->toDateString().'&fin='.now()->endOfMonth()->toDateString())
            ->assertOk();
        $this->assertStringNotContainsString($token, (new Parser)->parseContent($registre->getContent())->getText());
        $rapport = $this->actingAs($dfp)->get('/api/v1/rapports/periodique?type=mois&annee='.now()->year.'&mois='.now()->month)
            ->assertOk();
        $this->assertStringNotContainsString($token, (new Parser)->parseContent($rapport->getContent())->getText());
    }

    public function test_telechargements_de_pieces_et_numerisations_respectent_la_visibilite_du_courrier_parent(): void
    {
        Storage::fake('local');
        $direction = Direction::factory()->create(['code' => 'AUT']);
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        $directeur = User::factory()->directeurDirection($direction)->create(['initiales_reference' => 'ABC']);
        $central = Direction::factory()->create();
        $acteursInterdits = [
            $this->agent(Poste::DG, $central),
            $this->agent(Poste::DGA, $central),
            $this->agent(Poste::ASSISTANT_1, $central),
            $this->agent(Poste::ASSISTANT_DGA, $central),
            $this->agent(Poste::SECRETARIAT_1, $central),
            $this->agent(Poste::SECRETARIAT_2, $central),
            $this->agent(Poste::RECEPTION, $central),
            User::factory()->secretariatDirection(Direction::factory()->create())->create(),
            User::factory()->directeurDirection(Direction::factory()->create())->create(),
            User::factory()->administrateur()->create(),
        ];

        $token = 'FICHIER-PRIVE-4E-'.bin2hex(random_bytes(5));
        $id = $this->actingAs($secretariat)->postJson('/api/v1/documents-produits-direction/autonomes', [
            'objet' => $token,
            'contenu' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $token]]]]],
        ])->assertCreated()->json('data.id');
        $document = DocumentProduitDirection::query()->findOrFail($id);
        $autonome = Courrier::withoutGlobalScopes()->findOrFail($document->courrier_id);

        $annexePath = 'courriers/'.$token.'-annexe.pdf';
        $scanPath = 'numerisations/'.$token.'-scan.pdf';
        $contenuAnnexe = $token.'-CONTENU-ANNEXE';
        $contenuScan = $token.'-CONTENU-SCAN';
        Storage::disk('local')->put($annexePath, $contenuAnnexe);
        Storage::disk('local')->put($scanPath, $contenuScan);
        $annexe = $autonome->piecesJointes()->create([
            'libelle' => 'Annexe privée',
            'chemin' => $annexePath,
            'type_mime' => 'application/pdf',
            'taille_octets' => strlen($contenuAnnexe),
            'ordre' => 1,
            'uploaded_by_id' => $secretariat->id,
        ]);
        $scan = $autonome->numerisations()->create([
            'version' => 1,
            'chemin' => $scanPath,
            'poids_octets' => strlen($contenuScan),
            'sha256' => hash('sha256', $contenuScan),
            'source' => SourceDocumentNumerise::TELEPHONE,
        ]);

        $autreCourrier = Courrier::factory()->create(['direction_origine_id' => $direction->id]);
        $autrePiece = $autreCourrier->piecesJointes()->create([
            'libelle' => 'Pièce autre courrier',
            'chemin' => $annexePath,
        ]);
        $autreScan = $autreCourrier->numerisations()->create([
            'version' => 1,
            'chemin' => $scanPath,
            'poids_octets' => strlen($contenuScan),
            'sha256' => hash('sha256', $contenuScan),
            'source' => SourceDocumentNumerise::TELEPHONE,
        ]);

        $annexeUrl = "/api/v1/courriers/{$autonome->id}/pieces-jointes/{$annexe->id}";
        $scanUrl = "/api/v1/courriers/{$autonome->id}/numerisations/{$scan->id}";
        $pieceCandidatureUrl = "/api/v1/courriers/{$autonome->id}/pieces/cv";
        foreach ($acteursInterdits as $acteur) {
            foreach ([$annexeUrl, $scanUrl, $pieceCandidatureUrl] as $url) {
                $response = $this->actingAs($acteur)->get($url)->assertNotFound();
                $response->assertDontSee($token);
                $this->assertStringNotContainsString($contenuAnnexe, $response->getContent());
                $this->assertStringNotContainsString($contenuScan, $response->getContent());
            }
        }

        foreach ([$secretariat, $directeur] as $autorise) {
            $annexeTelechargee = $this->actingAs($autorise)->get($annexeUrl)->assertOk()->streamedContent();
            $scanTelecharge = $this->actingAs($autorise)->get($scanUrl)->assertOk()->streamedContent();
            $this->assertStringContainsString($contenuAnnexe, $annexeTelechargee);
            $this->assertStringContainsString($contenuScan, $scanTelecharge);
        }

        $this->actingAs($secretariat)
            ->get("/api/v1/courriers/{$autonome->id}/pieces-jointes/{$autrePiece->id}")
            ->assertNotFound();
        $this->actingAs($secretariat)
            ->get("/api/v1/courriers/{$autonome->id}/numerisations/{$autreScan->id}")
            ->assertNotFound();

        $annexeHistorique = $this->actingAs($secretariat)
            ->get("/api/v1/courriers/{$autreCourrier->id}/pieces-jointes/{$autrePiece->id}")
            ->assertOk()->streamedContent();
        $scanHistorique = $this->actingAs($secretariat)
            ->get("/api/v1/courriers/{$autreCourrier->id}/numerisations/{$autreScan->id}")
            ->assertOk()->streamedContent();
        $this->assertStringContainsString($contenuAnnexe, $annexeHistorique);
        $this->assertStringContainsString($contenuScan, $scanHistorique);
    }

    public function test_un_dossier_ne_revele_que_les_documents_visibles(): void
    {
        $finances = Direction::factory()->create();
        $autre = Direction::factory()->create();
        $c = Courrier::factory()->create(['objet' => 'C visible', 'direction_origine_id' => $finances->id]);
        foreach (['A caché', 'B caché', 'D caché'] as $objet) {
            Courrier::factory()->create([
                'dossier_id' => $c->dossier_id,
                'objet' => $objet,
                'direction_origine_id' => $autre->id,
                'direction_destination_id' => $autre->id,
            ]);
        }
        $directeur = User::factory()->directeurDirection($finances)->create();

        $reponse = $this->actingAs($directeur)->getJson("/api/v1/dossiers/{$c->dossier_id}")
            ->assertOk()->assertJsonCount(1, 'data.documents')->assertJsonPath('data.documents.0.id', $c->id);
        $reponse->assertDontSee('A caché')->assertDontSee('B caché')->assertDontSee('D caché');
    }

    public function test_document_direction_devient_visible_apres_dispatch_execute_uniquement(): void
    {
        $dmc = Direction::factory()->create();
        $dep = Direction::factory()->create();
        $b = Courrier::factory()->create(['objet' => 'Analyse DMC', 'direction_origine_id' => $dmc->id]);
        $depUser = User::factory()->directeurDirection($dep)->create();

        $this->actingAs($depUser)->getJson("/api/v1/courriers/{$b->id}")->assertNotFound();
        $dispatch = $this->dispatchExecute($b, $dep, DispatchStatut::EN_ATTENTE);
        $this->actingAs($depUser)->getJson("/api/v1/courriers/{$b->id}")->assertNotFound();
        $dispatch->update(['statut' => DispatchStatut::EXECUTE, 'execute_at' => now()]);
        $this->actingAs($depUser)->getJson("/api/v1/courriers/{$b->id}")->assertOk();
    }

    public function test_courrier_interne_et_sortant_restent_cloisonnes(): void
    {
        $centrale = Direction::factory()->create();
        $rh = Direction::factory()->create();
        $marketing = Direction::factory()->create();
        $interne = Courrier::factory()->create([
            'objet' => 'Interne RH vers DG',
            'direction_origine_id' => $rh->id,
            'direction_destination_id' => $centrale->id,
        ]);
        $sortant = Courrier::factory()->create([
            'objet' => 'Réponse externe officielle',
            'sens' => SensCourrier::SORTANT,
            'direction_origine_id' => $rh->id,
            'direction_destination_id' => null,
            'statut' => CourrierStatut::SIGNE,
        ]);
        $marketingUser = User::factory()->directeurDirection($marketing)->create();
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $centrale);

        $this->actingAs($marketingUser)->getJson("/api/v1/courriers/{$interne->id}")->assertNotFound();
        $this->actingAs($marketingUser)->getJson("/api/v1/courriers/{$sortant->id}")->assertNotFound();
        $sortant->transitions()->create([
            'statut' => CourrierStatut::SIGNE,
            'nouveau_statut' => CourrierStatut::SIGNE,
            'destinataire_poste' => Poste::SECRETARIAT_2,
            'created_at' => now(),
        ]);
        $this->actingAs($sec2)->getJson("/api/v1/courriers/{$sortant->id}")->assertOk();

        $this->dispatchExecute($interne, $marketing);
        $this->actingAs($marketingUser)->getJson("/api/v1/courriers/{$interne->id}")->assertOk();
    }

    private function dispatchExecute(Courrier $courrier, Direction $direction, DispatchStatut $statut = DispatchStatut::EXECUTE): DispatchCourrier
    {
        // Les décisions de fixture appartiennent au titulaire déjà créé :
        // ne pas introduire plusieurs DG dans un même scénario.
        $decisionnaire = User::query()->where('poste', Poste::DG)->first()
            ?? User::factory()->create(['poste' => Poste::DG]);

        return DispatchCourrier::query()->create([
            'courrier_id' => $courrier->id,
            'dossier_id' => $courrier->dossier_id,
            'cycle' => ((int) $courrier->dispatchs()->max('cycle')) + 1,
            'type_destination' => DispatchTypeDestination::DIRECTION,
            'direction_id' => $direction->id,
            'instruction' => 'Traiter ce document.',
            'decisionnaire_id' => $decisionnaire->id,
            'decisionnaire_poste' => Poste::DG,
            'autorite_poste' => Poste::DG,
            'decide_at' => now(),
            'statut' => $statut,
            'execute_at' => $statut === DispatchStatut::EXECUTE ? now() : null,
        ]);
    }
}
