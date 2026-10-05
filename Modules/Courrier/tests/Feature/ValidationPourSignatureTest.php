<?php

namespace Modules\Courrier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\MissionDocumentaireStatut;
use Modules\Courrier\Enums\MissionDocumentaireType;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\MissionDocumentaire;
use Modules\Courrier\Services\RegenererPdfASignerService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\DgInterim;
use Modules\Kernel\Models\Direction;
use Smalot\PdfParser\Parser;

class ValidationPourSignatureTest extends CourrierTestCase
{
    use RefreshDatabase;

    public function test_refuse_si_la_relecture_nest_pas_validee_ou_si_acteur_non_dg(): void
    {
        [$d, $dg, $assistant, $dga] = $this->projetPretPourValidation();
        $d->update(['relecture_validee_at' => null]);

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$d->id}/valider-pour-signature")
            ->assertUnprocessable();
        $this->assertNull($d->fresh()->numero_depart);

        $d->update(['relecture_validee_at' => now()]);
        $this->actingAs($assistant)->postJson("/api/v1/courriers/{$d->id}/valider-pour-signature")
            ->assertNotFound();
        $this->actingAs($dga)->postJson("/api/v1/courriers/{$d->id}/valider-pour-signature")
            ->assertNotFound();
    }

    public function test_pdf_a_signer_contient_les_paragraphes_du_corps(): void
    {
        Storage::fake('local');
        [$d, $dg] = $this->projetPretPourValidation();
        $d->forceFill([
            'projet_reponse_contenu' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Premier paragraphe de démonstration ONT.']]],
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Deuxième paragraphe de démonstration ONT.']]],
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Troisième paragraphe de démonstration ONT.']]],
                ],
            ],
        ])->saveQuietly();

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$d->id}/valider-pour-signature")->assertOk();

        $textePdf = (new Parser)->parseContent(Storage::disk('local')->get($d->fresh()->pdf_a_signer_chemin))->getText();
        $this->assertStringContainsString('Premier paragraphe de démonstration ONT.', $textePdf);
        $this->assertStringContainsString('Deuxième paragraphe de démonstration ONT.', $textePdf);
        $this->assertStringContainsString('Troisième paragraphe de démonstration ONT.', $textePdf);
    }

    public function test_dg_valide_et_cree_un_pdf_a_signer_sans_signer_ni_transmettre(): void
    {
        Storage::fake('local');
        [$d, $dg] = $this->projetPretPourValidation();
        $dg->forceFill(['name' => 'Directeur Général'])->save();
        Mail::fake();

        $this->actingAs($dg)->postJson("/api/v1/courriers/{$d->id}/signer")->assertForbidden();

        $reponse = $this->actingAs($dg)->postJson("/api/v1/courriers/{$d->id}/valider-pour-signature")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::EN_ATTENTE_SIGNATURE->value)
            ->assertJsonPath('data.valide_signature_par.id', $dg->id)
            ->assertJsonPath('data.pdf_a_signer_disponible', true);

        $d->refresh();
        $this->assertNotNull($d->numero_depart);
        $this->assertNotNull($d->valide_signature_at);
        $this->assertSame($dg->id, $d->valide_signature_par_id);
        $this->assertSame(CourrierStatut::EN_ATTENTE_SIGNATURE, $d->statut);
        $this->assertNull($d->signe_at);
        $this->assertNull($d->signataire_id);
        $this->assertNull($d->pdf_chemin);
        $this->assertNull($d->pdf_sha256);
        $this->assertStringStartsWith('courriers-a-signer/', $d->pdf_a_signer_chemin);
        Storage::disk('local')->assertExists($d->pdf_a_signer_chemin);
        $this->assertSame(
            hash('sha256', Storage::disk('local')->get($d->pdf_a_signer_chemin)),
            $d->pdf_a_signer_sha256,
        );

        $textePdf = (new Parser)->parseContent(Storage::disk('local')->get($d->pdf_a_signer_chemin))->getText();
        $this->assertStringContainsString($d->numero_depart, $textePdf);
        $this->assertStringContainsString('DOCUMENT À SIGNER', $textePdf);
        $this->assertStringContainsString('NON SIGNÉ', $textePdf);
        $this->assertStringContainsString('Le Directeur Général', $textePdf);
        $this->assertStringContainsString('Directeur Général', $textePdf);
        $this->assertGreaterThanOrEqual(1, substr_count($textePdf, 'Directeur Général'));
        $this->assertStringContainsString('Partenaire', $textePdf);
        $this->assertStringContainsString('Texte final validé', $textePdf);

        $transition = $d->transitions()->reorder()->latest('id')->firstOrFail();
        $this->assertSame(CourrierStatut::EN_ATTENTE_SIGNATURE, $transition->statut);
        $this->assertNull($transition->destinataire_poste);
        $this->assertSame(0, $d->transitions()->where('destinataire_poste', Poste::SECRETARIAT_2->value)->count());
        Mail::assertNothingQueued();

        $this->actingAs($dg)->get("/api/v1/courriers/{$d->id}/pdf-a-signer")->assertOk()->assertHeader('content-type', 'application/pdf');
        $d->refresh();
        $this->assertSame(CourrierStatut::EN_ATTENTE_SIGNATURE, $d->statut);
        $this->assertNull($d->signe_at);
        $this->assertNull($d->signataire_id);
        $this->assertNull($d->pdf_chemin);
        $this->assertNull($d->pdf_sha256);
        $this->assertSame(0, $d->transitions()->where('destinataire_poste', Poste::SECRETARIAT_2->value)->count());
        $this->actingAs($this->agent(Poste::SECRETARIAT_2, Direction::factory()->create()))
            ->postJson("/api/v1/courriers/{$d->id}/envoyer", [
                'destinataire_externe_nom' => 'Partenaire',
                'mode_expedition' => 'courriel',
            ])->assertNotFound();

        $urlPublic = URL::temporarySignedRoute('api.public.reponses.telecharger', now()->addMinute(), ['courrier' => $d->id]);
        $this->get($urlPublic)->assertNotFound();

        $numeroDepart = $d->numero_depart;
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$d->id}/valider-pour-signature")
            ->assertForbidden();
        $this->assertSame($numeroDepart, $d->fresh()->numero_depart);
        $this->assertSame(1, $d->transitions()->where('statut', CourrierStatut::EN_ATTENTE_SIGNATURE)->count());
    }

    public function test_pdf_a_signer_est_refuse_aux_acteurs_sans_visibilite(): void
    {
        Storage::fake('local');
        [$d, $dg, $assistant, $relecteur] = $this->projetPretPourValidation();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$d->id}/valider-pour-signature")->assertOk();

        $this->actingAs($relecteur)->get("/api/v1/courriers/{$d->id}/pdf-a-signer")->assertNotFound();
        $this->actingAs($assistant)->get("/api/v1/courriers/{$d->id}/pdf-a-signer")->assertNotFound();
    }

    public function test_dga_interimaire_valide_selon_le_resolver_dg(): void
    {
        Storage::fake('local');
        [$d, , , $dga, $dgTitulaire] = $this->projetPretPourValidation();
        DgInterim::query()->create([
            'dg_titulaire_id' => $dgTitulaire->id,
            'dga_interimaire_id' => $dga->id,
            'motif' => 'Intérim DG en cours',
            'started_at' => now()->subHour(),
            'started_by_id' => $dgTitulaire->id,
        ]);

        $this->actingAs($dga)->postJson("/api/v1/courriers/{$d->id}/valider-pour-signature")
            ->assertOk()
            ->assertJsonPath('data.statut', CourrierStatut::EN_ATTENTE_SIGNATURE->value)
            ->assertJsonPath('data.valide_signature_par.id', $dga->id);
        $this->assertNull($d->fresh()->signe_at);
        $textePdf = (new Parser)->parseContent(Storage::disk('local')->get($d->fresh()->pdf_a_signer_chemin))->getText();
        $this->assertStringContainsString('intérim de la Direction Générale', preg_replace('/\s+/u', ' ', $textePdf));
    }

    public function test_pdf_a_signer_est_regenere_sans_modifier_les_etats_metier_ni_l_historique(): void
    {
        Storage::fake('local');
        [$courrier, $dg] = $this->courrierEnAttenteSignature();
        $cheminInitial = $courrier->pdf_a_signer_chemin;
        $shaInitial = hash('sha256', "%PDF-1.7\nAncien PDF de certification\n%%EOF");
        Storage::disk('local')->put($cheminInitial, "%PDF-1.7\nAncien PDF de certification\n%%EOF");
        $courrier->forceFill(['pdf_a_signer_sha256' => $shaInitial])->saveQuietly();

        $avant = $courrier->fresh();
        $transitionsAvant = $avant->transitions()->count();
        $dateValidationAvant = $avant->valide_signature_at->toISOString();
        $updatedAtAvant = $avant->updated_at->toISOString();
        $auditsAvant = AuditLog::query()
            ->where('auditable_type', $avant->getMorphClass())
            ->where('auditable_id', $avant->id)
            ->count();

        $regenere = app(RegenererPdfASignerService::class)->regenererPdfASigner($avant);
        $apres = $courrier->fresh();
        $pdf = Storage::disk('local')->get($cheminInitial);
        $textePdf = (new Parser)->parseContent($pdf)->getText();

        $this->assertSame($avant->numero_depart, $apres->numero_depart);
        $this->assertSame(CourrierStatut::EN_ATTENTE_SIGNATURE, $apres->statut);
        $this->assertSame($avant->valide_signature_par_id, $apres->valide_signature_par_id);
        $this->assertSame($dateValidationAvant, $apres->valide_signature_at->toISOString());
        $this->assertSame($updatedAtAvant, $apres->updated_at->toISOString());
        $this->assertSame($transitionsAvant, $apres->transitions()->count());
        $this->assertSame($auditsAvant, AuditLog::query()
            ->where('auditable_type', $apres->getMorphClass())
            ->where('auditable_id', $apres->id)
            ->count());
        $this->assertSame(0, $apres->transitions()->where('destinataire_poste', Poste::SECRETARIAT_2->value)->count());
        $this->assertNull($apres->signe_at);
        $this->assertNull($apres->signataire_id);
        $this->assertNull($apres->pdf_chemin);
        $this->assertNull($apres->pdf_sha256);
        $this->assertNull($apres->scan_signe_televerse_par_id);
        $this->assertNull($apres->scan_signe_televerse_at);
        $this->assertSame($cheminInitial, $apres->pdf_a_signer_chemin);
        $this->assertNotSame($shaInitial, $apres->pdf_a_signer_sha256);
        $this->assertSame(hash('sha256', $pdf), $apres->pdf_a_signer_sha256);
        $this->assertSame($apres->pdf_a_signer_sha256, $regenere->pdf_a_signer_sha256);
        $this->assertGreaterThanOrEqual(1, substr_count($textePdf, 'Directeur Général'));
        $this->assertStringContainsString('Le Directeur Général', $textePdf);
        $this->assertStringContainsString('DOCUMENT À SIGNER', $textePdf);
        $this->assertStringContainsString('NON SIGNÉ', $textePdf);
    }

    public function test_regeneration_refuse_un_statut_incompatible_sans_remplacer_le_pdf(): void
    {
        Storage::fake('local');
        [$courrier] = $this->courrierEnAttenteSignature();
        $chemin = $courrier->pdf_a_signer_chemin;
        $pdfInitial = Storage::disk('local')->get($chemin);
        $courrier->forceFill(['statut' => CourrierStatut::PROJET_A_VALIDER])->saveQuietly();

        try {
            app(RegenererPdfASignerService::class)->regenererPdfASigner($courrier);
            $this->fail('La régénération aurait dû être refusée hors du statut EN_ATTENTE_SIGNATURE.');
        } catch (ValidationException) {
            $this->assertSame($pdfInitial, Storage::disk('local')->get($chemin));
        }
    }

    public function test_regeneration_refuse_si_un_scan_ou_une_finalisation_est_deja_enregistree(): void
    {
        Storage::fake('local');
        [$courrier, $dg] = $this->courrierEnAttenteSignature();
        $chemin = $courrier->pdf_a_signer_chemin;
        $pdfInitial = Storage::disk('local')->get($chemin);
        $courrier->forceFill([
            'signe_at' => now(),
            'signataire_id' => $dg->id,
            'pdf_chemin' => "courriers-signes/{$courrier->id}/final.pdf",
            'pdf_sha256' => str_repeat('a', 64),
            'scan_signe_televerse_par_id' => $dg->id,
            'scan_signe_televerse_at' => now(),
        ])->saveQuietly();

        try {
            app(RegenererPdfASignerService::class)->regenererPdfASigner($courrier);
            $this->fail('La régénération aurait dû être refusée après la finalisation du scan signé.');
        } catch (ValidationException) {
            $this->assertSame($pdfInitial, Storage::disk('local')->get($chemin));
        }
    }

    private function courrierEnAttenteSignature(): array
    {
        [$courrier, $dg] = $this->projetPretPourValidation();
        $dg->forceFill(['name' => 'Directeur Général'])->save();
        $this->actingAs($dg)->postJson("/api/v1/courriers/{$courrier->id}/valider-pour-signature")->assertOk();

        return [$courrier->fresh(), $dg];
    }

    private function projetPretPourValidation(): array
    {
        $direction = Direction::factory()->create();
        $dg = $this->agent(Poste::DG, $direction);
        $dgTitulaire = $dg;
        $assistant = $this->agent(Poste::ASSISTANT_1, $direction);
        $relecteur = $this->agent(Poste::ASSISTANT_2, $direction);
        $dga = $this->agent(Poste::DGA, $direction);
        $source = Courrier::factory()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
        $d = Courrier::factory()->create([
            'dossier_id' => $source->dossier_id,
            'en_reponse_a_courrier_id' => $source->id,
            'created_by' => $assistant->id,
            'sens' => 'sortant',
            'statut' => CourrierStatut::PROJET_A_VALIDER,
            'destinataire_externe_nom' => 'Partenaire',
            'destinataire_externe_email' => 'partenaire@example.test',
            'relecteur_id' => $relecteur->id,
            'relecture_validee_at' => now(),
            'projet_reponse_contenu' => [
                'type' => 'doc',
                'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Texte final validé']]]],
            ],
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
            'projet_courrier_id' => $d->id,
            'statut' => MissionDocumentaireStatut::RETOURNEE,
            'envoyee_at' => now()->subHour(),
            'retournee_at' => now(),
        ]);
        $this->marquerDecharge($d);

        return [$d, $dg, $assistant, $dga, $dgTitulaire, $relecteur];
    }
}
