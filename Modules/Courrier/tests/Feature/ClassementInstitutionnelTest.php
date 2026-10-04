<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\ClassementDocumentStatut;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DecisionDirection;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Enums\ModeReception;
use Modules\Courrier\Enums\ModeSortie;
use Modules\Courrier\Enums\SensCourrier;
use Modules\Courrier\Models\ClassementDocument;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Courrier\Services\ArchivageDossierService;
use Modules\Courrier\Services\ClassementDocumentService;
use Modules\Courrier\Services\DispatchCourrierService;
use Modules\Courrier\Services\TraitementDirectionService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\DelegationPoste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DgAuthorityResolver;

class ClassementInstitutionnelTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_decision_classement_est_executee_uniquement_par_sec2_puis_archivee_et_corrigee_avec_audit(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $admin = User::factory()->administrateur()->create();
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG, 'numero_enregistrement' => '2026-009999', 'reference_documentaire' => '001/ONT/DMC/2026', 'numero_depart' => 'DEP-1']);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [['type' => 'classement', 'instruction' => 'À classer']]])->assertOk();
        $dispatch = $courrier->dispatchs()->firstOrFail();
        $this->actingAs($admin)->postJson("/api/v1/dispatchs/{$dispatch->id}/classer", ['emplacement' => 'Armoire A'])->assertUnprocessable();
        $this->receptionnerDispatchSec2($dispatch, $sec2);
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/classer", ['cote' => 'COTE-1', 'emplacement' => 'Salle 1 / Armoire A', 'observation' => 'Original physique'])->assertOk();
        $classement = ClassementDocument::firstOrFail();
        $this->assertSame($courrier->dossier_id, $classement->dossier_id);
        $this->assertSame($dispatch->id, $classement->dispatch_courrier_id);
        $this->assertNotSame($courrier->numero_enregistrement, $classement->cote);
        $this->assertSame($sec2->id, $classement->classe_par_id);
        $this->assertNotNull($classement->classe_at);
        $this->actingAs($sec2)->postJson("/api/v1/classements-documents/{$classement->id}/corriger", ['emplacement' => 'Salle 1 / Armoire B', 'motif' => 'Correction de localisation'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'classement_document.metadonnees_corrigees', 'auditable_id' => $classement->id]);
        $this->actingAs($sec2)->postJson("/api/v1/classements-documents/{$classement->id}/archiver")->assertOk();
        $classement->refresh();
        $this->assertSame('archive', $classement->statut->value);
        $this->assertSame($sec2->id, $classement->archive_par_id);
        $this->assertNotNull($classement->archive_at);
        $this->assertDatabaseHas('courriers', ['id' => $courrier->id]);
    }

    public function test_decision_archivage_est_refusee_tant_quun_document_nest_pas_archive(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$courrier->dossier_id}/decision-archivage")->assertUnprocessable();
        $this->actingAs($sec2)->postJson("/api/v1/dossiers/{$courrier->dossier_id}/archiver")->assertUnprocessable();
        $this->assertDatabaseHas('dossiers', ['id' => $courrier->dossier_id, 'statut_archivage' => 'actif']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'dossier.archivage_decide', 'auditable_id' => $courrier->dossier_id]);
    }

    public function test_decision_classement_est_refusee_tant_que_la_sortie_explicite_nest_pas_completee(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $service = app(DispatchCourrierService::class);

        $courrierCourriel = Courrier::factory()->create([
            'statut' => CourrierStatut::ENVOYE,
            'mode_sortie' => ModeSortie::COURRIEL,
            'courriel_envoye_at' => null,
            'date_envoi' => null,
            'dossier_id' => null,
        ]);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrierCourriel->id}/dispatchs", [
            'destinations' => [['type' => 'classement', 'instruction' => 'Sortie incomplète']],
        ])->assertUnprocessable()->assertJsonValidationErrors('courrier');
        try {
            $service->decider($courrierCourriel, $dg, [['type' => 'classement', 'instruction' => 'À classer']]);
            $this->fail('Une validation devait bloquer une sortie incomplète.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('courrier', $e->errors());
        }

        $courrierRetrait = Courrier::factory()->create([
            'statut' => CourrierStatut::DISPONIBLE_RETRAIT,
            'mode_sortie' => ModeSortie::RETRAIT_PHYSIQUE,
            'retrait_disponible_at' => now(),
            'remis_le' => null,
            'dossier_id' => null,
        ]);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrierRetrait->id}/dispatchs", [
            'destinations' => [['type' => 'classement', 'instruction' => 'Sortie incomplète']],
        ])->assertForbidden();
        try {
            $service->decider($courrierRetrait, $dg, [['type' => 'classement', 'instruction' => 'À classer']]);
            $this->fail('Une validation devait bloquer une sortie incomplète.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('courrier', $e->errors());
        }

        $courrierMixte = Courrier::factory()->create([
            'statut' => CourrierStatut::ENVOYE,
            'mode_sortie' => ModeSortie::COURRIEL_ET_RETRAIT,
            'courriel_envoye_at' => now(),
            'retrait_disponible_at' => now(),
            'remis_le' => null,
            'dossier_id' => null,
        ]);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrierMixte->id}/dispatchs", [
            'destinations' => [['type' => 'classement', 'instruction' => 'Canal courriel seul']],
        ])->assertUnprocessable()->assertJsonValidationErrors('courrier');
        try {
            $service->decider($courrierMixte, $dg, [['type' => 'classement', 'instruction' => 'À classer']]);
            $this->fail('Une validation devait bloquer une sortie incomplète.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('courrier', $e->errors());
        }
    }

    public function test_decision_classement_et_reception_restent_limitees_aux_autorites_effectives(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $dga = $this->agent(Poste::DGA, $direction);
        $sec1 = $this->agent(Poste::SECRETARIAT_1, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $assistantDg = $this->agent(Poste::ASSISTANT_1, $direction);
        $assistantDga = $this->agent(Poste::ASSISTANT_DGA, $direction);
        $secretariatDirection = User::factory()->secretariatDirection($direction)->create();
        $directeurDirection = User::factory()->directeurDirection($direction)->create();
        $admin = User::factory()->administrateur()->create();
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::ENVOYE,
            'mode_sortie' => ModeSortie::COURRIEL,
            'date_envoi' => today(),
            'courriel_envoye_at' => now(),
            'courriel_envoye_par_id' => $sec2->id,
            'courriel_destinataire' => 'destinataire@example.test',
            'destinataire_externe_email' => 'destinataire@example.test',
        ]);
        $payload = ['destinations' => [['type' => 'classement', 'instruction' => 'Classement autorisé']]];

        $this->assertSame('titulaire', app(DgAuthorityResolver::class)->source($dg));
        $this->assertTrue(Gate::forUser($dg)->allows('deciderDispatch', $courrier));
        $this->actingAs($dg)->getJson("/api/v1/courriers/{$courrier->id}")
            ->assertOk()->assertJsonPath('data.peut_decider_classement', true)
            ->assertJsonPath('data.peut_ouvrir_nouveau_cycle', false);
        $acteursRefuses = [$dga, $sec1, $sec2, $assistantDg, $assistantDga, $secretariatDirection, $directeurDirection, $admin];
        foreach ($acteursRefuses as $acteur) {
            $this->assertFalse(Gate::forUser($acteur)->allows('deciderDispatch', $courrier), $acteur->poste?->value ?? $acteur->role->value);
            $response = $this->actingAs($acteur)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", $payload);
            $this->assertContains($response->status(), [403, 404], $acteur->poste?->value ?? $acteur->role->value);
        }
        $this->actingAs($dg)->postJson('/api/v1/dg-disponibilite', [
            'disponible' => false,
            'dga_interimaire_id' => $dga->id,
            'motif' => 'Test autorité effective',
        ])->assertOk();
        $this->assertSame('interim_dga', app(DgAuthorityResolver::class)->source($dga));
        $this->assertTrue(Gate::forUser($dga)->allows('deciderDispatch', $courrier));
        $this->actingAs($dga)->getJson("/api/v1/courriers/{$courrier->id}")
            ->assertOk()->assertJsonPath('data.peut_decider_classement', true);
        $this->actingAs($dga)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", $payload)
            ->assertOk()->assertJsonPath('data.peut_decider_classement', false);

        $dispatch = $courrier->fresh()->dispatchs()->sole();
        $this->assertTrue(Gate::forUser($sec2)->allows('accuserReception', $dispatch));
        $this->assertTrue(Gate::forUser($sec2)->allows('executer', $dispatch));
        $this->actingAs($sec2)->getJson('/api/v1/dispatchs/centre?type=classement&statut=en_attente')
            ->assertOk()->assertJsonPath('data.0.peut_accuser_reception', true);
        foreach ([$dg, $dga, $sec1, $assistantDg, $assistantDga, $secretariatDirection, $directeurDirection, $admin] as $acteur) {
            $this->assertFalse(Gate::forUser($acteur)->allows('accuserReception', $dispatch), $acteur->poste?->value ?? $acteur->role->value);
            $this->assertFalse(Gate::forUser($acteur)->allows('executer', $dispatch), $acteur->poste?->value ?? $acteur->role->value);
        }
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/accuser-reception")
            ->assertOk()->assertJsonPath('data.peut_accuser_reception', false);
    }

    public function test_courrier_historique_envoye_sans_mode_sortie_conserve_le_cycle_ancien(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::ENVOYE,
            'mode_sortie' => null,
            'dossier_id' => null,
        ]);

        $this->assertTrue($courrier->estSortieCompletee());
        $this->assertFalse($courrier->estCycleClassementApresSortieCompletee());
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", [
            'destinations' => [['type' => 'classement', 'instruction' => 'Circuit historique']],
        ])->assertOk()->assertJsonPath('data.statut', CourrierStatut::EN_DISPATCH->value);
        $courrier->refresh();
        $dispatch = $courrier->dispatchs()->sole();
        $this->assertSame(CourrierStatut::EN_DISPATCH, $courrier->statut);
        $this->assertSame(DispatchStatut::EN_ATTENTE, $dispatch->statut);
        $this->assertDatabaseHas('courrier_transitions', [
            'courrier_id' => $courrier->id,
            'ancien_statut' => CourrierStatut::ENVOYE->value,
            'nouveau_statut' => CourrierStatut::EN_DISPATCH->value,
        ]);

        $this->receptionnerDispatchSec2($dispatch, $sec2);
        $this->assertNotNull($courrier->fresh()->bordereauCourant()?->accuse_reception_at);
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/classer", ['emplacement' => 'Archives historiques'])->assertOk();
        $courrier->refresh();
        $dispatch->refresh();
        $this->assertSame(CourrierStatut::DISPATCH_EXECUTE, $courrier->statut);
        $this->assertSame(DispatchStatut::EXECUTE, $dispatch->statut);
        $this->assertSame(ClassementDocumentStatut::CLASSE, $courrier->classement()->sole()->statut);
    }
    public function test_un_courrier_courriel_envoye_peut_etre_classe_sans_perdre_son_statut_de_livraison(): void
    {
        Storage::fake('local');
        Mail::fake();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $pdf = "%PDF-1.7\nDocument signe Phase 4.1\n%%EOF";
        $pdfPath = 'courriers-signes/phase41-courriel.pdf';
        Storage::disk('local')->put($pdfPath, $pdf);

        $courrier = Courrier::factory()->create([
            'sens' => SensCourrier::SORTANT,
            'statut' => CourrierStatut::SIGNE,
            'destinataire_externe_nom' => 'Destinataire officiel',
            'destinataire_externe_email' => 'destinataire@example.test',
            'numero_depart' => 'DEP-PHASE41-EMAIL',
            'signataire_id' => $dg->id,
            'signe_at' => now()->subMinute(),
            'relecture_validee_at' => now()->subMinutes(2),
            'pdf_chemin' => $pdfPath,
            'pdf_sha256' => hash('sha256', $pdf),
        ]);
        $courrier->transitions()->create([
            'statut' => CourrierStatut::SIGNE,
            'ancien_statut' => CourrierStatut::EN_RELECTURE,
            'nouveau_statut' => CourrierStatut::SIGNE,
            'changed_by_id' => $dg->id,
            'destinataire_poste' => Poste::SECRETARIAT_2->value,
            'accuse_reception_at' => now(),
            'created_at' => now(),
        ]);

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/mode-sortie", [
            'mode_sortie' => ModeSortie::COURRIEL->value,
        ])->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/envoyer-par-courriel")
            ->assertOk()->assertJsonPath('data.statut', CourrierStatut::ENVOYE->value);
        $courrier->refresh();

        $this->assertSame('titulaire', app(DgAuthorityResolver::class)->source($dg));
        $this->assertTrue($courrier->estSortieCompletee());
        $this->assertSame($sec2->id, $courrier->courriel_envoye_par_id);
        $this->assertSame($courrier->destinataire_externe_email, $courrier->courriel_destinataire);
        $this->assertSame(CourrierStatut::ENVOYE, $courrier->statut);
        $champsPreuves = [
            'pdf_chemin', 'pdf_sha256', 'signataire_id', 'signe_at', 'relecture_validee_at',
            'numero_depart', 'mode_sortie', 'date_envoi', 'courriel_envoye_at',
            'courriel_envoye_par_id', 'destinataire_externe_email', 'courriel_destinataire',
        ];
        $preuvesAvant = array_intersect_key($courrier->getRawOriginal(), array_flip($champsPreuves));
        $transitionsAvant = $courrier->transitions()->count();

        $destinationInterdite = [['type' => 'direction', 'direction_id' => $direction->id, 'instruction' => 'Interdit']];
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => $destinationInterdite])
            ->assertUnprocessable()->assertJsonValidationErrors('destinations');
        $this->assertSame(0, $courrier->dispatchs()->count());
        try {
            app(DispatchCourrierService::class)->decider($courrier, $dg, $destinationInterdite);
            $this->fail('Le service devait refuser une destination autre que le classement.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('destinations', $exception->errors());
        }

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", [
            'destinations' => [['type' => 'classement', 'instruction' => 'À classer']],
        ])->assertOk();
        $courrier->refresh();
        $dispatch = $courrier->dispatchs()->sole();
        $this->assertSame(CourrierStatut::ENVOYE, $courrier->statut);
        $this->assertSame(DispatchTypeDestination::CLASSEMENT, $dispatch->type_destination);
        $this->assertSame(DispatchStatut::EN_ATTENTE, $dispatch->statut);
        $this->assertSame($transitionsAvant, $courrier->transitions()->count());

        $destinationDoublon = [['type' => 'classement', 'instruction' => 'Double décision']];
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => $destinationDoublon])
            ->assertUnprocessable()->assertJsonValidationErrors('courrier');
        try {
            app(DispatchCourrierService::class)->decider($courrier, $dg, $destinationDoublon);
            $this->fail('Une seconde décision ne devait pas ouvrir un autre dispatch en attente.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('courrier', $exception->errors());
        }
        $this->assertSame(1, $courrier->dispatchs()->count());

        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/accuser-reception")->assertOk();
        $courrier->refresh();
        $dispatch->refresh();
        $this->assertSame(CourrierStatut::ENVOYE, $courrier->statut);
        $this->assertSame($sec2->id, $dispatch->accuse_reception_par_id);
        $this->assertNotNull($dispatch->accuse_reception_at);
        $this->assertSame($transitionsAvant, $courrier->transitions()->count());

        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/classer", ['emplacement' => 'Archives courriel'])->assertOk();
        $courrier->refresh();
        $dispatch->refresh();
        $classement = ClassementDocument::query()->where('courrier_id', $courrier->id)->sole();
        $this->assertSame(CourrierStatut::ENVOYE, $courrier->statut);
        $this->assertSame(ClassementDocumentStatut::CLASSE, $classement->statut);
        $this->assertSame(DispatchStatut::EXECUTE, $dispatch->statut);
        $this->assertSame($preuvesAvant, array_intersect_key($courrier->getRawOriginal(), array_flip($champsPreuves)));
        $this->assertSame($transitionsAvant, $courrier->transitions()->count());
        $this->assertNotContains($courrier->statut, [CourrierStatut::EN_DISPATCH, CourrierStatut::DISPATCH_EXECUTE]);
    }

    public function test_un_courrier_retrait_remis_peut_etre_classe_sans_perdre_son_statut_de_livraison(): void
    {
        Storage::fake('local');
        Mail::fake();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $contenuPdf = "%PDF-1.7\nCourrier sortant signé pour retrait\n%%EOF";
        $pdfPath = 'courriers-signes/phase41-retrait.pdf';
        Storage::disk('local')->put($pdfPath, $contenuPdf);
        $source = Courrier::factory()->create([
            'sens' => SensCourrier::ENTRANT,
            'mode_reception' => ModeReception::DEPOT_EN_LIGNE,
            'expediteur_externe_nom' => 'Partenaire source',
            'expediteur_externe_email' => 'source@example.test',
        ]);
        $courrierRemis = Courrier::factory()->create([
            'dossier_id' => $source->dossier_id,
            'sens' => SensCourrier::SORTANT,
            'statut' => CourrierStatut::SIGNE,
            'en_reponse_a_courrier_id' => $source->id,
            'destinataire_externe_nom' => 'Destinataire officiel',
            'destinataire_externe_email' => 'retrait@example.test',
            'numero_depart' => 'DEP-PHASE41-RETRAIT',
            'signataire_id' => $dg->id,
            'signe_at' => now()->subMinute(),
            'relecture_validee_at' => now()->subMinutes(2),
            'pdf_chemin' => $pdfPath,
            'pdf_sha256' => hash('sha256', $contenuPdf),
        ]);
        $courrierRemis->transitions()->create([
            'statut' => CourrierStatut::SIGNE,
            'ancien_statut' => CourrierStatut::EN_RELECTURE,
            'nouveau_statut' => CourrierStatut::SIGNE,
            'changed_by_id' => $dg->id,
            'destinataire_poste' => Poste::SECRETARIAT_2->value,
            'accuse_reception_at' => now(),
            'created_at' => now(),
        ]);

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrierRemis->id}/mode-sortie", [
            'mode_sortie' => ModeSortie::RETRAIT_PHYSIQUE->value,
        ])->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrierRemis->id}/rendre-disponible-retrait", [
            'observation' => 'Guichet SEC2',
        ])->assertOk()->assertJsonPath('data.statut', CourrierStatut::DISPONIBLE_RETRAIT->value);
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrierRemis->id}/confirmer-remise-physique", [
            'remis_a' => 'Marie Tshibangu',
            'observation' => 'Pièce contrôlée',
            'decharge_remise' => UploadedFile::fake()->create('decharge.pdf', 10, 'application/pdf'),
        ])->assertOk()->assertJsonPath('data.statut', CourrierStatut::REMIS->value);
        $courrierRemis->refresh();
        $this->assertSame(CourrierStatut::REMIS, $courrierRemis->statut);
        $this->assertTrue($courrierRemis->estSortieCompletee());
        $this->assertNotNull($courrierRemis->retrait_disponible_at);
        $this->assertNotNull($courrierRemis->retrait_disponible_par_id);
        $this->assertNotNull($courrierRemis->remis_le);
        $this->assertSame('Marie Tshibangu', $courrierRemis->remis_a);
        $this->assertSame($sec2->id, $courrierRemis->retrait_effectue_par_id);
        $this->assertSame('retrait_physique', $courrierRemis->mode_remise->value);
        $this->assertNotNull($courrierRemis->decharge_remise_chemin);
        $this->assertSame($contenuPdf, Storage::disk('local')->get($courrierRemis->pdf_chemin));

        $champsPreuves = [
            'pdf_chemin', 'pdf_sha256', 'signataire_id', 'signe_at', 'relecture_validee_at',
            'numero_depart', 'mode_sortie', 'retrait_disponible_at', 'retrait_disponible_par_id',
            'retrait_disponible_observation', 'remis_le', 'remis_a', 'mode_remise',
            'retrait_effectue_par_id', 'retrait_observation', 'decharge_remise_chemin',
        ];
        $preuvesAvant = array_intersect_key($courrierRemis->getRawOriginal(), array_flip($champsPreuves));
        $transitionsAvant = $courrierRemis->transitions()->count();
        $this->assertSame('titulaire', app(DgAuthorityResolver::class)->source($dg));
        $this->assertTrue(Gate::forUser($dg)->allows('deciderDispatch', $courrierRemis));

        $destinationInterdite = [['type' => 'direction', 'direction_id' => $direction->id, 'instruction' => 'Interdit']];
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrierRemis->id}/dispatchs", ['destinations' => $destinationInterdite])
            ->assertUnprocessable()->assertJsonValidationErrors('destinations');
        try {
            app(DispatchCourrierService::class)->decider($courrierRemis, $dg, $destinationInterdite);
            $this->fail('Le service devait refuser une destination autre que le classement.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('destinations', $exception->errors());
        }
        $this->assertSame(0, $courrierRemis->dispatchs()->count());

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrierRemis->id}/dispatchs", [
            'destinations' => [['type' => 'classement', 'instruction' => 'À classer']],
        ])->assertOk();
        $courrierRemis->refresh();
        $dispatchRemis = $courrierRemis->dispatchs()->sole();
        $this->assertSame(CourrierStatut::REMIS, $courrierRemis->statut);
        $this->assertSame(DispatchTypeDestination::CLASSEMENT, $dispatchRemis->type_destination);
        $this->assertSame(DispatchStatut::EN_ATTENTE, $dispatchRemis->statut);
        $this->assertSame($transitionsAvant, $courrierRemis->transitions()->count());

        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatchRemis->id}/accuser-reception")->assertOk();
        $courrierRemis->refresh();
        $dispatchRemis->refresh();
        $this->assertSame(CourrierStatut::REMIS, $courrierRemis->statut);
        $this->assertSame($sec2->id, $dispatchRemis->accuse_reception_par_id);
        $this->assertNotNull($dispatchRemis->accuse_reception_at);
        $this->assertSame($transitionsAvant, $courrierRemis->transitions()->count());

        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatchRemis->id}/classer", ['emplacement' => 'Archives retrait'])->assertOk();
        $courrierRemis->refresh();
        $dispatchRemis->refresh();
        $classement = ClassementDocument::query()->where('courrier_id', $courrierRemis->id)->sole();
        $this->assertSame(CourrierStatut::REMIS, $courrierRemis->statut);
        $this->assertSame(ClassementDocumentStatut::CLASSE, $classement->statut);
        $this->assertSame(DispatchStatut::EXECUTE, $dispatchRemis->statut);
        $this->assertSame($preuvesAvant, array_intersect_key($courrierRemis->getRawOriginal(), array_flip($champsPreuves)));
        $this->assertSame($transitionsAvant, $courrierRemis->transitions()->count());
        $this->assertNotContains($courrierRemis->statut, [CourrierStatut::EN_DISPATCH, CourrierStatut::DISPATCH_EXECUTE]);
    }

    public function test_un_courrier_combine_envoye_par_courriel_puis_remis_ne_peut_etre_classe_quapres_les_deux_canaux(): void
    {
        Storage::fake('local');
        Mail::fake();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        [$courrier, $contenuPdf] = $this->creerCourrierSignePourCanaux($dg, 'cas-a');

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/mode-sortie", [
            'mode_sortie' => ModeSortie::COURRIEL_ET_RETRAIT->value,
        ])->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/envoyer-par-courriel")
            ->assertOk()->assertJsonPath('data.statut', CourrierStatut::ENVOYE->value);
        $courrier->refresh();
        $this->assertSame(CourrierStatut::ENVOYE, $courrier->statut);
        $this->assertNotNull($courrier->courriel_envoye_at);
        $this->assertNull($courrier->remis_le);
        $this->assertFalse($courrier->estSortieCompletee());
        $this->refuserClassementCombine($courrier, $dg, 422);

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/rendre-disponible-retrait")->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/confirmer-remise-physique", [
            'remis_a' => 'Destinataire cas A',
            'decharge_remise' => UploadedFile::fake()->create('decharge-cas-a.pdf', 10, 'application/pdf'),
        ])->assertOk()->assertJsonPath('data.statut', CourrierStatut::REMIS->value);
        $courrier->refresh();
        $this->assertSame(CourrierStatut::REMIS, $courrier->statut);
        $this->assertNotNull($courrier->courriel_envoye_at);
        $this->assertNotNull($courrier->remis_le);
        $this->assertTrue($courrier->estSortieCompletee());

        $champsPreuves = $this->champsPreuvesSortieCombinee();
        $preuvesAvant = array_intersect_key($courrier->getRawOriginal(), array_flip($champsPreuves));
        $transitionsAvant = $courrier->transitions()->count();
        $this->classerSortieCombinee($courrier, $dg, $sec2, $contenuPdf, $preuvesAvant, $champsPreuves, $transitionsAvant, 'Archives cas A');
    }

    public function test_un_courrier_combine_remis_puis_envoye_par_courriel_ne_peut_etre_classe_quapres_les_deux_canaux(): void
    {
        Storage::fake('local');
        Mail::fake();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        [$courrier, $contenuPdf] = $this->creerCourrierSignePourCanaux($dg, 'cas-b');

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/mode-sortie", [
            'mode_sortie' => ModeSortie::COURRIEL_ET_RETRAIT->value,
        ])->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/rendre-disponible-retrait")->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/confirmer-remise-physique", [
            'remis_a' => 'Destinataire cas B',
            'decharge_remise' => UploadedFile::fake()->create('decharge-cas-b.pdf', 10, 'application/pdf'),
        ])->assertOk()->assertJsonPath('data.statut', CourrierStatut::REMIS->value);
        $courrier->refresh();
        $this->assertSame(CourrierStatut::REMIS, $courrier->statut);
        $this->assertNotNull($courrier->remis_le);
        $this->assertNull($courrier->courriel_envoye_at);
        $this->assertFalse($courrier->estSortieCompletee());
        $this->refuserClassementCombine($courrier, $dg, 403);

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/envoyer-par-courriel")
            ->assertOk()->assertJsonPath('data.statut', CourrierStatut::REMIS->value);
        $courrier->refresh();
        $this->assertSame(CourrierStatut::REMIS, $courrier->statut);
        $this->assertNotNull($courrier->remis_le);
        $this->assertNotNull($courrier->courriel_envoye_at);
        $this->assertTrue($courrier->estSortieCompletee());

        $champsPreuves = $this->champsPreuvesSortieCombinee();
        $preuvesAvant = array_intersect_key($courrier->getRawOriginal(), array_flip($champsPreuves));
        $transitionsAvant = $courrier->transitions()->count();
        $this->classerSortieCombinee($courrier, $dg, $sec2, $contenuPdf, $preuvesAvant, $champsPreuves, $transitionsAvant, 'Archives cas B');
    }

    /** @return array{0: Courrier, 1: string} */
    private function creerCourrierSignePourCanaux(User $dg, string $suffixe): array
    {
        $contenuPdf = "%PDF-1.7\nDocument combine {$suffixe}\n%%EOF";
        $pdfPath = "courriers-signes/phase41-combine-{$suffixe}.pdf";
        Storage::disk('local')->put($pdfPath, $contenuPdf);
        $courrier = Courrier::factory()->create([
            'sens' => SensCourrier::SORTANT,
            'statut' => CourrierStatut::SIGNE,
            'destinataire_externe_nom' => 'Destinataire officiel',
            'destinataire_externe_email' => 'combine@example.test',
            'numero_depart' => 'DEP-PHASE41-'.strtoupper($suffixe),
            'signataire_id' => $dg->id,
            'signe_at' => now()->subMinute(),
            'relecture_validee_at' => now()->subMinutes(2),
            'pdf_chemin' => $pdfPath,
            'pdf_sha256' => hash('sha256', $contenuPdf),
        ]);
        $courrier->transitions()->create([
            'statut' => CourrierStatut::SIGNE,
            'ancien_statut' => CourrierStatut::EN_RELECTURE,
            'nouveau_statut' => CourrierStatut::SIGNE,
            'changed_by_id' => $dg->id,
            'destinataire_poste' => Poste::SECRETARIAT_2->value,
            'accuse_reception_at' => now(),
            'created_at' => now(),
        ]);

        return [$courrier, $contenuPdf];
    }

    private function refuserClassementCombine(Courrier $courrier, User $dg, int $httpStatus): void
    {
        $destination = [['type' => 'classement', 'instruction' => 'À classer trop tôt']];
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => $destination])
            ->assertStatus($httpStatus);
        try {
            app(DispatchCourrierService::class)->decider($courrier, $dg, $destination);
            $this->fail('Le service devait refuser le classement avant la fin des deux canaux.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('courrier', $exception->errors());
        }
        $this->assertSame(0, $courrier->dispatchs()->count());
    }

    /** @return string[] */
    private function champsPreuvesSortieCombinee(): array
    {
        return [
            'pdf_chemin', 'pdf_sha256', 'signataire_id', 'signe_at', 'numero_depart', 'mode_sortie',
            'date_envoi', 'courriel_envoye_at', 'courriel_envoye_par_id', 'courriel_destinataire',
            'destinataire_externe_email', 'retrait_disponible_at', 'retrait_disponible_par_id',
            'retrait_disponible_observation', 'remis_le', 'remis_a', 'retrait_effectue_par_id',
            'retrait_observation', 'mode_remise', 'decharge_remise_chemin',
        ];
    }

    /** @param string[] $champsPreuves @param array<string, mixed> $preuvesAvant */
    private function classerSortieCombinee(
        Courrier $courrier,
        User $dg,
        User $sec2,
        string $contenuPdf,
        array $preuvesAvant,
        array $champsPreuves,
        int $transitionsAvant,
        string $emplacement,
    ): void {
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", [
            'destinations' => [['type' => 'classement', 'instruction' => 'Classer après sortie complète']],
        ])->assertOk();
        $courrier->refresh();
        $dispatch = $courrier->dispatchs()->sole();
        $this->assertSame(CourrierStatut::REMIS, $courrier->statut);
        $this->assertSame(DispatchTypeDestination::CLASSEMENT, $dispatch->type_destination);
        $this->assertSame(DispatchStatut::EN_ATTENTE, $dispatch->statut);
        $this->assertSame($transitionsAvant, $courrier->transitions()->count());

        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/accuser-reception")->assertOk();
        $courrier->refresh();
        $dispatch->refresh();
        $this->assertSame(CourrierStatut::REMIS, $courrier->statut);
        $this->assertSame($sec2->id, $dispatch->accuse_reception_par_id);
        $this->assertNotNull($dispatch->accuse_reception_at);
        $this->assertSame($transitionsAvant, $courrier->transitions()->count());
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/accuser-reception")->assertForbidden();
        try {
            app(DispatchCourrierService::class)->accuserReception($dispatch, $sec2);
            $this->fail('Une seconde réception ne devait pas être enregistrée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dispatch', $exception->errors());
        }

        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/classer", ['emplacement' => $emplacement])->assertOk();
        $courrier->refresh();
        $dispatch->refresh();
        $classement = ClassementDocument::query()->where('courrier_id', $courrier->id)->sole();
        $this->assertSame(CourrierStatut::REMIS, $courrier->statut);
        $this->assertSame(DispatchStatut::EXECUTE, $dispatch->statut);
        $this->assertSame(ClassementDocumentStatut::CLASSE, $classement->statut);
        $this->assertSame($preuvesAvant, array_intersect_key($courrier->getRawOriginal(), array_flip($champsPreuves)));
        $this->assertSame($contenuPdf, Storage::disk('local')->get($courrier->pdf_chemin));
        $this->assertSame($transitionsAvant, $courrier->transitions()->count());
        $this->assertNotContains($courrier->statut, [CourrierStatut::EN_DISPATCH, CourrierStatut::DISPATCH_EXECUTE]);
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/classer", ['emplacement' => $emplacement])->assertUnprocessable();
        try {
            app(ClassementDocumentService::class)->classer($dispatch, $sec2, ['emplacement' => $emplacement]);
            $this->fail('Un second classement ne devait pas être créé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dispatch', $exception->errors());
        }
        $this->assertSame(1, ClassementDocument::query()->where('courrier_id', $courrier->id)->count());
    }

    public function test_un_courrier_historique_remis_sans_mode_sortie_ne_peut_pas_ouvrir_le_nouveau_cycle_de_classement(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $courrier = Courrier::factory()->create([
            'statut' => CourrierStatut::REMIS,
            'mode_sortie' => null,
            'dossier_id' => null,
        ]);

        $this->assertTrue($courrier->estSortieCompletee());
        $this->assertFalse($courrier->estCycleClassementApresSortieCompletee());
        $this->assertFalse(Gate::forUser($dg)->allows('deciderDispatch', $courrier));
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", [
            'destinations' => [['type' => 'classement', 'instruction' => 'Historique']],
        ])->assertForbidden();

        try {
            app(DispatchCourrierService::class)->decider($courrier, $dg, [['type' => 'classement', 'instruction' => 'Historique']]);
            $this->fail('Le service ne devait pas ouvrir un nouveau cycle sur un REMIS historique.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('courrier', $exception->errors());
        }
        $this->assertSame(0, $courrier->dispatchs()->count());
    }

    public function test_liste_classements_accepte_la_delegation_sec2_active_et_est_paginee(): void
    {
        $admin = User::factory()->administrateur()->create();
        $delegataire = User::factory()->responsableDirection()->create();
        DelegationPoste::query()->create([
            'poste' => Poste::SECRETARIAT_2, 'delegataire_id' => $delegataire->id,
            'debut' => now()->subDay(), 'fin' => now()->addDay(), 'motif' => 'Intérim', 'cree_par_id' => $admin->id,
        ]);

        $this->actingAs($delegataire)->getJson('/api/v1/classements-documents')
            ->assertOk()->assertJsonStructure(['data', 'current_page', 'per_page']);

        $delegation = DelegationPoste::query()->firstOrFail();
        $delegation->update(['fin' => now()->subDay()]);
        $this->actingAs($delegataire)->getJson('/api/v1/classements-documents')->assertForbidden();
    }

    public function test_decision_archivage_est_immuable_et_double_clic_est_refuse(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $this->archiverDocument($courrier, $dg, $sec2, 'DOUBLE-CLIC');

        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$courrier->dossier_id}/decision-archivage")->assertOk();
        $date = $courrier->dossier->fresh()->archivage_decide_at;
        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$courrier->dossier_id}/decision-archivage")->assertUnprocessable();
        $this->assertTrue($date->equalTo($courrier->dossier->fresh()->archivage_decide_at));
    }

    public function test_decision_archivage_exige_tous_les_documents_archives_avant_execution_sec2(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $a = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $b = Courrier::factory()->create(['dossier_id' => $a->dossier_id, 'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $c = Courrier::factory()->create(['dossier_id' => $a->dossier_id, 'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);

        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$a->dossier_id}/decision-archivage")->assertUnprocessable();
        $this->assertDatabaseHas('dossiers', ['id' => $a->dossier_id, 'statut_archivage' => 'actif']);

        $this->archiverDocument($a, $dg, $sec2, 'A');
        $this->archiverDocument($b, $dg, $sec2, 'B');
        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$a->dossier_id}/decision-archivage")->assertUnprocessable();
        $this->assertDatabaseHas('dossiers', ['id' => $a->dossier_id, 'statut_archivage' => 'actif']);

        $this->archiverDocument($c, $dg, $sec2, 'C');
        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$a->dossier_id}/decision-archivage")
            ->assertOk()->assertJsonPath('data.statut_archivage', 'a_archiver');
        $this->actingAs($sec2)->postJson("/api/v1/dossiers/{$a->dossier_id}/archiver")
            ->assertOk()->assertJsonPath('data.statut_archivage', 'archive');
    }

    public function test_decision_archivage_est_refusee_si_un_dispatch_direction_execute_nest_pas_receptionne(): void
    {
        $centrale = Direction::factory()->create();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $centrale);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $centrale);
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);

        app(DispatchCourrierService::class)->decider($courrier, $dg, [
            ['type' => DispatchTypeDestination::DIRECTION->value, 'direction_id' => $direction->id, 'instruction' => 'Traiter'],
            ['type' => DispatchTypeDestination::CLASSEMENT->value, 'instruction' => 'Classer'],
        ]);
        $dispatchDirection = $courrier->dispatchs()->where('type_destination', DispatchTypeDestination::DIRECTION)->firstOrFail();
        $dispatchClassement = $courrier->dispatchs()->where('type_destination', DispatchTypeDestination::CLASSEMENT)->firstOrFail();
        $this->receptionnerDispatchSec2($dispatchDirection, $sec2);
        app(DispatchCourrierService::class)->executer($dispatchDirection, $sec2);
        $classement = app(ClassementDocumentService::class)->classer($dispatchClassement, $sec2, [
            'cote' => 'COTE-ACCUSÉ-EN-ATTENTE',
            'emplacement' => 'Archives',
        ]);
        app(ClassementDocumentService::class)->archiver($classement, $sec2, null);

        $this->assertSame(DispatchStatut::EXECUTE, $dispatchDirection->fresh()->statut);
        $this->assertNull($dispatchDirection->fresh()->accuse_reception_at);
        $this->assertDatabaseCount('traitements_direction', 0);

        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$courrier->dossier_id}/decision-archivage")
            ->assertUnprocessable()->assertJsonValidationErrors('dossier');
        $this->assertDatabaseHas('dossiers', ['id' => $courrier->dossier_id, 'statut_archivage' => 'actif']);
    }

    public function test_decision_archivage_reste_possible_apres_reception_et_traitement_du_dispatch_direction(): void
    {
        $centrale = Direction::factory()->create();
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $centrale);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $centrale);
        $secretariat = User::factory()->secretariatDirection($direction)->create();
        $directeur = User::factory()->directeurDirection($direction)->create();
        $courrier = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);

        app(DispatchCourrierService::class)->decider($courrier, $dg, [
            ['type' => DispatchTypeDestination::DIRECTION->value, 'direction_id' => $direction->id, 'instruction' => 'Traiter'],
            ['type' => DispatchTypeDestination::CLASSEMENT->value, 'instruction' => 'Classer'],
        ]);
        $dispatchDirection = $courrier->dispatchs()->where('type_destination', DispatchTypeDestination::DIRECTION)->firstOrFail();
        $dispatchClassement = $courrier->dispatchs()->where('type_destination', DispatchTypeDestination::CLASSEMENT)->firstOrFail();
        $this->receptionnerDispatchSec2($dispatchDirection, $sec2);
        app(DispatchCourrierService::class)->executer($dispatchDirection, $sec2);
        app(DispatchCourrierService::class)->accuserReception($dispatchDirection, $secretariat);

        $traitement = TraitementDirection::query()->where('dispatch_courrier_id', $dispatchDirection->id)->firstOrFail();
        $traitements = app(TraitementDirectionService::class);
        $traitements->transmettreDirecteur($traitement, $secretariat, null);
        $traitements->prendreEnCharge($traitement->fresh(), $directeur);
        $traitements->decider($traitement->fresh(), $directeur, DecisionDirection::TRAITEMENT_TERMINE, null);

        $classement = app(ClassementDocumentService::class)->classer($dispatchClassement, $sec2, [
            'cote' => 'COTE-REÇU-ET-TRAITÉ',
            'emplacement' => 'Archives',
        ]);
        app(ClassementDocumentService::class)->archiver($classement, $sec2, null);

        $this->assertNotNull($dispatchDirection->fresh()->accuse_reception_at);
        $this->assertSame('termine_directeur', $traitement->fresh()->statut->value);
        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$courrier->dossier_id}/decision-archivage")
            ->assertOk()->assertJsonPath('data.statut_archivage', 'a_archiver');
        app(ArchivageDossierService::class)->archiver($courrier->dossier, $sec2);
        $this->assertDatabaseHas('dossiers', ['id' => $courrier->dossier_id, 'statut_archivage' => 'archive']);
    }

    public function test_archivage_refuse_un_dossier_quand_un_document_cache_du_meme_dossier_nest_pas_archive(): void
    {
        $directionVisible = Direction::factory()->create();
        $directionCachee = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $directionVisible);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $directionVisible);

        $visible = Courrier::factory()->create([
            'direction_origine_id' => $directionVisible->id,
            'direction_destination_id' => $directionVisible->id,
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
        ]);
        $cache = Courrier::factory()->create([
            'dossier_id' => $visible->dossier_id,
            'direction_origine_id' => $directionCachee->id,
            'direction_destination_id' => $directionCachee->id,
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
        ]);

        $this->archiverDocument($visible, $dg, $sec2, 'VISIBLE');

        $visible->dossier()->update(['statut_archivage' => 'a_archiver', 'archivage_decide_at' => now()]);

        $documentsApi = $this->actingAs($sec2)->getJson("/api/v1/dossiers/{$visible->dossier_id}")
            ->assertOk()
            ->json('data.documents');
        $this->assertCount(1, $documentsApi);
        $this->assertSame($visible->id, $documentsApi[0]['id']);

        try {
            app(ArchivageDossierService::class)->archiver($visible->dossier, $sec2);
            $this->fail('Un document caché non archivé devait bloquer l’archivage du dossier.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dossier', $exception->errors());
        }

        $this->assertDatabaseHas('dossiers', ['id' => $visible->dossier_id, 'statut_archivage' => 'a_archiver']);
        $this->assertDatabaseHas('courriers', ['id' => $cache->id, 'dossier_id' => $visible->dossier_id]);
    }

    public function test_decision_dg_refuse_un_dossier_avec_un_document_non_archive(): void
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $visible = Courrier::factory()->create([
            'direction_origine_id' => $direction->id,
            'direction_destination_id' => $direction->id,
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
        ]);
        Courrier::factory()->create([
            'dossier_id' => $visible->dossier_id,
            'direction_origine_id' => Direction::factory()->create()->id,
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
        ]);
        $this->archiverDocument($visible, $dg, $sec2, 'DECISION');

        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$visible->dossier_id}/decision-archivage")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dossier');
        $this->assertDatabaseHas('dossiers', ['id' => $visible->dossier_id, 'statut_archivage' => 'actif']);
    }

    public function test_archivage_reste_autorise_quand_tous_les_documents_du_dossier_sont_eligibles(): void
    {
        $directionVisible = Direction::factory()->create();
        $directionCachee = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $directionVisible);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $directionVisible);
        $lecteur = User::factory()->directeurDirection($directionVisible)->create();
        $visible = Courrier::factory()->create([
            'direction_origine_id' => $directionVisible->id,
            'direction_destination_id' => $directionVisible->id,
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
        ]);
        $cache = Courrier::factory()->create([
            'dossier_id' => $visible->dossier_id,
            'direction_origine_id' => $directionCachee->id,
            'direction_destination_id' => $directionCachee->id,
            'statut' => CourrierStatut::EN_ATTENTE_AVIS_DG,
        ]);
        $this->archiverDocument($visible, $dg, $sec2, 'POSITIF-A');
        $this->archiverDocument($cache, $dg, $sec2, 'POSITIF-B');

        $documentsApi = $this->actingAs($lecteur)->getJson("/api/v1/dossiers/{$visible->dossier_id}")
            ->assertOk()
            ->json('data.documents');
        $this->assertCount(1, $documentsApi);
        $this->assertSame($visible->id, $documentsApi[0]['id']);

        $this->actingAs($dg)->postJson("/api/v1/dossiers/{$visible->dossier_id}/decision-archivage")
            ->assertOk()->assertJsonPath('data.statut_archivage', 'a_archiver');
        $this->actingAs($sec2)->postJson("/api/v1/dossiers/{$visible->dossier_id}/archiver")
            ->assertOk()->assertJsonPath('data.statut_archivage', 'archive');
    }

    private function archiverDocument(Courrier $courrier, User $dg, User $sec2, string $suffixe): void
    {
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/dispatchs", ['destinations' => [[
            'type' => 'classement', 'instruction' => 'Classer.',
        ]]])->assertOk();
        $dispatch = $courrier->dispatchs()->latest('id')->firstOrFail();
        $this->receptionnerDispatchSec2($dispatch, $sec2);
        $this->actingAs($sec2)->postJson("/api/v1/dispatchs/{$dispatch->id}/classer", [
            'cote' => "COTE-{$suffixe}", 'emplacement' => 'Archives',
        ])->assertOk();
        $classement = ClassementDocument::query()->where('courrier_id', $courrier->id)->firstOrFail();
        $this->actingAs($sec2)->postJson("/api/v1/classements-documents/{$classement->id}/archiver")->assertOk();
    }
}
