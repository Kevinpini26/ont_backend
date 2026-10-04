<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Mockery;
use Modules\Courrier\Contracts\CircuitTransitionRules;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\MissionDocumentaireStatut;
use Modules\Courrier\Enums\MissionDocumentaireType;
use Modules\Courrier\Exceptions\TransitionNonAutoriseeException;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Courrier\Policies\CourrierPolicy;
use Modules\Courrier\Services\CourrierCircuitService;
use Modules\Courrier\Services\CourrierVisibilityService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\DgInterim;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;
use Modules\Kernel\Support\DgAuthorityResolver;

class FinalisationScanSigneTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_upload_refuse_avant_attente_signature_et_a_un_dga_non_habilite(): void
    {
        Storage::fake('local');
        [$courrier, $dg, , $dga] = $this->projetPretPourSignature();

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/scan-signe", [
            'scan' => $this->scanPdf(),
        ])->assertForbidden();

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/valider-pour-signature")->assertOk();
        $this->actingAs($dga)->postJson("/api/v1/courriers/{$courrier->id}/scan-signe", [
            'scan' => $this->scanPdf(),
        ])->assertNotFound();
        $this->assertSame(CourrierStatut::EN_ATTENTE_SIGNATURE, $courrier->fresh()->statut);
        $this->assertNull($courrier->fresh()->pdf_chemin);
    }

    public function test_policy_nautorise_que_dg_titulaire_ou_dga_en_interim_pour_le_scan_signe(): void
    {
        Storage::fake('local');
        [$courrier, $dg, $direction, $dga] = $this->projetPretPourSignature();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/valider-pour-signature")->assertOk();
        $courrier->refresh();

        $visibilite = Mockery::mock(CourrierVisibilityService::class);
        $visibilite->shouldReceive('peutVoir')->andReturnTrue();
        $policy = new CourrierPolicy(
            app(CircuitTransitionRules::class),
            app(DelegationResolver::class),
            $visibilite,
            app(DgAuthorityResolver::class),
        );

        $this->assertTrue($policy->televerserScanSigne($dg, $courrier));

        $acteursRefuses = [
            $dga,
            $this->agent(Poste::SECRETARIAT_2, $direction),
            $this->agent(Poste::SECRETARIAT_1, $direction),
            $this->agent(Poste::ASSISTANT_1, $direction),
            $this->agent(Poste::ASSISTANT_DGA, $direction),
            User::factory()->directeurDirection($direction)->create(),
            User::factory()->administrateur()->create(),
        ];

        foreach ($acteursRefuses as $acteur) {
            $this->assertFalse($policy->televerserScanSigne($acteur, $courrier), "{$acteur->role->value}/{$acteur->poste?->value} ne doit pas téléverser le scan signé.");
        }

        DgInterim::query()->create([
            'dg_titulaire_id' => $dg->id,
            'dga_interimaire_id' => $dga->id,
            'motif' => 'Intérim DG pour test d’autorisation',
            'started_at' => now()->subHour(),
            'started_by_id' => $dg->id,
        ]);

        $this->assertTrue($policy->televerserScanSigne($dga, $courrier));
    }

    public function test_sec2_refuse_par_endpoint_et_service_direct_avant_toute_mutation(): void
    {
        Storage::fake('local');
        [$courrier, $dg, $direction] = $this->projetPretPourSignature();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/valider-pour-signature")->assertOk();
        $courrier->refresh();
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $transitionsAvant = $courrier->transitions()->count();
        $pdfASignerAvant = Storage::disk('local')->get($courrier->pdf_a_signer_chemin);
        $shaAvant = $courrier->pdf_a_signer_sha256;

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/scan-signe", [
            'scan' => $this->scanPdf(),
        ])->assertNotFound();

        try {
            app(CourrierCircuitService::class)->finaliserSignaturePhysique($courrier, $sec2, $this->scanPdf());
            $this->fail('Le service doit refuser SEC2 même si le courrier lui est fourni directement.');
        } catch (TransitionNonAutoriseeException) {
            // Le garde service doit s’exécuter avant toute mutation ou création de fichier.
        }

        $courrierFinal = $courrier->fresh();
        $this->assertSame(CourrierStatut::EN_ATTENTE_SIGNATURE, $courrierFinal->statut);
        $this->assertSame($pdfASignerAvant, Storage::disk('local')->get($courrierFinal->pdf_a_signer_chemin));
        $this->assertSame($shaAvant, $courrierFinal->pdf_a_signer_sha256);
        $this->assertNull($courrierFinal->pdf_chemin);
        $this->assertNull($courrierFinal->pdf_sha256);
        $this->assertNull($courrierFinal->signe_at);
        $this->assertNull($courrierFinal->signataire_id);
        $this->assertNull($courrierFinal->scan_signe_televerse_par_id);
        $this->assertNull($courrierFinal->scan_signe_televerse_at);
        $this->assertSame($transitionsAvant, $courrierFinal->transitions()->count());
        $this->assertSame(0, $courrierFinal->transitions()->where('destinataire_poste', Poste::SECRETARIAT_2->value)->count());
        $this->assertSame([], Storage::disk('local')->allFiles("courriers-signes/{$courrier->id}"));
    }

    public function test_upload_refuse_un_fichier_non_pdf_et_un_fichier_trop_volumineux(): void
    {
        Storage::fake('local');
        [$courrier, $dg] = $this->projetPretPourSignature();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/valider-pour-signature")->assertOk();

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/scan-signe", [
            'scan' => UploadedFile::fake()->createWithContent('scan.txt', 'not a PDF'),
        ])->assertUnprocessable();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/scan-signe", [
            'scan' => UploadedFile::fake()->createWithContent('vide.pdf', ''),
        ])->assertUnprocessable();

        config()->set('courrier.signature_physique.taille_max_scan_ko', 1);
        $tropVolumineux = UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.7\n".str_repeat('x', 2048));
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/scan-signe", [
            'scan' => $tropVolumineux,
        ])->assertUnprocessable();

        $this->assertNull($courrier->fresh()->signe_at);
        $this->assertSame(CourrierStatut::EN_ATTENTE_SIGNATURE, $courrier->fresh()->statut);
    }

    public function test_finalisation_conserve_le_pdf_intermediaire_et_transmet_a_sec2_apres_acceptation(): void
    {
        Storage::fake('local');
        Mail::fake();
        [$courrier, $dg, $direction] = $this->projetPretPourSignature();
        Courrier::withoutGlobalScopes()->whereKey($courrier->en_reponse_a_courrier_id)
            ->update(['expediteur_externe_email' => 'partenaire@example.test']);
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/valider-pour-signature")->assertOk();
        $courrier->refresh();
        $cheminIntermediaire = $courrier->pdf_a_signer_chemin;
        $empreinteIntermediaire = $courrier->pdf_a_signer_sha256;
        $contenuIntermediaire = Storage::disk('local')->get($cheminIntermediaire);

        $this->actingAs($sec2)->getJson("/api/v1/courriers/{$courrier->id}")->assertNotFound();
        $this->actingAs($sec2)->getJson('/api/v1/courriers?statut=signe')->assertJsonMissing(['id' => $courrier->id]);

        $contenuScan = "%PDF-1.7\nSignature physique et cachet ONT\n%%EOF";
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/scan-signe", [
            'scan' => UploadedFile::fake()->createWithContent('nom-original-infiable.pdf', $contenuScan),
        ])->assertOk()->assertJsonPath('data.statut', CourrierStatut::SIGNE->value);

        $courrier->refresh();
        $this->assertSame(CourrierStatut::SIGNE, $courrier->statut);
        $this->assertSame($dg->id, $courrier->signataire_id);
        $this->assertSame($dg->id, $courrier->scan_signe_televerse_par_id);
        $this->assertNotNull($courrier->signe_at);
        $this->assertNotNull($courrier->scan_signe_televerse_at);
        $this->assertNotSame($cheminIntermediaire, $courrier->pdf_chemin);
        $this->assertStringStartsWith('courriers-signes/'.$courrier->id.'/', $courrier->pdf_chemin);
        $this->assertSame(hash('sha256', $contenuScan), $courrier->pdf_sha256);
        $this->assertSame($cheminIntermediaire, $courrier->pdf_a_signer_chemin);
        $this->assertSame($empreinteIntermediaire, $courrier->pdf_a_signer_sha256);
        $this->assertSame($contenuIntermediaire, Storage::disk('local')->get($cheminIntermediaire));
        $this->assertSame(1, $courrier->transitions()->where('statut', CourrierStatut::SIGNE)->count());
        $transitionFinale = $courrier->transitions()->where('statut', CourrierStatut::SIGNE)->firstOrFail();
        $this->assertSame(Poste::SECRETARIAT_2->value, $transitionFinale->destinataire_poste);

        $this->actingAs($sec2)->getJson("/api/v1/courriers/{$courrier->id}")->assertOk();
        $this->actingAs($sec2)->getJson('/api/v1/courriers?statut=signe')->assertJsonFragment(['id' => $courrier->id]);
        $this->actingAs($sec2)->get("/api/v1/courriers/{$courrier->id}/pdf")
            ->assertOk()->assertHeader('content-type', 'application/pdf')->assertStreamedContent($contenuScan);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/scan-signe", [
            'scan' => $this->scanPdf(),
        ])->assertForbidden();
        $this->assertSame(1, $courrier->fresh()->transitions()->where('statut', CourrierStatut::SIGNE)->count());

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/mode-sortie", [
            'mode_sortie' => 'courriel',
        ])->assertUnprocessable();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")->assertOk();

        $urlPublic = URL::temporarySignedRoute('api.public.reponses.telecharger', now()->addMinute(), ['courrier' => $courrier->id]);
        $this->get($urlPublic)->assertNotFound();

        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/mode-sortie", [
            'mode_sortie' => 'courriel',
        ])->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/envoyer-par-courriel")
            ->assertOk()->assertJsonPath('data.statut', CourrierStatut::ENVOYE->value);
        $urlPublicEnvoye = URL::temporarySignedRoute('api.public.reponses.telecharger', now()->addMinute(), ['courrier' => $courrier->id]);
        $this->get($urlPublicEnvoye)->assertOk()->assertStreamedContent($contenuScan);
    }

    public function test_scan_refuse_si_le_pdf_a_signer_a_ete_altere_sans_finaliser(): void
    {
        Storage::fake('local');
        [$courrier, $dg] = $this->projetPretPourSignature();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/valider-pour-signature")->assertOk();
        $courrier->refresh();
        Storage::disk('local')->put($courrier->pdf_a_signer_chemin, 'contenu altere');

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/scan-signe", [
            'scan' => $this->scanPdf(),
        ])->assertUnprocessable();

        $courrier->refresh();
        $this->assertSame(CourrierStatut::EN_ATTENTE_SIGNATURE, $courrier->statut);
        $this->assertNull($courrier->pdf_chemin);
        $this->assertNull($courrier->pdf_sha256);
        $this->assertNull($courrier->signataire_id);
        $this->assertNull($courrier->signe_at);
        $this->assertSame(0, $courrier->transitions()->where('statut', CourrierStatut::SIGNE)->count());
    }

    public function test_hash_final_altere_bloque_telechargement_et_envoi_sec2(): void
    {
        Storage::fake('local');
        [$courrier, $dg, $direction] = $this->projetPretPourSignature();
        $sec2 = $this->agent(Poste::SECRETARIAT_2, $direction);
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/valider-pour-signature")->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/scan-signe", [
            'scan' => $this->scanPdf(),
        ])->assertOk();
        $courrier->refresh();
        Storage::disk('local')->put($courrier->pdf_chemin, 'scan final altere');

        $this->actingAs($sec2)->get("/api/v1/courriers/{$courrier->id}/pdf")->assertUnprocessable();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/accuser-reception")->assertOk();
        $this->actingAs($sec2)->postJson("/api/v1/courriers/{$courrier->id}/envoyer", [
            'destinataire_externe_nom' => 'Partenaire officiel',
            'destinataire_externe_email' => 'partenaire@example.test',
            'mode_expedition' => 'courriel',
        ])->assertUnprocessable();

        $this->assertSame(CourrierStatut::SIGNE, $courrier->fresh()->statut);
        $this->assertNull($courrier->fresh()->date_envoi);
    }

    public function test_dga_interimaire_reste_signataire_et_le_scan_est_serve_uniquement_apres_signature(): void
    {
        Storage::fake('local');
        [$courrier, , $direction, $dga, $dgTitulaire] = $this->projetPretPourSignature();
        DgInterim::query()->create([
            'dg_titulaire_id' => $dgTitulaire->id,
            'dga_interimaire_id' => $dga->id,
            'motif' => 'Intérim DG en cours',
            'started_at' => now()->subHour(),
            'started_by_id' => $dgTitulaire->id,
        ]);
        $this->actingAs($dga)->postJson("/api/v1/courriers/{$courrier->id}/valider-pour-signature")->assertOk();
        $this->assertNull($courrier->fresh()->signe_at);
        $this->actingAs($dga)->postJson("/api/v1/courriers/{$courrier->id}/scan-signe", [
            'scan' => $this->scanPdf(),
        ])->assertOk();

        $courrier->refresh();
        $this->assertSame($dga->id, $courrier->signataire_id);
        $this->assertSame($dga->id, $courrier->valide_signature_par_id);
        $this->assertSame(CourrierStatut::SIGNE, $courrier->statut);
        $this->assertNotNull($courrier->signe_at);
    }

    private function projetPretPourSignature(): array
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $assistant = $this->agent(Poste::ASSISTANT_1, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_2, $direction);
        $dga = $this->agent(Poste::DGA, $direction);
        $source = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $courrier = Courrier::factory()->create([
            'dossier_id' => $source->dossier_id,
            'en_reponse_a_courrier_id' => $source->id,
            'created_by' => $assistant->id,
            'sens' => 'sortant',
            'statut' => CourrierStatut::PROJET_A_VALIDER,
            'destinataire_externe_nom' => 'Partenaire officiel',
            'destinataire_externe_email' => 'partenaire@example.test',
            'relecteur_id' => $relecteur->id,
            'relecture_validee_at' => now(),
            'projet_reponse_contenu' => ['type' => 'doc', 'content' => []],
        ]);
        MissionDocumentaire::query()->create([
            'courrier_id' => $source->id,
            'dossier_id' => $source->dossier_id,
            'demandeur_id' => $dg->id,
            'demandeur_poste' => Poste::DG,
            'autorite_poste' => Poste::DG,
            'assistant_id' => $assistant->id,
            'instruction' => 'Préparer la réponse officielle.',
            'type' => MissionDocumentaireType::PREPARATION_REPONSE,
            'projet_courrier_id' => $courrier->id,
            'statut' => MissionDocumentaireStatut::RETOURNEE,
            'envoyee_at' => now()->subHour(),
            'retournee_at' => now(),
        ]);
        $this->marquerDecharge($courrier);

        return [$courrier, $dg, $direction, $dga, $dg];
    }

    private function scanPdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.7\nSignature et cachet ONT\n%%EOF");
    }
}
